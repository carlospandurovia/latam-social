<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Certificados;
use App\Modules\Core\Services\Correlativos;
use App\Modules\Core\Services\Integraciones;
use App\Modules\Finance\Emision\CredencialesDeEnvio;
use App\Modules\Finance\Emision\EnviadorDeComprobante;
use App\Modules\Finance\Emision\Enviadores;
use App\Modules\Finance\Emision\RespuestaDeEnvio;
use App\Modules\Finance\Services\Comprobantes;
use App\Modules\Finance\Services\Facturas;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El comprobante llega a la administración (iteración 9.9e).
 *
 * ### Lo que estas pruebas SÍ comprueban, y lo que no
 *
 * **Sí:** que los cinco finales se distinguen, que cada uno deja el rastro que
 * le toca, que un rechazo no se reintenta y un error de red sí, que el CDR se
 * guarda como prueba, y que un intento no se puede borrar ni corregir.
 *
 * **No:** la llamada por el cable. Este contenedor no tiene la extensión `soap`
 * y SUNAT no es alcanzable desde aquí, así que la costura está en
 * `WsClientInterface` —que es donde de verdad separa *«qué mando y qué entiendo
 * de lo que contesta»* de *«el cable»*— y esa mitad se estrena en el servidor.
 * Está dicho en el documento de la iteración, no escondido.
 */
final class EnvioAlaAdministracionTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $paisPE;

    private int $clienteId;

    private int $marcaId;

    private int $sociedadId;

    private int $serieId;

    private int $autorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');
        $this->autorId = (int) $this->usuarioCon('admin')->id;

        $this->clienteId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'commercial_name' => 'ACME',
            'client_code' => 'ACME-01', 'country_id' => $this->paisPE, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->marcaId = (int) DB::table('client_brands')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_organization_id' => $this->clienteId,
            'name' => 'Marca ACME', 'slug' => 'marca-acme', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->perfilFiscal();

        $this->sociedadId = $this->entidadLegal([
            'country_id' => $this->paisPE, 'tax_location_code' => '150101',
            'district' => 'MIRAFLORES', 'city' => 'LIMA', 'region' => 'LIMA',
        ]);
        $this->serieId = $this->serieDe($this->sociedadId);

        $this->cargarCertificado();
        $this->conexionDeSunat();
    }

    protected function tearDown(): void
    {
        Enviadores::olvidar();
        parent::tearDown();
    }

    // ------------------------------------------------------- los cinco finales

    /** **La que más importa.** Aceptado deja el CDR guardado y el estado puesto. */
    public function test_aceptado_guarda_el_cdr_y_deja_el_estado(): void
    {
        $factura = $this->facturaConComprobante();
        $this->enviadorQueContesta(new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::ACEPTADO, codigo: '0',
            descripcion: 'La Factura numero F001-1, ha sido aceptada',
            cdr: 'ZIP-DEL-CDR', nombreCdr: 'R-20603203896-01-F001-1.zip',
        ));

        $respuesta = Comprobantes::enviar($factura->uuid, $this->autorId);

        self::assertSame(RespuestaDeEnvio::ACEPTADO, $respuesta->estado);
        self::assertTrue($respuesta->entro());
        self::assertFalse($respuesta->sePuedeReintentar());

        self::assertSame('aceptado', (string) DB::table('invoices')
            ->where('id', $factura->id)->value('external_status'));

        $cdr = Comprobantes::vigente((int) $factura->id, Comprobantes::CDR);
        self::assertNotNull($cdr, 'el CDR queda guardado como prueba');
        self::assertSame('R-20603203896-01-F001-1.zip', (string) $cdr->name);

        $intento = Comprobantes::intentos((int) $factura->id)->first();
        self::assertSame(1, (int) $intento->attempt_number);
        self::assertSame('0', (string) $intento->response_code);
        self::assertSame('SUNAT produccion', (string) $intento->connection_snapshot);
    }

    /**
     * **La distinción que más cara sale confundir.** Un rechazo NO se reintenta;
     * un error de red SÍ.
     *
     * Reenviar un rechazo da el mismo rechazo —el documento es inválido— y no
     * reintentar un error de red deja un comprobante emitido que nunca llegó.
     */
    public function test_rechazado_no_se_reintenta_y_error_de_red_si(): void
    {
        $rechazo = new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::RECHAZADO, codigo: '2335',
            descripcion: 'El dato ingresado en el tipo de documento del receptor no es correcto',
        );
        $red = new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::ERROR_RED, codigo: null,
            descripcion: 'No se pudo hablar con SUNAT: Could not connect to host',
        );

        self::assertFalse($rechazo->sePuedeReintentar(), 'un rechazo no se reintenta');
        self::assertFalse($rechazo->entro(), 'y no entro');
        self::assertTrue($red->sePuedeReintentar(), 'un error de red si');
        self::assertFalse($red->entro(), 'y tampoco se sabe si entro');
    }

    /** Un rechazo se guarda con su código: es lo que hay que poder citar. */
    public function test_un_rechazo_se_guarda_con_su_codigo_y_sin_cdr(): void
    {
        $factura = $this->facturaConComprobante();
        $this->enviadorQueContesta(new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::RECHAZADO, codigo: '2335',
            descripcion: 'El dato ingresado en el tipo de documento del receptor no es correcto',
        ));

        Comprobantes::enviar($factura->uuid, $this->autorId);

        self::assertSame('rechazado', (string) DB::table('invoices')
            ->where('id', $factura->id)->value('external_status'));
        self::assertNull(Comprobantes::vigente((int) $factura->id, Comprobantes::CDR),
            'un rechazo no trae CDR');
        self::assertSame('2335', (string) Comprobantes::intentos((int) $factura->id)
            ->first()->response_code);
    }

    /**
     * Las observaciones se cuentan: el próximo comprobante con el mismo defecto
     * puede no entrar, y enterarse entonces es tarde.
     */
    public function test_las_observaciones_se_guardan_contadas(): void
    {
        $factura = $this->facturaConComprobante();
        $this->enviadorQueContesta(new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::OBSERVADO, codigo: '0',
            descripcion: 'La Factura numero F001-1, ha sido aceptada',
            notas: ['4267 El XML no contiene el codigo de tipo de operacion',
                '4333 El dato del distrito no coincide con el ubigeo'],
            cdr: 'ZIP', nombreCdr: 'R-x.zip',
        ));

        $respuesta = Comprobantes::enviar($factura->uuid, $this->autorId);

        self::assertTrue($respuesta->entro(), 'observado ENTRO: es valido');
        self::assertSame('observado', (string) DB::table('invoices')
            ->where('id', $factura->id)->value('external_status'));
        self::assertSame(2, (int) Comprobantes::intentos((int) $factura->id)->first()->notes_count);
    }

    // ------------------------------------------------------- el rastro

    /** Reintentar **añade una fila**; no pisa la anterior. */
    public function test_reintentar_anade_un_intento_y_no_pisa_el_anterior(): void
    {
        $factura = $this->facturaConComprobante();

        $this->enviadorQueContesta(new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::ERROR_RED, codigo: null, descripcion: 'Se agoto la espera',
        ));
        Comprobantes::enviar($factura->uuid, $this->autorId);

        $this->enviadorQueContesta(new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::ACEPTADO, codigo: '0', descripcion: 'Aceptada',
            cdr: 'ZIP', nombreCdr: 'R-x.zip',
        ));
        Comprobantes::enviar($factura->uuid, $this->autorId);

        $intentos = Comprobantes::intentos((int) $factura->id);
        self::assertCount(2, $intentos, 'los dos siguen ahi');
        self::assertSame(2, (int) $intentos[0]->attempt_number);
        self::assertSame('aceptado', (string) $intentos[0]->outcome);
        self::assertSame('error_red', (string) $intentos[1]->outcome, 'el primero no se pisa');
        self::assertSame('aceptado', (string) DB::table('invoices')
            ->where('id', $factura->id)->value('external_status'));
    }

    /** Y la base no deja borrar ni corregir un intento. */
    public function test_un_intento_no_se_borra_ni_se_corrige(): void
    {
        $factura = $this->facturaConComprobante();
        $this->enviadorQueContesta(new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::RECHAZADO, codigo: '2335', descripcion: 'Rechazada',
        ));
        Comprobantes::enviar($factura->uuid, $this->autorId);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_dsub_no_update` lo rechaza.
        DB::table('document_submissions')->where('invoice_id', $factura->id)
            ->update(['outcome' => 'aceptado']);
    }

    /**
     * El intento apunta al documento que se mandó, no «al vigente».
     *
     * Si se regeneró el XML entre dos intentos, esto es lo único que dice cuál
     * de los dos vio la administración.
     */
    public function test_el_intento_apunta_al_documento_que_se_mando(): void
    {
        $factura = $this->facturaConComprobante();
        $primero = (int) Comprobantes::vigente((int) $factura->id)->id;

        $this->enviadorQueContesta(new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::ACEPTADO, codigo: '0', descripcion: 'Aceptada',
            cdr: 'ZIP', nombreCdr: 'R-x.zip',
        ));
        Comprobantes::enviar($factura->uuid, $this->autorId);

        // Se regenera: el vigente pasa a ser OTRO.
        Comprobantes::generar($factura->uuid, $this->autorId);
        self::assertNotSame($primero, (int) Comprobantes::vigente((int) $factura->id)->id);

        self::assertSame($primero, (int) DB::table('document_submissions')
            ->where('invoice_id', $factura->id)->value('electronic_document_id'));
    }

    // ------------------------------------------------- lo que impide mandarlo

    /** Sin XML armado no hay nada que mandar, y se dice con esas palabras. */
    public function test_sin_comprobante_armado_no_se_manda(): void
    {
        $factura = $this->facturaEmitida();
        $this->enviadorQueContesta(new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::ACEPTADO, codigo: '0', descripcion: 'x',
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('armelo antes de mandarlo');

        Comprobantes::enviar($factura->uuid, $this->autorId);
    }

    /** Sin contraseña del usuario secundario tampoco. */
    public function test_sin_contrasena_del_usuario_secundario_no_se_manda(): void
    {
        $conexion = DB::table('integration_connections')
            ->where('name', 'SUNAT produccion')->first(['id']);
        Integraciones::revocarSecreto((int) $conexion->id, 'password', 'La prueba la retira.');

        $factura = $this->facturaConComprobante();
        $this->enviadorQueContesta(new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::ACEPTADO, codigo: '0', descripcion: 'x',
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contrasena guardada');

        Comprobantes::enviar($factura->uuid, $this->autorId);
    }

    /** Un país sin enviador lo dice, en vez de fingir que lo mandó. */
    public function test_un_pais_sin_enviador_lo_dice(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No hay forma de entregar');

        Enviadores::para('CO');
    }

    // ------------------------------------------------------------- pantalla

    public function test_desde_la_pantalla_se_manda_y_se_ve_el_resultado(): void
    {
        $factura = $this->facturaConComprobante();
        $this->enviadorQueContesta(new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::ACEPTADO, codigo: '0',
            descripcion: 'La Factura numero F001-1, ha sido aceptada',
            cdr: 'ZIP', nombreCdr: 'R-x.zip',
        ));

        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('facturas.enviar', ['uuid' => $factura->uuid]))
            ->assertRedirect()
            ->assertSessionHas('exito');

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('facturas.ver', ['uuid' => $factura->uuid]))
            ->assertOk()
            ->assertSee('Aceptado por la administración', false)
            ->assertSee('Descargar la respuesta de la administración (CDR)', false);
    }

    /** Y con un rechazo, la pantalla **no ofrece reintentar**. */
    public function test_con_un_rechazo_la_pantalla_no_ofrece_reintentar(): void
    {
        $factura = $this->facturaConComprobante();
        $this->enviadorQueContesta(new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::RECHAZADO, codigo: '2335', descripcion: 'No es correcto',
        ));
        Comprobantes::enviar($factura->uuid, $this->autorId);

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('facturas.ver', ['uuid' => $factura->uuid]))
            ->assertOk()
            ->assertSee('Un rechazo no se reintenta', false)
            ->assertDontSee('Reintentar el envío', false);
    }

    public function test_sin_permiso_de_emitir_no_se_manda(): void
    {
        $factura = $this->facturaConComprobante();

        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->post(route('facturas.enviar', ['uuid' => $factura->uuid]))
            ->assertForbidden();
    }

    // ----------------------------------------------------------- ayudantes

    /** Un enviador de mentira que contesta lo que se le diga. */
    private function enviadorQueContesta(RespuestaDeEnvio $respuesta): void
    {
        Enviadores::olvidar();
        Enviadores::registrar('PE', static fn (): EnviadorDeComprobante => new class($respuesta) implements EnviadorDeComprobante
        {
            public function __construct(private RespuestaDeEnvio $respuesta) {}

            public function envia(string $nombre, string $xml, CredencialesDeEnvio $credenciales): RespuestaDeEnvio
            {
                return $this->respuesta;
            }

            public function pais(): string
            {
                return 'PE';
            }

            public function porQueNoPuede(): ?string
            {
                return null;
            }
        });
    }

    private function facturaConComprobante(): object
    {
        $factura = $this->facturaEmitida();
        Comprobantes::generar($factura->uuid, $this->autorId);

        return $factura;
    }

    private function facturaEmitida(): object
    {
        $uuid = Facturas::borrador($this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'completed', 'billing_legal_entity_id' => $this->sociedadId,
            'currency_code' => 'PEN', 'revenue_amount' => 1000.0, 'is_gratis' => 0,
        ]));

        Facturas::emitir($uuid, $this->serieId, $this->autorId);

        return DB::table('invoices')->where('uuid', $uuid)->first();
    }

    private function conexionDeSunat(): void
    {
        $proveedor = (int) DB::table('integration_providers')->where('code', 'sunat')->value('id');

        $uuid = Integraciones::guardarConexion(null, [
            'integration_provider_id' => $proveedor,
            'legal_entity_id' => $this->sociedadId,
            'name' => 'SUNAT produccion',
            'environment' => 'production',
            'username' => 'MODDATOS',
            'base_url' => '',
            'status' => 'active',
        ], $this->autorId);

        Integraciones::guardarSecreto(
            (int) Integraciones::porUuid($uuid)->id, 'password', 'moddatos', $this->autorId,
        );
    }

    private function cargarCertificado(): void
    {
        $clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $ruc = (string) DB::table('legal_entities')->where('id', $this->sociedadId)->value('tax_id_number');
        $csr = openssl_csr_new(['countryName' => 'PE', 'organizationName' => 'Prueba', 'commonName' => $ruc],
            $clave, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $clave, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($x509, $pemCert);
        openssl_pkey_export($clave, $pemClave);

        Certificados::cargar($this->sociedadId, 'production', $pemClave.$pemCert, null, $this->autorId);
    }

    private function perfilFiscal(): int
    {
        return (int) DB::table('client_tax_profiles')->insertGetId([
            'client_organization_id' => $this->clienteId, 'country_id' => $this->paisPE,
            'legal_name' => 'ACME S.A.', 'tax_id_type' => 'RUC',
            'tax_id_number' => (string) random_int(20000000000, 20999999999),
            'address_line1' => 'Av. Demo 100', 'city' => 'Lima', 'payment_term_days' => 30,
            'valid_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function serieDe(int $sociedadId): int
    {
        $tipo = (int) DB::table('document_types')
            ->where('country_id', $this->paisPE)->where('code', 'invoice')->value('id');

        return Correlativos::guardarSerie(null, [
            'legal_entity_id' => $sociedadId, 'document_type_id' => $tipo,
            'series' => 'F001', 'next_number' => 1, 'environment' => 'production',
            'is_active' => true, 'is_default' => true,
        ]);
    }
}
