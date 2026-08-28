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

    /** La clave se guarda cifrada: en la columna no está el valor. */
    public function test_la_credencial_no_queda_en_claro_en_la_base(): void
    {
        CredencialFuente::guardar('sunat', 'clave-secreta-8f2a', $this->usuarioCon('admin')->id);

        $guardada = (string) DB::table('fx_sources')->where('code', 'sunat')->value('api_key_cipher');

        $this->assertNotSame('clave-secreta-8f2a', $guardada);
        $this->assertStringNotContainsString('clave-secreta', $guardada);
        $this->assertSame('clave-secreta-8f2a', CredencialFuente::clave('sunat'));
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

    /** El entorno manda sobre la base. */
    public function test_la_clave_del_entorno_gana_a_la_guardada(): void
    {
        CredencialFuente::guardar('sunat', 'la-de-la-base', $this->usuarioCon('admin')->id);

        // Por `config()` y no por `putenv()`: es como lo lee el servicio, y es
        // lo unico que sigue funcionando con `config:cache` en produccion.
        config(['latam.cambio.decolecta.clave' => 'la-del-entorno']);

        $this->assertSame('la-del-entorno', CredencialFuente::clave('sunat'));
        $this->assertSame(CredencialFuente::ENTORNO, CredencialFuente::estado('sunat')['origen']);
    }

    /** `tg_fxs_credencial_firmada`: media firma no vale. */
    public function test_una_credencial_sin_autor_no_entra_ni_por_sql(): void
    {
        $this->expectException(QueryException::class);

        DB::table('fx_sources')->where('code', 'sunat')->update([
            'api_key_cipher' => 'loquesea', 'api_key_last4' => 'abcd',
            'credential_set_at' => now(), 'credential_set_by_user_id' => null,
        ]);
    }

    /** La bitácora guarda que cambió, y los cuatro últimos. Nunca el valor. */
    public function test_la_bitacora_no_guarda_la_clave(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post('/tipos-de-cambio/credencial', ['api_key' => 'clave-secreta-8f2a'])
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

        $this->actingAs($finanzas)->get('/tipos-de-cambio')->assertOk();
        $this->actingAs($finanzas)
            ->post('/tipos-de-cambio/credencial', ['api_key' => 'clave-secreta-8f2a'])
            ->assertForbidden();
    }

    public function test_sin_permiso_no_se_ve_la_pantalla(): void
    {
        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->get('/tipos-de-cambio')->assertForbidden();
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
        $respuesta = $this->actingAs($this->usuarioCon('finance'))->get('/tipos-de-cambio');

        $respuesta->assertOk();
        $respuesta->assertSee('sólo trae USD', false);
    }

    /** Declarar una fuente nueva releva a la anterior el día antes. */
    public function test_declarar_otra_fuente_desde_la_pantalla_cierra_la_anterior(): void
    {
        $this->actingAs($this->usuarioCon('finance'))->post('/tipos-de-cambio/oficial', [
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
        $this->actingAs($this->usuarioCon('finance'))->post('/tipos-de-cambio/anotar', [
            'base_currency_code' => 'USD', 'quote_currency_code' => 'PEN',
            'rate_date' => '2026-08-14', 'rate' => '3.742', 'side' => Cambio::VENTA,
        ])->assertSessionHas('exito');

        $this->assertDatabaseHas('exchange_rates', [
            'rate_date' => '2026-08-14', 'source' => 'manual', 'side' => Cambio::VENTA,
        ]);
    }
}
