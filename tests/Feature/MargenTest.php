<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Cambio;
use App\Modules\Core\Services\Consolidacion;
use App\Modules\Core\Services\FiltrosDeResumen;
use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Resumen;
use App\Modules\Finance\Services\Costos;
use App\Modules\Finance\Services\Ledger;
use App\Modules\Finance\Services\Rentabilidad;
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
 * `D-13`: el margen en el panel.
 *
 * ### La que de verdad importa
 *
 * `test_el_panel_y_rentabilidad_dan_el_mismo_numero`. La resta está escrita
 * **dos veces** —`Resumen::margen()` en `Core` y `Rentabilidad` en `Finance`—
 * porque `Core` no puede depender de `Finance` y `deptrac` lo impide
 * (`DEC-353`). Dos definiciones del mismo número es una deuda, y lo único que
 * evita que se separen es esta prueba: las pone a las dos delante de los mismos
 * datos y compara.
 *
 * ### Y la de seguridad
 *
 * El margen no lo ve quien lleva la campaña (`DEC-181`, `BR-SEC-001` 🔴). Dos
 * pruebas: que no se pinta, y que **no se consulta** —un `@can` en la plantilla
 * sobre datos ya traídos es una fuga esperando a que alguien borre el `@can`—.
 */
final class MargenTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $clienteId;

    private int $marcaId;

    private int $paisPE;

    private int $sociedadId;

    private string $moneda;

    private string $otraMoneda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
        Queue::fake();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');

        // **Las monedas se ELIGEN, no se heredan del catálogo** (`T-122`).
        //
        // `DB::table('currencies')->value('code')` --el idiom que usan las
        // demás suites-- devuelve `ARS`, no `PEN`: la consulta es un
        // `SELECT code ... LIMIT 1` sin `ORDER BY` y `code` está cubierto por
        // `uq_currencies_code`, así que el motor recorre ESE índice y las filas
        // salen en orden alfabético. Da igual para una prueba que sólo agrupa;
        // aquí se convierte, y ARS no tiene fuente declarada.
        $this->moneda = 'PEN';
        $this->otraMoneda = 'USD';

        // La premisa, afirmada: si el par USD→PEN dejara de estar sembrado, o
        // la moneda base de partida cambiara, estas pruebas fallarían cuatro
        // líneas más abajo hablando de otra cosa (`DEC-330`).
        self::assertSame($this->moneda, Consolidacion::ajustes()['moneda']);
        self::assertNotNull(
            Cambio::fuenteOficial($this->otraMoneda, $this->moneda, now()->toDateString()),
            'Sin fuente oficial USD→PEN estas pruebas no prueban una conversión.',
        );

        $this->sociedadId = $this->entidadLegal();

        $this->clienteId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'commercial_name' => 'ACME', 'client_code' => 'ACME-01',
            'country_id' => $this->paisPE, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->marcaId = (int) DB::table('client_brands')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_organization_id' => $this->clienteId,
            'name' => 'Marca ACME', 'slug' => 'marca-acme', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------- la resta

    public function test_ingreso_menos_creadores_menos_gasto(): void
    {
        $campanaId = $this->campanaConMargen(15000.0, 700.0);
        $this->gasto($campanaId, 1200.0);

        $linea = $this->margen()['lineas'][0];

        self::assertSame($this->moneda, $linea['moneda']);
        self::assertSame(15000.0, $linea['ingreso']);
        self::assertSame(700.0, $linea['creadores']);
        self::assertSame(1200.0, $linea['gasto']);
        // 15000 − 700 − 1200 = 13100. El número se conoce de antemano.
        self::assertSame(13100.0, $linea['margen']);
    }

    public function test_un_gasto_anulado_no_baja_el_margen(): void
    {
        $campanaId = $this->campanaConMargen(15000.0, 700.0);
        $gastoId = $this->gasto($campanaId, 1200.0);

        Costos::anular($gastoId, 'El proveedor devolvió el importe completo',
            (int) $this->usuarioCon('admin')->id);

        self::assertSame(14300.0, $this->margen()['lineas'][0]['margen']);
    }

    public function test_una_campana_en_borrador_no_entra(): void
    {
        // Un borrador no compromete nada: su `revenue_amount` es una intención.
        // Sumarlo infla el ingreso sin que nadie lo vea.
        $this->campanaConMargen(15000.0, 0.0, ['status' => 'draft', 'revenue_amount' => 0]);

        self::assertSame([], $this->margen()['lineas']);
    }

    public function test_una_campana_fuera_del_periodo_no_entra(): void
    {
        $this->campanaConMargen(15000.0, 0.0, [
            'starts_on' => CarbonImmutable::now()->subDays(200)->toDateString(),
            'ends_on' => CarbonImmutable::now()->subDays(120)->toDateString(),
        ]);

        self::assertSame([], $this->margen()['lineas']);
    }

    // ------------------------------------------------------ lo que queda fuera

    public function test_un_canje_no_entra_en_el_total_y_se_cuenta(): void
    {
        $this->campanaConMargen(15000.0, 700.0);
        $canjeId = $this->campanaConMargen(0.0, 0.0, ['is_gratis' => 1, 'revenue_amount' => 0]);
        $this->gasto($canjeId, 300.0);

        $margen = $this->margen();

        // Su ingreso es cero POR DECISIÓN (`DEC-184`): su margen siempre sale
        // negativo y hundiría la cifra por un motivo deliberado.
        self::assertSame(15000.0, $margen['lineas'][0]['ingreso']);
        self::assertSame(1, $margen['fuera']);
    }

    public function test_una_campana_con_gastos_en_otra_moneda_queda_fuera(): void
    {
        $campanaId = $this->campanaConMargen(15000.0, 700.0);
        $this->gasto($campanaId, 50.0, $this->otraMoneda);

        $margen = $this->margen();

        // Su margen en la moneda de la campaña estaría INCOMPLETO, y sumar un
        // número incompleto lo vuelve invisible.
        self::assertSame([], $margen['lineas']);
        self::assertSame(1, $margen['fuera']);
    }

    public function test_con_algo_fuera_no_hay_porcentaje_y_se_explica(): void
    {
        $this->campanaConMargen(15000.0, 700.0);
        $canjeId = $this->campanaConMargen(0.0, 0.0, ['is_gratis' => 1, 'revenue_amount' => 0]);
        $this->gasto($canjeId, 300.0);

        $margen = $this->margen();

        self::assertNull($margen['porcentaje']);
        self::assertNotNull($margen['veto']);
        self::assertStringContainsString('fuera del total', (string) $margen['veto']);
    }

    public function test_sin_nada_fuera_si_hay_porcentaje(): void
    {
        $campanaId = $this->campanaConMargen(1000.0, 0.0);
        $this->gasto($campanaId, 250.0);

        $margen = $this->margen();

        // 750 sobre 1000 = 75,0 %.
        self::assertNull($margen['veto']);
        self::assertSame(75.0, $margen['porcentaje']);
    }

    // ------------------------------------------------------- el consolidado

    public function test_el_consolidado_convierte_ingreso_y_costos_con_lados_distintos(): void
    {
        $hoy = CarbonImmutable::now()->toDateString();
        Cambio::anotar('USD', 'PEN', $hoy, '3.50000000', 'sunat', Cambio::COMPRA);
        Cambio::anotar('USD', 'PEN', $hoy, '4.00000000', 'sunat', Cambio::VENTA);

        // Una campaña en PEN y otra en USD, cada una completa en lo suyo.
        $enSoles = $this->campanaConMargen(1000.0, 0.0);
        $this->gasto($enSoles, 100.0);

        $enDolares = $this->campanaConMargen(200.0, 0.0, ['currency_code' => $this->otraMoneda]);
        $this->gasto($enDolares, 50.0, $this->otraMoneda);

        $consolidado = $this->margen()['consolidado'];

        // Ingreso: 1000 + (200 × 3,50 compra) = 1700.
        // Costos:   100 + ( 50 × 4,00 venta)  =  300.
        // Margen:  1700 − 300 = 1400.
        //
        // Con un solo lado para las dos direcciones el número sería otro, y
        // parecería igual de correcto.
        self::assertSame(1700.0, $consolidado['ingreso']);
        self::assertSame(300.0, $consolidado['costos']);
        self::assertSame(1400.0, $consolidado['margen']);
        self::assertFalse($consolidado['parcial']);
    }

    public function test_una_moneda_sin_tasa_deja_el_consolidado_parcial_y_sin_porcentaje(): void
    {
        $enSoles = $this->campanaConMargen(1000.0, 0.0);
        $this->gasto($enSoles, 100.0);

        // USD sin tasa anotada hoy: hay fuente declarada pero nadie publicó
        // ninguna tasa en esta prueba, así que no se puede convertir.
        $this->campanaConMargen(200.0, 0.0, ['currency_code' => $this->otraMoneda]);

        $margen = $this->margen();

        self::assertTrue($margen['consolidado']['parcial']);
        self::assertNull($margen['porcentaje']);
        self::assertStringContainsString('incompleto', (string) $margen['veto']);
        // Y las dos líneas por moneda siguen ahí: lo que no se puede convertir
        // no desaparece de la pantalla, sólo del total.
        self::assertCount(2, $margen['lineas']);
    }

    // -------------------------------------------------- las dos definiciones

    /**
     * **La que impide que las dos restas se separen.**
     *
     * `Core` no puede llamar a `Finance`, así que la misma resta está escrita en
     * dos sitios. Esta prueba es lo único que garantiza que digan lo mismo.
     */
    public function test_el_panel_y_rentabilidad_dan_el_mismo_numero(): void
    {
        $primera = $this->campanaConMargen(15000.0, 700.0);
        $this->gasto($primera, 1200.0);

        $segunda = $this->campanaConMargen(8000.0, 500.0);
        $this->gasto($segunda, 300.0);

        $delPanel = $this->margen()['lineas'][0];
        $deRentabilidad = Rentabilidad::listado()[$this->moneda]['total'];

        self::assertSame($deRentabilidad['ingreso'], $delPanel['ingreso']);
        self::assertSame($deRentabilidad['creadores'], $delPanel['creadores']);
        self::assertSame($deRentabilidad['gasto'], $delPanel['gasto']);
        self::assertSame($deRentabilidad['margen'], $delPanel['margen']);
    }

    // ------------------------------------------------------------ el permiso

    public function test_quien_lleva_campanas_no_recibe_el_bloque(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');

        // La premisa, afirmada aquí y no cuatro líneas abajo (`DEC-181`).
        self::assertFalse($gestor->can('campaign.view_margin'));

        $html = (string) $this->actingAs($gestor)->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringNotContainsString('Margen del periodo', $html);
    }

    public function test_finanzas_si_lo_recibe(): void
    {
        $campanaId = $this->campanaConMargen(1000.0, 0.0);
        $this->gasto($campanaId, 250.0);

        $finanzas = $this->usuarioCon('finance');

        self::assertTrue($finanzas->can('campaign.view_margin'));

        $html = (string) $this->actingAs($finanzas)->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringContainsString('Margen del periodo', $html);
        self::assertStringContainsString('750,00', $html);
    }

    public function test_el_bloque_no_consulta_nada_cuando_no_hay_permiso(): void
    {
        $consultas = 0;

        DB::listen(static function ($consulta) use (&$consultas): void {
            if (str_contains($consulta->sql, 'campaign_costs')) {
                $consultas++;
            }
        });

        $this->actingAs($this->usuarioCon('campaign_manager'))->get('/backoffice/panel')->assertOk();

        self::assertSame(0, $consultas, 'Sin permiso, el gasto de campaña no se consulta siquiera.');
    }

    // ---------------------------------------------------------------- apoyo

    /** @return array<string, mixed> */
    private function margen(): array
    {
        return Resumen::margen(FiltrosDeResumen::porDefecto());
    }

    /**
     * Una campaña confirmada dentro del periodo, con su devengo si se pide.
     *
     * @param array<string, mixed> $cambios
     */
    private function campanaConMargen(float $ingreso, float $pactado, array $cambios = []): int
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId, array_merge([
            'status' => 'in_progress',
            // Explícita: `campanaDe` la tomaría del catálogo, y eso es ARS.
            'currency_code' => $this->moneda,
            'revenue_amount' => $ingreso,
            'creator_budget_amount' => 100000,
            'billing_legal_entity_id' => $this->sociedadId,
            'starts_on' => CarbonImmutable::now()->subDays(5)->toDateString(),
            'ends_on' => CarbonImmutable::now()->addDays(20)->toDateString(),
        ], $cambios));

        $this->mercadoDe($campanaId, $this->paisPE);

        if ($pactado > 0.0) {
            $participacionId = $this->participacionDe($campanaId, null, ['agreed_amount' => $pactado]);
            Ledger::devengar($participacionId);
        }

        return $campanaId;
    }

    private function gasto(int $campanaId, float $importe, ?string $moneda = null): int
    {
        return Costos::anotar(
            $campanaId, 'product', 'Producto de la campaña', $importe,
            $moneda ?? $this->moneda, now()->toDateString(), null,
            (int) $this->usuarioCon('admin')->id,
        );
    }
}
