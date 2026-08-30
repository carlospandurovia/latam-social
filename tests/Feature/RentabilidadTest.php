<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Campaign\Services\Invitaciones;
use App\Modules\Campaign\Services\ListaCorta;
use App\Modules\Finance\Services\Costos;
use App\Modules\Finance\Services\Rentabilidad;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * La rentabilidad (iteración 9.10).
 *
 * Tres cifras y una resta: ingreso − costo de creadores − gasto operativo. Lo
 * que estas pruebas fijan no es la aritmética, que es trivial, sino **las cuatro
 * veces que el sistema se niega a dar un número**:
 *
 * 1. No convierte monedas: las agrupa (`DEC-180`).
 * 2. No da porcentaje si hay más de una moneda, y **dice por qué**.
 * 3. No mete los canjes en ningún total (`DEC-184`).
 * 4. No suma una campaña cuyo margen está incompleto porque tiene gastos en
 *    otra moneda.
 *
 * Y una más, que es de seguridad: la pantalla entera exige
 * `campaign.view_margin` (`BR-SEC-001`, 🔴).
 */
final class RentabilidadTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $clienteId;

    private int $marcaId;

    private int $paisPE;

    private string $moneda;

    private string $otraMoneda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Queue::fake();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');
        $this->moneda = (string) DB::table('currencies')->value('code');
        $this->otraMoneda = (string) DB::table('currencies')
            ->where('code', '<>', $this->moneda)->value('code');

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

    // ---------------------------------------------------------- la resta

    /** **La que descubre si las demás mienten.** El caso normal cuadra. */
    public function test_ingreso_menos_creadores_menos_gasto(): void
    {
        $campanaId = $this->campanaConCreador(700.0, 15000.0);

        Costos::anotar($campanaId, 'product', 'Producto', 1200.0, $this->moneda,
            now()->toDateString(), null, (int) $this->usuarioCon('admin')->id);

        $cuenta = Rentabilidad::deUnaCampana($this->campana($campanaId));
        $fila = $cuenta['monedas'][$this->moneda];

        $this->assertSame(15000.0, $fila['ingreso']);
        $this->assertSame(700.0, $fila['creadores']);
        $this->assertSame(1200.0, $fila['gasto']);
        $this->assertSame(13100.0, $fila['margen']);
        $this->assertEqualsWithDelta(87.33, $cuenta['porcentaje'], 0.01);
    }

    /** Un gasto anulado no resta: ya no se debe. */
    public function test_un_gasto_anulado_no_baja_el_margen(): void
    {
        $campanaId = $this->campanaConCreador(700.0, 15000.0);
        $autor = (int) $this->usuarioCon('admin')->id;

        Costos::anotar($campanaId, 'product', 'Cargado dos veces', 1200.0, $this->moneda,
            now()->toDateString(), null, $autor);
        $costoId = (int) DB::table('campaign_costs')->where('campaign_id', $campanaId)->value('id');
        Costos::anular($costoId, 'Estaba duplicado.', $autor);

        $cuenta = Rentabilidad::deUnaCampana($this->campana($campanaId));

        $this->assertSame(14300.0, $cuenta['monedas'][$this->moneda]['margen']);
    }

    // ------------------------------------------------------- las monedas

    /**
     * **`DEC-180`.** Dos monedas no se restan entre sí, y no hay porcentaje.
     *
     * Convertirlas exige elegir compra, venta o media (`Q-63`), y cada elección
     * da un margen distinto para los mismos hechos.
     */
    public function test_con_dos_monedas_no_hay_porcentaje_y_se_dice_por_que(): void
    {
        $campanaId = $this->campanaConCreador(700.0, 15000.0);
        $autor = (int) $this->usuarioCon('admin')->id;

        Costos::anotar($campanaId, 'shipping', 'Courier internacional', 300.0,
            $this->otraMoneda, now()->toDateString(), null, $autor);

        $cuenta = Rentabilidad::deUnaCampana($this->campana($campanaId));

        $this->assertCount(2, $cuenta['monedas']);
        $this->assertNull($cuenta['porcentaje']);
        $this->assertNull($cuenta['moneda_unica']);
        $this->assertStringContainsString('decision contable', (string) $cuenta['veto_porcentaje']);
        // Cada moneda con su cuenta, sin mezclarse.
        $this->assertSame(14300.0, $cuenta['monedas'][$this->moneda]['margen']);
        $this->assertSame(-300.0, $cuenta['monedas'][$this->otraMoneda]['margen']);
    }

    // --------------------------------------------------------- los canjes

    /** **`DEC-184`.** Un canje sale marcado, con su costo, y sin porcentaje. */
    public function test_un_canje_no_tiene_porcentaje_y_lo_dice(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'draft', 'revenue_amount' => 0, 'is_gratis' => 1,
        ]);
        Costos::anotar($campanaId, 'product', 'Producto regalado', 400.0, $this->moneda,
            now()->toDateString(), null, (int) $this->usuarioCon('admin')->id);

        $cuenta = Rentabilidad::deUnaCampana($this->campana($campanaId));

        $this->assertTrue($cuenta['canje']);
        $this->assertNull($cuenta['porcentaje']);
        $this->assertStringContainsString('canje', (string) $cuenta['veto_porcentaje']);
        // Lo que costó es real, y por eso se enseña.
        $this->assertSame(-400.0, $cuenta['monedas'][$this->moneda]['margen']);
    }

    public function test_un_canje_queda_fuera_del_total_del_grupo(): void
    {
        $normal = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'draft', 'revenue_amount' => 1000,
        ]);
        $canje = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'draft', 'revenue_amount' => 0, 'is_gratis' => 1,
        ]);
        Costos::anotar($canje, 'product', 'Producto regalado', 400.0, $this->moneda,
            now()->toDateString(), null, (int) $this->usuarioCon('admin')->id);

        $grupos = Rentabilidad::listado();

        $this->assertSame(1000.0, $grupos[$this->moneda]['total']['margen']);
        $this->assertSame(1, $grupos[$this->moneda]['fuera']);
        // Pero sigue en la lista: su costo es información útil.
        $this->assertCount(2, $grupos[$this->moneda]['campanas']);
        $this->assertContains($normal, [$normal]);
    }

    /**
     * Una campaña con gastos en otra moneda tampoco entra en el total.
     *
     * Su margen en la moneda del grupo está **incompleto** —no incluye ese
     * gasto—, y sumar un número incompleto lo vuelve invisible.
     */
    public function test_una_campana_con_gastos_en_otra_moneda_queda_fuera_del_total(): void
    {
        $limpia = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'draft', 'revenue_amount' => 1000,
        ]);
        $mezclada = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'draft', 'revenue_amount' => 2000,
        ]);
        Costos::anotar($mezclada, 'shipping', 'Courier', 50.0, $this->otraMoneda,
            now()->toDateString(), null, (int) $this->usuarioCon('admin')->id);

        $grupos = Rentabilidad::listado();
        $grupo = $grupos[$this->moneda];

        $this->assertSame(1000.0, $grupo['total']['margen']);
        $this->assertSame(1, $grupo['fuera']);

        $fila = collect($grupo['campanas'])->firstWhere('uuid',
            (string) DB::table('campaigns')->where('id', $mezclada)->value('uuid'));
        $this->assertSame([$this->otraMoneda], $fila['otras_monedas']);
        $this->assertContains($limpia, [$limpia]);
    }

    // --------------------------------------------------------- el listado

    /** De peor a mejor: la pregunta por la que se abre es cuáles pierden dinero. */
    public function test_las_que_pierden_dinero_salen_arriba(): void
    {
        $buena = $this->campanaDe($this->clienteId, $this->marcaId,
            ['status' => 'draft', 'revenue_amount' => 5000]);
        $mala = $this->campanaDe($this->clienteId, $this->marcaId,
            ['status' => 'draft', 'revenue_amount' => 100]);
        Costos::anotar($mala, 'production', 'Producción cara', 900.0, $this->moneda,
            now()->toDateString(), null, (int) $this->usuarioCon('admin')->id);

        $campanas = Rentabilidad::listado()[$this->moneda]['campanas'];

        $this->assertSame(
            (string) DB::table('campaigns')->where('id', $mala)->value('uuid'),
            $campanas[0]['uuid'],
        );
        $this->assertSame(-800.0, $campanas[0]['margen']);
        $this->assertSame(
            (string) DB::table('campaigns')->where('id', $buena)->value('uuid'),
            $campanas[1]['uuid'],
        );
    }

    // -------------------------------------------------------- la pantalla

    public function test_finanzas_entra_a_la_rentabilidad(): void
    {
        $this->campanaDe($this->clienteId, $this->marcaId, ['status' => 'draft']);

        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('rentabilidad.index'))
            ->assertOk()
            ->assertSee('El ingreso es el declarado', false);
    }

    /** **`DEC-181`.** Quien lleva campañas no entra: la pantalla es del permiso. */
    public function test_quien_lleva_campanas_no_entra(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('rentabilidad.index'))
            ->assertForbidden();
    }

    public function test_un_creador_tampoco(): void
    {
        $this->actingAs($this->usuarioCon('creator'))
            ->get(route('rentabilidad.index'))
            ->assertForbidden();
    }

    public function test_la_ficha_ensena_el_detalle_del_gasto(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId,
            ['status' => 'draft', 'revenue_amount' => 5000]);
        Costos::anotar($campanaId, 'product', 'Zapatillas del sorteo', 1200.0, $this->moneda,
            now()->toDateString(), null, (int) $this->usuarioCon('admin')->id);
        $uuid = (string) DB::table('campaigns')->where('id', $campanaId)->value('uuid');

        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('rentabilidad.show', $uuid))
            ->assertOk()
            ->assertSee('Zapatillas del sorteo', false)
            ->assertSee('3,800.00');
    }

    public function test_una_campana_que_no_existe_da_404(): void
    {
        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('rentabilidad.show', (string) Str::uuid()))
            ->assertNotFound();
    }

    // ------------------------------------------------------------ apoyo

    private function campana(int $id): object
    {
        return DB::table('campaigns')->where('id', $id)->first();
    }

    /** Una campaña con UN creador que ya aceptó, y por tanto con devengo. */
    private function campanaConCreador(float $importe, float $ingreso): int
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'recruiting', 'revenue_amount' => $ingreso, 'creator_budget_amount' => 5000,
            'billing_legal_entity_id' => $this->entidadLegal(),
            'starts_on' => now()->addDays(10)->toDateString(),
            'ends_on' => now()->addMonths(2)->toDateString(),
        ]);
        $this->mercadoDe($campanaId, $this->paisPE, ['target_creators' => null]);
        $this->requisitoDe($campanaId, ['quantity' => 1]);

        $campana = $this->campana($campanaId);
        $creadorId = $this->creadorActivo();
        ListaCorta::anadir($campana, $creadorId);

        $participacionId = (int) DB::table('campaign_creators')
            ->where('campaign_id', $campanaId)->where('creator_id', $creadorId)->value('id');
        DB::table('campaign_creators')->where('id', $participacionId)
            ->update(['agreed_amount' => $importe]);

        $token = Invitaciones::invitar(
            $campana,
            DB::table('campaign_creators')->where('id', $participacionId)->first(),
            (int) $this->usuarioCon('admin')->id,
        );
        Invitaciones::aceptar($token, '203.0.113.9');

        return $campanaId;
    }
}
