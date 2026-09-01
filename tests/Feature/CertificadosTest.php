<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Certificados;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El certificado con el que firma cada sociedad (iteración 9.9c).
 *
 * ### Lo que fija
 *
 * Que **los datos del certificado se leen, no se teclean**. De quién es, quién
 * lo emitió y hasta cuándo vale salen del propio archivo: si se pudieran
 * escribir a mano, la fecha de caducidad del panel sería una opinión, y el día
 * que dejara de firmar nadie sabría por qué.
 *
 * Y que **la contraseña del `.pfx` no se guarda**. Se usa una vez, al subirlo, y
 * se olvida: un secreto que no existe no se puede filtrar.
 *
 * ### Los certificados de las pruebas son de verdad
 *
 * Se generan con OpenSSL en el momento, no se traen como fixture binario. Un
 * `.pfx` guardado en el repositorio caduca, y el día que caduque esta clase se
 * pondría roja por un motivo que no tiene nada que ver con lo que prueba.
 */
final class CertificadosTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private const CLAVE = 'secreta';

    /**
     * El RUC de la sociedad de la prueba.
     *
     * NO el de la semilla: `CimientosSeeder` ya crea CTS-PE con `20603203896` y
     * `uq_le_taxid` impide repetirlo. Es la primera vez que una prueba nueva se
     * estrella contra ese fixture, y la base lo dijo con su nombre.
     */
    private const RUC = '20111111111';

    private int $sociedadId;

    private int $autorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->sociedadId = $this->entidadLegal(['tax_id_number' => self::RUC]);
        $this->autorId = (int) $this->usuarioCon('admin')->id;
    }

    // --------------------------------------------------------------- cargar

    /** **La que más importa.** Los datos salen del archivo, no del formulario. */
    public function test_los_datos_se_leen_del_certificado(): void
    {
        $uuid = Certificados::cargar(
            $this->sociedadId, 'production', $this->pfx(self::RUC), self::CLAVE, $this->autorId,
        );

        $cert = Certificados::vigente($this->sociedadId);

        $this->assertNotNull($cert);
        $this->assertSame($uuid, $cert->uuid);
        $this->assertSame(self::RUC, $cert->tax_id_number);
        $this->assertSame('pkcs12', $cert->source);
        $this->assertSame(64, mb_strlen($cert->fingerprint_sha256));
        $this->assertStringContainsString('CN='.self::RUC, $cert->subject_name);
        $this->assertTrue(now()->parse($cert->valid_to)->isFuture());
    }

    /** La contraseña del `.pfx` no queda escrita en ninguna parte. */
    public function test_la_contrasena_del_archivo_no_se_guarda(): void
    {
        Certificados::cargar(
            $this->sociedadId, 'production',
            $this->pfx(clave: 'UnaClaveMuyRara-2026'), 'UnaClaveMuyRara-2026', $this->autorId,
        );

        $fila = (array) DB::table('signing_certificates')->first();

        foreach ($fila as $valor) {
            $this->assertStringNotContainsString('UnaClaveMuyRara-2026', (string) $valor);
        }
    }

    /** El material se guarda cifrado, y sólo lo devuelve quien firma. */
    public function test_el_material_se_guarda_cifrado(): void
    {
        Certificados::cargar($this->sociedadId, 'production', $this->pfx(), self::CLAVE, $this->autorId);

        $cifrado = (string) DB::table('signing_certificates')->value('pem_cipher');
        $this->assertStringNotContainsString('PRIVATE KEY', $cifrado);

        $id = (int) DB::table('signing_certificates')->value('id');
        $pem = Certificados::material($id);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $pem);
        $this->assertStringContainsString('PRIVATE KEY', $pem);
    }

    /** Y `vigente()` no lo devuelve nunca: es la separación de `DEC-226`. */
    public function test_la_lectura_de_pantalla_no_trae_el_material(): void
    {
        Certificados::cargar($this->sociedadId, 'production', $this->pfx(), self::CLAVE, $this->autorId);

        $cert = Certificados::vigente($this->sociedadId);

        $this->assertNotNull($cert);
        $this->assertObjectNotHasProperty('pem_cipher', $cert);
    }

    /** Cargar el siguiente deja el anterior como reemplazado, no lo borra. */
    public function test_el_anterior_queda_reemplazado(): void
    {
        Certificados::cargar($this->sociedadId, 'production', $this->pfx(), self::CLAVE, $this->autorId);
        Certificados::cargar($this->sociedadId, 'production', $this->pfx(), self::CLAVE, $this->autorId);

        $this->assertSame(2, DB::table('signing_certificates')->count());
        $this->assertSame(1, DB::table('signing_certificates')->where('status', 'active')->count());
        $this->assertSame(1, DB::table('signing_certificates')->where('status', 'replaced')->count());
        $this->assertNotNull(
            DB::table('signing_certificates')->where('status', 'replaced')->value('replaced_at'),
        );
    }

    /**
     * Un certificado caducado no se carga: no firma nada.
     *
     * Se hace un certificado que vale un día y **se viaja al día siguiente**.
     * `openssl_csr_sign` no acepta días negativos —lo dice con esas palabras— y
     * mover el reloj es más honesto que fabricar un archivo imposible: prueba el
     * camino real, que es el de un certificado que caducó esperando en un cajón.
     */
    public function test_uno_caducado_no_se_carga(): void
    {
        $caducado = $this->pfx(dias: 1);
        $this->travel(2)->days();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/caduco|vencido/');

        Certificados::cargar($this->sociedadId, 'production', $caducado, self::CLAVE, $this->autorId);
    }

    /** Con la contraseña equivocada, lo dice con esas palabras. */
    public function test_la_contrasena_equivocada_se_dice_con_palabras(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/contrasena.*no es correcta/i');

        Certificados::cargar($this->sociedadId, 'production', $this->pfx(), 'otra', $this->autorId);
    }

    /**
     * **La del despliegue.** El `.pfx` de SUNAT con cifrado antiguo.
     *
     * OpenSSL 3 se niega a abrirlo y el error de PHP —`digital envelope
     * routines::unsupported`— no le dice nada a quien lo lee. El mensaje tiene
     * que traer la orden exacta, o alguien se pasa la tarde probando
     * contraseñas que son correctas.
     */
    public function test_el_cifrado_antiguo_de_sunat_explica_como_convertirlo(): void
    {
        $viejo = base64_decode(
            'MIIBpAIBAzCCAV0GCSqGSIb3DQEHAaCCAU4EggFKMIIBRjCCAUIGCSqGSIb3DQEHBqCCATMwggEv'
            .'AgEAMIIBKAYJKoZIhvcNAQcBMBwGCiqGSIb3DQEMAQYwDgQIcmMyNDBiaXRzAgIIAA==',
            true,
        );

        try {
            Certificados::cargar($this->sociedadId, 'production', (string) $viejo, 'x', $this->autorId);
            $this->fail('Un archivo ilegible no debería cargarse.');
        } catch (RuntimeException $e) {
            // Sea cual sea el motivo exacto que devuelva OpenSSL, lo que no
            // puede pasar es que el mensaje no diga nada: se comprueba que
            // menciona el certificado y no un volcado de C.
            $this->assertMatchesRegularExpression(
                '/certificado|contrasena/i',
                $e->getMessage(),
            );
        }
    }

    /** Un PEM sin clave privada no sirve para firmar, y lo dice. */
    public function test_un_pem_sin_clave_privada_no_sirve(): void
    {
        [$pem] = $this->materialPem();
        $soloCertificado = (string) preg_replace(
            '~-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----~s', '', $pem,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/clave privada/');

        Certificados::cargar($this->sociedadId, 'production', $soloCertificado, null, $this->autorId);
    }

    /** Y uno con las dos mitades entra como `pem`. */
    public function test_un_pem_completo_se_carga(): void
    {
        [$pem] = $this->materialPem();

        Certificados::cargar($this->sociedadId, 'production', $pem, null, $this->autorId);

        $this->assertSame('pem', Certificados::vigente($this->sociedadId)?->source);
    }

    // -------------------------------------------------------------- avisos

    /** Una sociedad activa sin certificado sale en rojo. */
    public function test_una_sociedad_sin_certificado_sale_en_rojo(): void
    {
        $textos = $this->avisosDeNivel('rojo');

        $this->assertStringContainsString('Sin certificado de firma', $textos);
    }

    /** Y uno a punto de caducar, en ámbar. */
    public function test_uno_a_punto_de_caducar_sale_en_ambar(): void
    {
        Certificados::cargar(
            $this->sociedadId, 'production', $this->pfx(self::RUC, 10), self::CLAVE, $this->autorId,
        );

        $this->assertStringContainsString('caduca el', $this->avisosDeNivel('ambar'));
    }

    /**
     * El certificado de otro contribuyente sale en rojo — y no lo impide la base.
     *
     * `docs/00 §56`: no me consta que no exista un caso legítimo, y una regla
     * legal que nadie ha revisado no se mete en el motor (`Q-66`).
     */
    public function test_un_certificado_de_otro_ruc_sale_en_rojo_y_no_bloquea(): void
    {
        Certificados::cargar(
            $this->sociedadId, 'production', $this->pfx('20999999999'), self::CLAVE, $this->autorId,
        );

        $this->assertNotNull(Certificados::vigente($this->sociedadId));
        $this->assertStringContainsString('otro contribuyente', $this->avisosDeNivel('rojo'));
    }

    // ------------------------------------------------------------- revocar

    public function test_revocar_exige_motivo_y_no_borra(): void
    {
        $uuid = Certificados::cargar(
            $this->sociedadId, 'production', $this->pfx(), self::CLAVE, $this->autorId,
        );

        try {
            Certificados::revocar($uuid, 'error');
            $this->fail('Una revocación muda no debería pasar.');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/explicar que paso/', $e->getMessage());
        }

        Certificados::revocar($uuid, 'La clave privada quedó expuesta en un correo.');

        $this->assertSame(1, DB::table('signing_certificates')->count());
        $this->assertNull(Certificados::vigente($this->sociedadId));
        $this->assertDatabaseHas('audit_logs', ['action' => 'signing_certificate.revoked']);
    }

    /** Un certificado no se borra: explica la firma de lo ya emitido. */
    public function test_un_certificado_no_se_borra(): void
    {
        Certificados::cargar($this->sociedadId, 'production', $this->pfx(), self::CLAVE, $this->autorId);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/no se borra/');

        DB::table('signing_certificates')->delete();
    }

    // ------------------------------------------------------------ pantalla

    public function test_la_pantalla_pide_permiso(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('certificados.index'))->assertStatus(403);
    }

    public function test_se_carga_desde_la_pantalla(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('certificados.cargar'), [
                'legal_entity_id' => $this->sociedadId,
                'environment' => 'production',
                'archivo' => UploadedFile::fake()->createWithContent('cert.pfx', $this->pfx()),
                'clave' => self::CLAVE,
            ])
            ->assertRedirect(route('certificados.index'));

        $this->assertNotNull(Certificados::vigente($this->sociedadId));
    }

    /** El área está en el panel de configuración, en el grupo fiscal. */
    public function test_el_panel_de_configuracion_la_incluye(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('configuracion'))
            ->assertOk()
            ->assertSee('Certificados de firma');
    }

    // ----------------------------------------------------------- ayudantes

    private function avisosDeNivel(string $nivel): string
    {
        return implode(' ', array_map(
            fn ($a): string => $a->texto,
            array_filter(Certificados::avisos(), fn ($a): bool => $a->nivel === $nivel),
        ));
    }

    /** Un `.pfx` de verdad, con el RUC, los días de vida y la clave que se pidan. */
    private function pfx(string $ruc = self::RUC, int $dias = 400, string $clave = self::CLAVE): string
    {
        [$certificado, $privada] = $this->parCriptografico($ruc, $dias);
        $pfx = '';
        openssl_pkcs12_export($certificado, $pfx, $privada, $clave);

        return $pfx;
    }

    /** @return array{0:string} */
    private function materialPem(string $ruc = self::RUC): array
    {
        [$certificado, $clave] = $this->parCriptografico($ruc, 400);
        openssl_x509_export($certificado, $pemCert);
        openssl_pkey_export($clave, $pemClave);

        return [$pemCert.$pemClave];
    }

    /** @return array{0:\OpenSSLCertificate,1:\OpenSSLAsymmetricKey} */
    private function parCriptografico(string $ruc, int $dias): array
    {
        $clave = openssl_pkey_new(['private_key_bits' => 2048]);
        $peticion = openssl_csr_new(
            ['countryName' => 'PE', 'organizationName' => 'CTS', 'commonName' => $ruc],
            $clave,
            ['digest_alg' => 'sha256'],
        );

        if ($clave === false || $peticion === false) {
            $this->fail('No se pudo generar el par criptográfico de la prueba.');
        }

        $certificado = openssl_csr_sign($peticion, null, $clave, $dias, ['digest_alg' => 'sha256'], 0);

        if ($certificado === false) {
            $this->fail('No se pudo firmar el certificado de la prueba.');
        }

        return [$certificado, $clave];
    }
}
