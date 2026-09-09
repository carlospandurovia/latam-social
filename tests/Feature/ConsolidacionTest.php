<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Cambio;
use App\Modules\Core\Services\Consolidacion;
use App\Modules\Core\Services\FiltrosDeResumen;
use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Resumen;
use App\Shared\Auth\Permisos;
use Carbon\CarbonImmutable;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * `D-12`: sumar varias monedas sin mentir.
 *
 * ### La que de verdad importa
 *
 * `test_una_moneda_sin_tasa_no_entra_en_el_total_y_se_dice`. Un consolidado mal
 * hecho no revienta: sale **de menos**, en silencio, y nadie lo nota hasta que
 * alguien cuadra a mano. Esa prueba fija que el importe que no se pudo convertir
 * sale nombrado, con su moneda y su motivo, y que el total queda marcado como
 * parcial.
 *
 * ### Y la segunda
 *
 * `test_lo_que_entra_y_lo_que_sale_no_usan_el_mismo_lado`. Con una sola tasa
 * para todo, el número saldría plausible y equivocado en una de las dos
 * direcciones siempre.
 */
final class ConsolidacionTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
        Queue::fake();
    }

    // ------------------------------------------------------------ los ajustes

    public function test_la_configuracion_llega_sembrada_y_sin_confirmar(): void
    {
        $ajustes = Consolidacion::ajustes();

        self::assertSame('PEN', $ajustes['moneda']);
        self::assertSame(Cambio::COMPRA, $ajustes['ingresos']);
        self::assertSame(Cambio::VENTA, $ajustes['egresos']);

        // Sembrada NO es confirmada: el panel ya consolida, y aun así alguien
        // tiene que decir que ésa es la moneda del negocio.
        self::assertFalse($ajustes['confirmado']);
        self::assertCount(1, Consolidacion::avisos());
        self::assertSame('ambar', Consolidacion::avisos()[0]->nivel);
    }

    /**
     * La fila existe y la moneda no, y el panel funciona igual.
     *
     * Es la forma del arreglo de `T-120`: la migración no puede sembrar una
     * moneda porque el catálogo llega después, así que la base dice «nadie ha
     * elegido» y el valor de partida lo pone el código. Si algún día alguien
     * mete un `default('PEN')` en la columna, esta prueba se pone roja y la
     * migración vuelve a caerse en una base limpia.
     */
    public function test_la_fila_nace_sin_moneda_y_el_panel_habla_igual(): void
    {
        $this->assertDatabaseHas('consolidation_settings', [
            'singleton' => 1, 'base_currency_code' => null, 'confirmed_at' => null,
        ]);

        self::assertSame('PEN', Consolidacion::ajustes()['moneda']);
    }

    public function test_guardar_confirma_y_apaga_el_aviso(): void
    {
        Consolidacion::guardar([
            'base_currency_code' => 'USD',
            'income_rate_side' => Cambio::MEDIO,
            'expense_rate_side' => Cambio::MEDIO,
        ], null);

        $ajustes = Consolidacion::ajustes();

        self::assertSame('USD', $ajustes['moneda']);
        self::assertTrue($ajustes['confirmado']);
        self::assertSame([], Consolidacion::avisos(), 'Confirmado y sin monedas sueltas: sin avisos.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'consolidation_settings.updated']);
    }

    public function test_sin_fila_los_ajustes_siguen_completos(): void
    {
        DB::table('consolidation_settings')->delete();

        // Un panel que se apaga porque falta una fila de configuración es lo
        // que `DEC-190` prohíbe. Los valores de partida sostienen la pantalla.
        self::assertSame('PEN', Consolidacion::ajustes()['moneda']);
        self::assertFalse(Consolidacion::ajustes()['confirmado']);
    }

    // ----------------------------------------------------------- la aritmética

    public function test_el_total_convierte_y_suma_lo_que_ya_estaba_en_la_base(): void
    {
        $hoy = CarbonImmutable::now()->toDateString();
        Cambio::anotar('USD', 'PEN', $hoy, '3.50000000', 'sunat', Cambio::COMPRA);

        $resultado = Consolidacion::consolidar(
            [['moneda' => 'PEN', 'importe' => 200.0], ['moneda' => 'USD', 'importe' => 100.0]],
            Consolidacion::INGRESO, $hoy,
        );

        // 200 + (100 × 3,50) = 550. El número se conoce de antemano, que es lo
        // que hace que esta prueba signifique algo.
        self::assertSame(550.0, $resultado['total']);
        self::assertSame('PEN', $resultado['moneda']);
        self::assertFalse($resultado['parcial']);
        self::assertSame($hoy, $resultado['fecha']);
    }

    public function test_una_moneda_sin_tasa_no_entra_en_el_total_y_se_dice(): void
    {
        // COP a propósito: nadie ha declarado fuente para ese par, que es el
        // caso de un cliente colombiano el primer día.
        $resultado = Consolidacion::consolidar(
            [['moneda' => 'PEN', 'importe' => 100.0], ['moneda' => 'COP', 'importe' => 900.0]],
            Consolidacion::INGRESO, CarbonImmutable::now()->toDateString(),
        );

        self::assertSame(100.0, $resultado['total'], 'Sólo lo que se pudo convertir.');
        self::assertTrue($resultado['parcial'], 'Y el total tiene que decir que le falta algo.');
        self::assertSame('COP', $resultado['fuera'][0]['moneda']);
        self::assertSame(900.0, $resultado['fuera'][0]['importe']);
        self::assertStringContainsString('fuente', $resultado['fuera'][0]['motivo']);
    }

    public function test_lo_que_entra_y_lo_que_sale_no_usan_el_mismo_lado(): void
    {
        $hoy = CarbonImmutable::now()->toDateString();
        Cambio::anotar('USD', 'PEN', $hoy, '3.50000000', 'sunat', Cambio::COMPRA);
        Cambio::anotar('USD', 'PEN', $hoy, '3.80000000', 'sunat', Cambio::VENTA);

        $lineas = [['moneda' => 'USD', 'importe' => 100.0]];

        // El mismo importe, el mismo día, dos números distintos y los dos
        // correctos: 350 lo que entra, 380 lo que sale.
        self::assertSame(350.0, Consolidacion::consolidar($lineas, Consolidacion::INGRESO, $hoy)['total']);
        self::assertSame(380.0, Consolidacion::consolidar($lineas, Consolidacion::EGRESO, $hoy)['total']);
    }

    public function test_sin_lineas_no_se_inventa_un_cero(): void
    {
        $resultado = Consolidacion::consolidar([], Consolidacion::INGRESO, CarbonImmutable::now()->toDateString());

        // Un 0,00 se lee como «no hay dinero». Lo que pasa es que no hay nada
        // que sumar, y eso se dice con un guion.
        self::assertNull($resultado['total']);
        self::assertFalse($resultado['parcial']);
    }

    public function test_un_domingo_se_convierte_con_la_tasa_del_viernes_y_lo_dice(): void
    {
        $viernes = CarbonImmutable::now()->subDays(3)->toDateString();
        $hoy = CarbonImmutable::now()->toDateString();

        Cambio::anotar('USD', 'PEN', $viernes, '3.50000000', 'sunat', Cambio::COMPRA);

        $resultado = Consolidacion::consolidar(
            [['moneda' => 'USD', 'importe' => 100.0]], Consolidacion::INGRESO, $hoy,
        );

        self::assertSame(350.0, $resultado['total']);
        // La fecha que se enseña es la REAL de la tasa, no la de hoy: guardar la
        // de hoy afirmaría que hoy hubo una tasa que nadie publicó.
        self::assertSame($viernes, $resultado['fecha']);
    }

    public function test_cambiar_la_moneda_base_cambia_el_total(): void
    {
        $hoy = CarbonImmutable::now()->toDateString();
        Cambio::anotar('PEN', 'USD', $hoy, '0.25000000', 'manual', Cambio::COMPRA);
        Cambio::declararOficial('PEN', 'USD', 'manual', $hoy);

        Consolidacion::guardar([
            'base_currency_code' => 'USD',
            'income_rate_side' => Cambio::COMPRA,
            'expense_rate_side' => Cambio::VENTA,
        ], null);

        $resultado = Consolidacion::consolidar(
            [['moneda' => 'PEN', 'importe' => 400.0]], Consolidacion::INGRESO, $hoy,
        );

        // 400 × 0,25 = 100. Si algún día alguien escribe «PEN» en el código,
        // esta prueba se pone roja.
        self::assertSame('USD', $resultado['moneda']);
        self::assertSame(100.0, $resultado['total']);
    }

    // -------------------------------------------------------------- el panel

    public function test_el_bloque_financiero_trae_su_consolidado(): void
    {
        // `clienteDePrueba()` es PRIVADO de `FinancieroTest`: verlo alli y
        // llamarlo desde aqui fue exactamente el error de `T-117` al reves.
        // Se fabrica el cliente con lo minimo que pide el esquema.
        $clienteId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_code' => 'CON-01',
            'commercial_name' => 'Cliente de consolidación',
            'country_id' => (int) DB::table('countries')->where('iso2', 'PE')->value('id'),
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->facturaEmitida($clienteId);

        $bloques = [];
        foreach (Resumen::financiero(FiltrosDeResumen::porDefecto())['bloques'] as $bloque) {
            $bloques[$bloque['clave']] = $bloque;
        }

        self::assertSame(118.0, $bloques['facturado']['consolidado']['total']);
        self::assertSame('PEN', $bloques['facturado']['consolidado']['moneda']);
        // Todo estaba ya en la moneda base: no se usó ninguna tasa, y por eso
        // no hay fecha que enseñar.
        self::assertNull($bloques['facturado']['consolidado']['fecha']);
    }

    public function test_el_panel_dice_que_la_moneda_no_esta_confirmada(): void
    {
        $html = (string) $this->actingAs($this->usuarioCon('finance'))
            ->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringContainsString('Nadie ha confirmado todavía', $html);
    }

    // ------------------------------------------------------------ la pantalla

    public function test_la_pantalla_exige_fx_manage(): void
    {
        $sinPermiso = $this->usuarioCon('campaign_manager');

        // La premisa, afirmada: si algún día `campaign_manager` recibiera
        // `fx.manage`, esta prueba lo diría aquí y no cuatro líneas abajo.
        self::assertFalse($sinPermiso->can('fx.manage'));

        $this->actingAs($sinPermiso)->get('/backoffice/moneda')->assertForbidden();
    }

    public function test_finanzas_ve_la_pantalla_y_guarda(): void
    {
        $usuario = $this->usuarioCon('finance');

        self::assertTrue($usuario->can('fx.manage'), 'Quien declara las tasas decide la moneda.');

        $this->actingAs($usuario)->get('/backoffice/moneda')->assertOk()
            ->assertSee('Moneda de consolidación');

        $this->actingAs($usuario)->put('/backoffice/moneda', [
            'base_currency_code' => 'USD',
            'income_rate_side' => Cambio::COMPRA,
            'expense_rate_side' => Cambio::VENTA,
        ])->assertRedirect();

        self::assertSame('USD', Consolidacion::ajustes()['moneda']);
        self::assertTrue(Consolidacion::ajustes()['confirmado']);
    }

    public function test_una_moneda_que_no_existe_no_se_guarda(): void
    {
        $this->actingAs($this->usuarioCon('finance'))->put('/backoffice/moneda', [
            'base_currency_code' => 'XXX',
            'income_rate_side' => Cambio::COMPRA,
            'expense_rate_side' => Cambio::VENTA,
        ])->assertSessionHasErrors('base_currency_code');

        self::assertSame('PEN', Consolidacion::ajustes()['moneda']);
    }

    public function test_un_lado_inventado_tampoco(): void
    {
        $this->actingAs($this->usuarioCon('finance'))->put('/backoffice/moneda', [
            'base_currency_code' => 'PEN',
            'income_rate_side' => 'regateado',
            'expense_rate_side' => Cambio::VENTA,
        ])->assertSessionHasErrors('income_rate_side');
    }

    public function test_la_configuracion_registra_el_area(): void
    {
        $html = (string) $this->actingAs($this->usuarioCon('finance'))
            ->get('/backoffice/configuracion')->assertOk()->getContent();

        self::assertStringContainsString('Moneda de consolidación', $html);
    }
}
