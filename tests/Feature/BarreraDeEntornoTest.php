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
use App\Shared\Config\Aviso;
use App\Shared\Config\Instalacion;
use App\Shared\Integracion\EntornoAjeno;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Desde aquí no se manda de verdad (iteración 9.22a, `DEC-029`).
 *
 * ### El fallo que estas pruebas impiden
 *
 * Se restaura una copia de la base de producción en un servidor de pruebas
 * —cosa que se hace todas las semanas—, alguien abre una factura que ya existía
 * y pulsa «Mandar a la administración». SUNAT recibe un comprobante fiscal **de
 * verdad**, con su serie y su correlativo. No se deshace: se anula con una
 * comunicación de baja, y el correlativo se quema.
 *
 * Hasta `9.9e` esto era imposible porque el sistema no sabía mandar. Desde
 * `9.9e`, sí.
 *
 * ### La prueba que impide que las demás pasen por el motivo equivocado
 *
 * `test_la_misma_factura_si_se_manda_desde_produccion` está aquí a propósito.
 * En este proyecto ya han aparecido **tres** aserciones que pasaban por una
 * razón distinta de la que afirmaban; una prueba que dice «no se mandó» y que
 * en realidad no se mandaba porque faltaba el certificado no vale nada. Ésa
 * demuestra que lo único que cambia entre mandar y no mandar es la máquina.
 */
final class BarreraDeEntornoTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $paisPE;

    private int $clienteId;

    private int $marcaId;

    private int $sociedadId;

    private int $autorId;

    private bool $enviadorLlamado = false;

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

        $this->cargarCertificado('production');
        $this->cargarCertificado('sandbox');
    }

    protected function tearDown(): void
    {
        Enviadores::olvidar();
        parent::tearDown();
    }

    // --------------------------------------------------------------- la barrera

    /**
     * **La que da nombre a la iteración.** Desde una instalación que no es
     * producción, una factura con serie de producción no sale.
     */
    public function test_una_instalacion_que_no_es_produccion_no_manda_a_produccion(): void
    {
        config(['instalacion.entorno' => 'staging']);
        $factura = $this->facturaConComprobante('production');
        $this->enviadorQueContesta($this->aceptado());

        try {
            Comprobantes::enviar($factura->uuid, $this->autorId);
            self::fail('la barrera dejo pasar un envio a produccion desde preproduccion');
        } catch (EntornoAjeno $e) {
            self::assertStringContainsString('Preproducción', $e->getMessage(),
                'el motivo dice QUE maquina es esta, no solo que no se puede');
        }

        self::assertFalse($this->enviadorLlamado,
            'no se llego a llamar al enviador: la barrera corta ANTES del cable');
        self::assertCount(0, Comprobantes::intentos((int) $factura->id),
            'y no queda intento, porque no hubo ninguno: nada salio de aqui');
        self::assertNull(DB::table('invoices')->where('id', $factura->id)->value('external_status'));
    }

    /**
     * **Y la misma factura sí se manda desde producción.**
     *
     * Sin esta, la anterior podría estar pasando por cualquier otra cosa que
     * falte —el certificado, la conexión, el usuario secundario—. Lo único que
     * cambia entre las dos es `instalacion.entorno`.
     */
    public function test_la_misma_factura_si_se_manda_desde_produccion(): void
    {
        config(['instalacion.entorno' => 'production']);
        $factura = $this->facturaConComprobante('production');
        $this->enviadorQueContesta($this->aceptado());

        $respuesta = Comprobantes::enviar($factura->uuid, $this->autorId);

        self::assertSame(RespuestaDeEnvio::ACEPTADO, $respuesta->estado);
        self::assertTrue($this->enviadorLlamado);
        self::assertCount(1, Comprobantes::intentos((int) $factura->id));
    }

    /**
     * Hacia PRUEBAS manda cualquiera. La barrera protege al servicio real, no
     * al de mentira: prohibir esto dejaría sin poder ensayar precisamente a la
     * máquina donde hay que ensayar.
     */
    public function test_desde_pruebas_si_se_manda_al_entorno_de_pruebas(): void
    {
        config(['instalacion.entorno' => 'local']);
        $factura = $this->facturaConComprobante('sandbox');
        $this->enviadorQueContesta($this->aceptado());

        $respuesta = Comprobantes::enviar($factura->uuid, $this->autorId);

        self::assertSame(RespuestaDeEnvio::ACEPTADO, $respuesta->estado);
        self::assertSame('sandbox', (string) Comprobantes::intentos((int) $factura->id)
            ->first()->environment);
    }

    /**
     * «No puedes desde aquí» y «falta configurarlo» **no son el mismo tipo**.
     *
     * Es la lección de `DEC-275` aplicada a las excepciones: dos cosas que se
     * ven iguales en un `catch` genérico y que se arreglan en sitios distintos
     * —una en el panel, la otra en otra máquina— no pueden compartir tipo.
     */
    public function test_falta_de_configuracion_no_es_barrera_de_entorno(): void
    {
        config(['instalacion.entorno' => 'production']);
        $factura = $this->facturaConComprobante('production', conConexion: false);
        $this->enviadorQueContesta($this->aceptado());

        try {
            Comprobantes::enviar($factura->uuid, $this->autorId);
            self::fail('se mando sin conexion configurada');
        } catch (EntornoAjeno) {
            self::fail('falta de configuracion no puede llegar como barrera de entorno');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('facturación electrónica', $e->getMessage(),
                'el mensaje nombra el proposito en palabras, no «invoicing»');
        }
    }

    // --------------------------------------------------------------- la anulación

    /** Con la anulación abierta se manda, **y queda escrito quién y cuándo**. */
    public function test_la_anulacion_deja_mandar_y_queda_en_la_bitacora(): void
    {
        config([
            'instalacion.entorno' => 'staging',
            'instalacion.permitir_conexiones_de_produccion' => true,
        ]);
        $factura = $this->facturaConComprobante('production');
        $this->enviadorQueContesta($this->aceptado());

        Comprobantes::enviar($factura->uuid, $this->autorId);

        self::assertTrue($this->enviadorLlamado, 'con la anulacion abierta si sale');
        self::assertSame(1, DB::table('audit_logs')
            ->where('action', 'integration.production_override')->count(),
            'ejercer la anulacion deja rastro: es lo que contesta a «.por que salio esto desde pruebas?»');
    }

    /** Y en producción la anulación no anota nada: no hay nada que anular. */
    public function test_en_produccion_la_anulacion_no_ensucia_la_bitacora(): void
    {
        config([
            'instalacion.entorno' => 'production',
            'instalacion.permitir_conexiones_de_produccion' => true,
        ]);
        $factura = $this->facturaConComprobante('production');
        $this->enviadorQueContesta($this->aceptado());

        Comprobantes::enviar($factura->uuid, $this->autorId);

        self::assertSame(0, DB::table('audit_logs')
            ->where('action', 'integration.production_override')->count());
    }

    // --------------------------------------------------------------- lo que se ve

    /**
     * La pantalla lo dice **antes** del botón, y no pinta el botón.
     *
     * El enviador se registra a propósito aunque esta prueba no mande nada.
     * Sin registrarlo pasaba —pero por el motivo equivocado, la cuarta vez que
     * ocurre en este proyecto—: con el registro vacío, `porQueNoSePuedeMandar()`
     * devolvía «no hay forma de entregar en PE» y la pantalla decía «desde aquí
     * no se manda» **sin que la barrera hubiera intervenido**. Se descubrió
     * rompiendo la barrera a mano para ver qué se ponía rojo: esta prueba
     * siguió verde.
     */
    public function test_la_pantalla_dice_el_motivo_y_no_pinta_el_boton(): void
    {
        config(['instalacion.entorno' => 'staging']);
        $factura = $this->facturaConComprobante('production');
        $this->enviadorQueContesta($this->aceptado());

        $respuesta = $this->actingAs($this->usuarioCon('admin'))
            ->get(route('facturas.ver', ['uuid' => $factura->uuid]));

        $respuesta->assertOk();
        // Primero: que el bloque de envio SI se esta pintando. Sin esto,
        // «no se ve el boton» pasaria tambien si no se viera nada --el cuarto
        // caso de una asercion que acierta por el motivo equivocado--.
        $respuesta->assertSee('Todavía no se ha mandado', false);
        $respuesta->assertSee('Desde aquí no se manda.', false);
        // Y que el motivo sea EL DE LA BARRERA, nombrando la maquina: sin esto,
        // cualquier otro impedimento pintaria el mismo cartel.
        $respuesta->assertSee('Esta instalación es «Preproducción»', false);
        $respuesta->assertDontSee('Mandar a la administración', false);
    }

    /** Y la franja de la instalación sale en todas las pantallas del panel. */
    public function test_la_franja_dice_en_que_maquina_se_esta(): void
    {
        config(['instalacion.entorno' => 'staging']);

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('facturas.index'))
            ->assertOk()
            ->assertSee('Ésta no es la instalación de producción', false)
            ->assertSee('Preproducción', false);
    }

    /** En producción no hay franja: un aviso que se ve siempre deja de leerse. */
    public function test_en_produccion_no_hay_franja(): void
    {
        config(['instalacion.entorno' => 'production']);

        self::assertNull(Instalacion::aviso());

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('facturas.index'))
            ->assertOk()
            ->assertDontSee('Ésta no es la instalación de producción', false);
    }

    /** La anulación abierta pone la franja en ROJO: la barrera está levantada. */
    public function test_con_la_anulacion_abierta_la_franja_es_roja(): void
    {
        config([
            'instalacion.entorno' => 'staging',
            'instalacion.permitir_conexiones_de_produccion' => true,
        ]);

        $aviso = Instalacion::aviso();

        self::assertNotNull($aviso);
        self::assertSame(Aviso::ROJO, $aviso->nivel);
        self::assertStringContainsString('PERMITIR_CONEXIONES_DE_PRODUCCION', $aviso->texto,
            'el aviso dice como se cierra, no solo que esta abierta');
    }

    /**
     * Un entorno que no está en la lista se enseña **tal cual**.
     *
     * Quien puso «qa-lima» en su servidor quiere leer «qa-lima»; taparlo con
     * «Desconocido» esconde justo el dato que dice de qué máquina se habla.
     */
    public function test_un_entorno_con_nombre_propio_se_ensena_tal_cual(): void
    {
        config(['instalacion.entorno' => 'qa-lima']);

        self::assertSame('qa-lima', Instalacion::nombre());
        self::assertFalse(Instalacion::esProduccion());
        self::assertNotNull(Instalacion::porQueNoPuedeUsar('production'));
        self::assertNull(Instalacion::porQueNoPuedeUsar('sandbox'));
    }

    // --------------------------------------------------------------- utilería

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

    private function aceptado(): RespuestaDeEnvio
    {
        return new RespuestaDeEnvio(
            estado: RespuestaDeEnvio::ACEPTADO, codigo: '0',
            descripcion: 'La Factura numero F001-1, ha sido aceptada',
            cdr: 'ZIP-DEL-CDR', nombreCdr: 'R-x.zip',
        );
    }

    private function facturaConComprobante(string $entorno, bool $conConexion = true): object
    {
        if ($conConexion) {
            $this->conexionDeSunat($entorno);
        }

        $serieId = $this->serieDe($this->sociedadId, $entorno);

        $uuid = Facturas::borrador($this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'completed', 'billing_legal_entity_id' => $this->sociedadId,
            'currency_code' => 'PEN', 'revenue_amount' => 1000.0, 'is_gratis' => 0,
        ]));
        Facturas::emitir($uuid, $serieId, $this->autorId);

        // Armar el XML no toca la barrera: se arma AQUI y se guarda AQUI. Lo
        // que la barrera vigila es la salida, no la preparacion.
        Comprobantes::generar($uuid, $this->autorId);

        return DB::table('invoices')->where('uuid', $uuid)->first();
    }

    private function serieDe(int $sociedadId, string $entorno): int
    {
        $tipo = (int) DB::table('document_types')
            ->where('country_id', $this->paisPE)->where('code', 'invoice')->value('id');

        return Correlativos::guardarSerie(null, [
            'legal_entity_id' => $sociedadId, 'document_type_id' => $tipo,
            'series' => $entorno === 'production' ? 'F001' : 'F900',
            'next_number' => 1, 'environment' => $entorno,
            'is_active' => true, 'is_default' => true,
        ]);
    }

    private function conexionDeSunat(string $entorno): void
    {
        $proveedor = (int) DB::table('integration_providers')->where('code', 'sunat')->value('id');

        $uuid = Integraciones::guardarConexion(null, [
            'integration_provider_id' => $proveedor,
            'legal_entity_id' => $this->sociedadId,
            'name' => 'SUNAT '.$entorno,
            'environment' => $entorno,
            'username' => 'MODDATOS',
            'base_url' => '',
            'status' => 'active',
        ], $this->autorId);

        Integraciones::guardarSecreto(
            (int) Integraciones::porUuid($uuid)->id, 'password', 'moddatos', $this->autorId,
        );
    }

    private function cargarCertificado(string $entorno): void
    {
        $clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $ruc = (string) DB::table('legal_entities')->where('id', $this->sociedadId)->value('tax_id_number');
        $csr = openssl_csr_new(['countryName' => 'PE', 'organizationName' => 'Prueba', 'commonName' => $ruc],
            $clave, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $clave, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($x509, $pemCert);
        openssl_pkey_export($clave, $pemClave);

        Certificados::cargar($this->sociedadId, $entorno, $pemClave.$pemCert, null, $this->autorId);
    }

    private function enviadorQueContesta(RespuestaDeEnvio $respuesta): void
    {
        $this->enviadorLlamado = false;
        $marca = function (): void {
            $this->enviadorLlamado = true;
        };

        Enviadores::olvidar();
        Enviadores::registrar('PE', static fn (): EnviadorDeComprobante => new class($respuesta, $marca) implements EnviadorDeComprobante
        {
            /** @param \Closure(): void $marca */
            public function __construct(
                private RespuestaDeEnvio $respuesta,
                private \Closure $marca,
            ) {}

            public function envia(string $nombre, string $xml, CredencialesDeEnvio $credenciales): RespuestaDeEnvio
            {
                ($this->marca)();

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
}
