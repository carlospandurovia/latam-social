<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Cambio;
use App\Modules\Core\Services\CredencialFuente;
use App\Modules\Core\Services\Decolecta;
use App\Modules\Core\Services\TraidaDeCambio;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Traer los tipos de cambio solos (iteración 9.2).
 *
 * Lo que esta iteración existe para impedir son tres cosas:
 *
 * 1. Que la **credencial** acabe en claro en la base, o en pantalla, o en la
 *    bitácora.
 * 2. Que el cron **falle en silencio** — que es la forma que tienen los procesos
 *    automáticos de estar rotos durante semanas.
 * 3. Que una respuesta rara de la API se **anote como si fuera una tasa**.
 */
final class TiposDeCambioTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        // La premisa: el seeder deja declarado quien publica USD->PEN. Sin
        // ella, media prueba diria «sin fuente» y pareceria otra cosa.
        $this->assertDatabaseHas('fx_official_sources', [
            'base_currency_code' => 'USD', 'quote_currency_code' => 'PEN',
            'source_code' => 'sunat', 'valid_to' => null,
        ]);
    }

    // ------------------------------------------------------------ credencial

    /**
     * La clave se guarda cifrada: en la columna no está el valor.
     *
     * 9.17h: la columna es otra. La clave dejó de vivir en `fx_sources` —que
     * tenía su propia caja fuerte, sin versión y sin revocación— y vive en
     * `integration_credentials`, por la misma puerta que la de SUNAT y la del
     * correo. Lo que la prueba defiende no ha cambiado: **en la tabla no está
     * el valor**.
     */
    public function test_la_credencial_no_queda_en_claro_en_la_base(): void
    {
        CredencialFuente::guardar('sunat', 'clave-secreta-8f2a', $this->usuarioCon('admin')->id);

        $guardada = (string) DB::table('fx_sources as s')
            ->join('integration_credentials as c', 'c.integration_connection_id', '=', 's.integration_connection_id')
            ->where('s.code', 'sunat')->whereNull('c.revoked_at')
            ->value('c.secret_cipher');

        $this->assertNotSame('clave-secreta-8f2a', $guardada);
        $this->assertStringNotContainsString('clave-secreta', $guardada);
        $this->assertSame('clave-secreta-8f2a', CredencialFuente::clave('sunat'));
    }

    /**
     * 9.17h: guardar una clave nueva **revoca la anterior**, no la pisa.
     *
     * Es lo que la caja fuerte vieja de `fx_sources` no sabía hacer: un
     * `UPDATE` sobre `api_key_cipher` se llevaba por delante quién había puesto
     * la anterior y hasta cuándo estuvo en uso. Esa es la primera pregunta el
     * día que aparezca un consumo raro contra el servicio.
     */
    public function test_una_clave_nueva_revoca_la_anterior_y_la_deja_en_el_historico(): void
    {
        $autor = (int) $this->usuarioCon('admin')->id;

        CredencialFuente::guardar('sunat', 'la-primera-1111', $autor);
        CredencialFuente::guardar('sunat', 'la-segunda-2222', $autor);

        $conexionId = (int) DB::table('fx_sources')->where('code', 'sunat')
            ->value('integration_connection_id');

        $filas = DB::table('integration_credentials')
            ->where('integration_connection_id', $conexionId)
            ->orderBy('version')->get(['version', 'last4', 'revoked_at', 'revoked_reason']);

        $this->assertCount(2, $filas, 'las dos siguen ahi');
        $this->assertNotNull($filas[0]->revoked_at, 'la primera queda revocada');
        $this->assertNotNull($filas[0]->revoked_reason, 'y con su motivo');
        $this->assertNull($filas[1]->revoked_at, 'la segunda es la viva');
        $this->assertSame('la-segunda-2222', CredencialFuente::clave('sunat'));
    }

    /**
     * 9.17h: retirar la clave **no la borra**, la revoca.
     *
     * `9.2` la ponía a NULL y con ella se iba quién la había puesto.
     */
    public function test_retirar_la_clave_la_deja_revocada_y_no_borrada(): void
    {
        $autor = (int) $this->usuarioCon('admin')->id;
        CredencialFuente::guardar('sunat', 'la-que-se-retira', $autor);

        CredencialFuente::olvidar('sunat');

        $conexionId = (int) DB::table('fx_sources')->where('code', 'sunat')
            ->value('integration_connection_id');

        $this->assertSame(1, DB::table('integration_credentials')
            ->where('integration_connection_id', $conexionId)->count(), 'la fila sigue');
        $this->assertSame(0, DB::table('integration_credentials')
            ->where('integration_connection_id', $conexionId)->whereNull('revoked_at')->count());
        $this->assertNull(CredencialFuente::clave('sunat'));
    }

    /**
     * 9.17h: la dirección sale del catálogo del proveedor, no de una columna.
     *
     * Estaba en una constante de PHP **y además** en una columna que se podía
     * teclear. Es la dirección fija y pública de Decolecta: ni una cosa ni la
     * otra (`DEC-255`).
     */
    public function test_la_direccion_de_decolecta_la_declara_el_catalogo(): void
    {
        CredencialFuente::guardar('sunat', 'una-clave-cualquiera', (int) $this->usuarioCon('admin')->id);

        $this->assertSame(
            'https://api.decolecta.com',
            CredencialFuente::url('sunat', 'https://no-deberia-usarse.test'),
        );
    }

    /** Y lo que se le enseña a una persona son cuatro caracteres, no la clave. */
    public function test_el_estado_no_devuelve_nunca_la_clave(): void
    {
        CredencialFuente::guardar('sunat', 'clave-secreta-8f2a', $this->usuarioCon('admin')->id);

        $estado = CredencialFuente::estado('sunat');

        $this->assertSame(CredencialFuente::BASE, $estado['origen']);
        $this->assertSame('8f2a', $estado['ultimos']);
        $this->assertNotContains('clave-secreta-8f2a', $estado);
    }

    /**
     * 9.17h: **la afirmación se invierte, y con motivo.**
     *
     * Hasta hoy el entorno ganaba siempre. Esa regla convivía en la MISMA
     * pantalla con la contraria de `DEC-260` —la cuenta de correo guardada
     * manda sobre el `.env`—, y dos integraciones vecinas con precedencias
     * opuestas es peor que cualquiera de las dos reglas por separado: se teclea
     * una clave en el panel, se guarda, y sigue llamando con la de antes sin
     * que nada lo diga. Ahora manda la guardada, y el `.env` es el respaldo.
     */
    public function test_la_clave_guardada_gana_a_la_del_entorno(): void
    {
        CredencialFuente::guardar('sunat', 'la-de-la-base', $this->usuarioCon('admin')->id);

        // Por `config()` y no por `putenv()`: es como lo lee el servicio, y es
        // lo unico que sigue funcionando con `config:cache` en produccion.
        config(['latam.cambio.decolecta.clave' => 'la-del-entorno']);

        $this->assertSame('la-de-la-base', CredencialFuente::clave('sunat'));
        $this->assertSame(CredencialFuente::BASE, CredencialFuente::estado('sunat')['origen']);
    }

    /** Y sin ninguna guardada, el `.env` sigue sirviendo. */
    public function test_sin_clave_guardada_manda_la_del_entorno(): void
    {
        config(['latam.cambio.decolecta.clave' => 'la-del-entorno']);

        $this->assertSame('la-del-entorno', CredencialFuente::clave('sunat'));
        $this->assertSame(CredencialFuente::ENTORNO, CredencialFuente::estado('sunat')['origen']);
    }

    /**
     * Media firma sigue sin valer — y ahora lo impone la COLUMNA.
     *
     * `9.2` lo defendía con `tg_fxs_credencial_firmada`, un disparador que
     * comprobaba que las cuatro columnas de la firma fueran juntas. Con la
     * clave en `integration_credentials` no hace falta ningún disparador:
     * `set_by_user_id` y `set_at` son `NOT NULL`, así que una credencial sin
     * autor **no cabe en la tabla**. Una regla que se convierte en una columna
     * obligatoria es una regla que ya no se puede olvidar de comprobar.
     */
    public function test_una_credencial_sin_autor_no_entra_ni_por_sql(): void
    {
        $conexionId = (int) DB::table('integration_connections')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'integration_provider_id' => (int) DB::table('integration_providers')
                ->where('purpose', 'fx')->value('id'),
            'legal_entity_id' => null,
            'name' => 'Fuente sin firma',
            'environment' => 'production',
            'base_url' => null,
            'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `set_by_user_id` y `set_at` son NOT NULL,
        // y eso es justo lo que esta prueba afirma.
        DB::table('integration_credentials')->insert([
            'integration_connection_id' => $conexionId,
            'kind' => 'api_key',
            'secret_cipher' => 'loquesea',
            'last4' => 'abcd',
            'version' => 1,
            'set_by_user_id' => null,
            'set_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** La bitácora guarda que cambió, y los cuatro últimos. Nunca el valor. */
    public function test_la_bitacora_no_guarda_la_clave(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post('/backoffice/tipos-de-cambio/credencial', ['api_key' => 'clave-secreta-8f2a'])
            // 9.17h: vuelve a la PESTANA, que es donde esta el formulario ahora.
            ->assertRedirect(route('integraciones.index', ['p' => 'fx']))
            ->assertSessionHas('exito');

        $fila = DB::table('audit_logs')->where('action', 'fx.credential.set')->first();

        $this->assertNotNull($fila);
        $this->assertStringNotContainsString('clave-secreta', (string) $fila->changes);
        $this->assertStringContainsString('8f2a', (string) $fila->changes);
    }

    /** Guardar la credencial exige `integration.manage`, no `fx.manage`. */
    public function test_anotar_tasas_no_da_derecho_a_tocar_la_credencial(): void
    {
        $finanzas = $this->usuarioCon('finance');

        $this->actingAs($finanzas)->get('/backoffice/tipos-de-cambio')->assertOk();
        $this->actingAs($finanzas)
            ->post('/backoffice/tipos-de-cambio/credencial', ['api_key' => 'clave-secreta-8f2a'])
            ->assertForbidden();
    }

    public function test_sin_permiso_no_se_ve_la_pantalla(): void
    {
        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->get('/backoffice/tipos-de-cambio')->assertForbidden();
    }

    // --------------------------------------------------------------- traida

    public function test_trae_compra_y_venta_y_las_anota(): void
    {
        CredencialFuente::guardar('sunat', 'clave-secreta-8f2a', $this->usuarioCon('admin')->id);

        Http::fake(['api.decolecta.com/*' => Http::response([
            'buy_price' => 3.735, 'sell_price' => 3.742,
            'base_currency' => 'USD', 'quote_currency' => 'PEN', 'date' => '2026-08-14',
        ], 200)]);

        $r = Decolecta::traer('2026-08-14');

        $this->assertSame(Decolecta::OK, $r['outcome']);
        $this->assertSame(2, $r['nuevas']);
        $this->assertSame('3.73500000', Cambio::tasa('USD', 'PEN', '2026-08-14', Cambio::COMPRA)->tasa);
        $this->assertSame('3.74200000', Cambio::tasa('USD', 'PEN', '2026-08-14', Cambio::VENTA)->tasa);
    }

    /** Repetir un día no duplica ni revienta: el cron pide tres días cada vez. */
    public function test_traer_el_mismo_dia_dos_veces_no_duplica(): void
    {
        CredencialFuente::guardar('sunat', 'clave-secreta-8f2a', $this->usuarioCon('admin')->id);
        Http::fake(['api.decolecta.com/*' => Http::response([
            'buy_price' => 3.735, 'sell_price' => 3.742, 'date' => '2026-08-14',
        ], 200)]);

        Decolecta::traer('2026-08-14');
        $segunda = Decolecta::traer('2026-08-14');

        $this->assertSame(Decolecta::OK, $segunda['outcome']);
        $this->assertSame(0, $segunda['nuevas']);
        $this->assertSame(2, DB::table('exchange_rates')->where('rate_date', '2026-08-14')->count());
    }

    /**
     * **La que importa.** Un 200 con un cuerpo que no entendemos **no anota
     * nada**, y se llama distinto que un 500.
     *
     * Si esto anotara, una tasa inventada entraría en una tabla que después no
     * se puede corregir — `tg_fx_inmutable` no deja.
     */
    public function test_una_respuesta_rara_no_anota_nada_y_tiene_nombre_propio(): void
    {
        CredencialFuente::guardar('sunat', 'clave-secreta-8f2a', $this->usuarioCon('admin')->id);
        Http::fake(['api.decolecta.com/*' => Http::response(['mensaje' => 'ok'], 200)]);

        $r = Decolecta::traer('2026-08-14');

        $this->assertSame(Decolecta::RESPUESTA_RARA, $r['outcome']);
        $this->assertSame(0, DB::table('exchange_rates')->count());
    }

    /** Un precio que no es un número tampoco se anota. */
    public function test_un_precio_que_no_es_numero_no_se_anota(): void
    {
        CredencialFuente::guardar('sunat', 'clave-secreta-8f2a', $this->usuarioCon('admin')->id);
        Http::fake(['api.decolecta.com/*' => Http::response([
            'buy_price' => 'no disponible', 'sell_price' => 3.742, 'date' => '2026-08-14',
        ], 200)]);

        $r = Decolecta::traer('2026-08-14');

        $this->assertSame(Decolecta::RESPUESTA_RARA, $r['outcome']);
        $this->assertSame(0, DB::table('exchange_rates')->count());
    }

    /**
     * Y si el que falla es el SEGUNDO precio, tampoco se anota el primero.
     *
     * Escribiendo sobre la marcha, un `sell_price` malo dejaba la compra ya
     * anotada mientras el resultado decia `RESPUESTA_RARA` — y como
     * `ck_ffr_nuevas` obliga a que una corrida fallida diga cero, el registro
     * juraba que no habia entrado nada. Una fila en `exchange_rates` que nadie
     * sabe que existe, en una tabla que **no se puede corregir**.
     */
    public function test_si_falla_el_segundo_precio_no_se_anota_el_primero(): void
    {
        CredencialFuente::guardar('sunat', 'clave-secreta-8f2a', $this->usuarioCon('admin')->id);
        Http::fake(['api.decolecta.com/*' => Http::response([
            'buy_price' => 3.735, 'sell_price' => 0, 'date' => '2026-08-14',
        ], 200)]);

        $r = Decolecta::traer('2026-08-14');

        $this->assertSame(Decolecta::RESPUESTA_RARA, $r['outcome']);
        $this->assertSame(0, DB::table('exchange_rates')->count(), 'ni la compra, que era buena');
    }

    public function test_sin_credencial_lo_dice_y_no_llama_a_nadie(): void
    {
        Http::fake();

        $r = Decolecta::traer('2026-08-14');

        $this->assertSame(Decolecta::SIN_CREDENCIAL, $r['outcome']);
        Http::assertNothingSent();
    }

    /** Cada final tiene su nombre: 401 no es 404 y no se arreglan igual. */
    public function test_un_401_dice_que_la_credencial_no_vale(): void
    {
        CredencialFuente::guardar('sunat', 'clave-secreta-8f2a', $this->usuarioCon('admin')->id);
        Http::fake(['api.decolecta.com/*' => Http::response([], 401)]);

        $r = Decolecta::traer('2026-08-14');

        $this->assertSame(Decolecta::ERROR_HTTP, $r['outcome']);
        $this->assertSame(401, $r['http']);
        $this->assertStringContainsString('rechazo la credencial', $r['detalle']);
    }

    // ------------------------------------------------------------- registro

    /** `ck_ffr_nuevas`: un intento fallido no pudo anotar nada. */
    public function test_una_corrida_fallida_no_puede_decir_que_trajo_tasas(): void
    {
        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: la prueba afirma que la base lo rechaza.
        DB::table('fx_fetch_runs')->insert([
            'source_code' => 'sunat', 'requested_date' => '2026-08-14', 'ran_at' => now(),
            'outcome' => 'error_http', 'rates_new' => 3, 'created_at' => now(),
        ]);
    }

    /** Sin ninguna corrida, la pantalla lo dice: el cron no ha arrancado. */
    public function test_si_el_cron_no_ha_corrido_nunca_la_pantalla_lo_dice(): void
    {
        $aviso = TraidaDeCambio::loQueHayQueMirar('sunat');

        $this->assertNotNull($aviso);
        $this->assertStringContainsString('no se ha ejecutado nunca', $aviso);
    }

    /** Y con una corrida buena de hoy, **no** hay aviso: no se grita por lo normal. */
    public function test_con_una_corrida_buena_de_hoy_no_hay_aviso(): void
    {
        TraidaDeCambio::anotar('sunat', now()->toDateString(),
            ['outcome' => Decolecta::OK, 'nuevas' => 2, 'http' => 200, 'detalle' => 'Anotadas 2.']);

        $this->assertNull(TraidaDeCambio::loQueHayQueMirar('sunat'));
    }

    /** Una corrida vieja sí avisa: eso ya no es un fin de semana. */
    public function test_una_corrida_de_hace_dias_avisa(): void
    {
        TraidaDeCambio::anotar('sunat', '2026-08-01',
            ['outcome' => Decolecta::OK, 'nuevas' => 2, 'http' => 200, 'detalle' => 'Anotadas 2.']);

        DB::table('fx_fetch_runs')->update(['ran_at' => now()->subDays(9)]);

        $aviso = TraidaDeCambio::loQueHayQueMirar('sunat');

        $this->assertNotNull($aviso);
        $this->assertStringContainsString('revise el cron', $aviso);
    }

    // -------------------------------------------------------------- comando

    public function test_el_comando_pide_tres_dias_y_deja_su_rastro(): void
    {
        CredencialFuente::guardar('sunat', 'clave-secreta-8f2a', $this->usuarioCon('admin')->id);
        Http::fake(['api.decolecta.com/*' => Http::response([
            'buy_price' => 3.735, 'sell_price' => 3.742,
        ], 200)]);

        $this->artisan('cambio:traer')->assertSuccessful();

        $this->assertSame(3, DB::table('fx_fetch_runs')->count());
    }

    /**
     * Sin credencial el comando **no** devuelve error… no: sí lo devuelve,
     * porque ningún día se pudo traer, y eso es una avería y no un feriado.
     */
    public function test_sin_credencial_el_comando_falla_pero_deja_escrito_por_que(): void
    {
        Http::fake();

        $this->artisan('cambio:traer', ['--dias' => 1])->assertFailed();

        $this->assertDatabaseHas('fx_fetch_runs', ['outcome' => Decolecta::SIN_CREDENCIAL]);
    }

    /** Un 404 —día sin publicar— no hace fallar al comando entero. */
    public function test_un_dia_sin_publicar_no_tumba_la_corrida(): void
    {
        CredencialFuente::guardar('sunat', 'clave-secreta-8f2a', $this->usuarioCon('admin')->id);

        $respuestas = [Http::response([], 404), Http::response([], 404),
            Http::response(['buy_price' => 3.735, 'sell_price' => 3.742], 200)];
        Http::fake(['api.decolecta.com/*' => Http::sequence($respuestas)]);

        $this->artisan('cambio:traer')->assertSuccessful();
    }

    // ------------------------------------------------------------- pantalla

    public function test_la_pantalla_dice_que_decolecta_solo_trae_dolares(): void
    {
        $respuesta = $this->actingAs($this->usuarioCon('finance'))->get('/backoffice/tipos-de-cambio');

        $respuesta->assertOk();
        $respuesta->assertSee('sólo trae USD', false);
    }

    /** Declarar una fuente nueva releva a la anterior el día antes. */
    public function test_declarar_otra_fuente_desde_la_pantalla_cierra_la_anterior(): void
    {
        $this->actingAs($this->usuarioCon('finance'))->post('/backoffice/tipos-de-cambio/oficial', [
            'base_currency_code' => 'USD', 'quote_currency_code' => 'PEN',
            'source_code' => 'manual', 'valid_from' => '2026-06-01',
        ])->assertSessionHas('exito');

        $this->assertDatabaseHas('fx_official_sources', [
            'source_code' => 'sunat', 'valid_to' => '2026-05-31',
        ]);
        $this->assertSame('manual', Cambio::fuenteOficial('USD', 'PEN', '2026-06-01')->source_code);
    }

    /** Una tasa a mano se guarda con la fuente `manual`, no disfrazada de SUNAT. */
    public function test_una_tasa_a_mano_no_se_disfraza_de_sunat(): void
    {
        $this->actingAs($this->usuarioCon('finance'))->post('/backoffice/tipos-de-cambio/anotar', [
            'base_currency_code' => 'USD', 'quote_currency_code' => 'PEN',
            'rate_date' => '2026-08-14', 'rate' => '3.742', 'side' => Cambio::VENTA,
        ])->assertSessionHas('exito');

        $this->assertDatabaseHas('exchange_rates', [
            'rate_date' => '2026-08-14', 'source' => 'manual', 'side' => Cambio::VENTA,
        ]);
    }
}
