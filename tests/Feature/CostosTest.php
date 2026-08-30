<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Campaign\Services\Invitaciones;
use App\Modules\Campaign\Services\ListaCorta;
use App\Modules\Finance\Services\Costos;
use App\Modules\Finance\Services\Ledger;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El gasto de una campaña (iteración 9.10a).
 *
 * `campaign_costs` llevaba desde la Fase 2 en el esquema con **cero filas**.
 * Mientras siguiera vacía, cualquier cuenta de rentabilidad restaba sólo lo que
 * se le paga a los creadores y llamaba «margen» al resto — un número que sale
 * siempre más alto de lo que es.
 *
 * Lo que estas pruebas fijan:
 *
 * 1. Un costo **se anula, no se reescribe ni se borra**, porque el margen de
 *    ayer tiene que poder reconstruirse.
 * 2. **Cada moneda por su lado.** Sumarlas exige una tasa, y cuál se aplica
 *    sigue siendo una decisión contable abierta (`Q-63`).
 * 3. Quien lleva una campaña **carga gastos y no ve el margen** (`DEC-181`).
 */
final class CostosTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $clienteId;

    private int $marcaId;

    private string $moneda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Queue::fake();

        $this->moneda = (string) DB::table('currencies')->value('code');

        $this->clienteId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'commercial_name' => 'ACME', 'client_code' => 'ACME-01',
            'country_id' => (int) DB::table('countries')->where('iso2', 'PE')->value('id'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->marcaId = (int) DB::table('client_brands')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_organization_id' => $this->clienteId,
            'name' => 'Marca ACME', 'slug' => 'marca-acme', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------- lo normal

    public function test_anotar_un_gasto_lo_deja_en_el_resumen(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId);
        $autor = $this->usuarioCon('campaign_manager');

        Costos::anotar($campanaId, 'product', 'Zapatillas para el sorteo', 1200.50,
            $this->moneda, now()->toDateString(), null, (int) $autor->id);

        $resumen = Costos::resumen($campanaId);

        $this->assertSame(1200.50, $resumen[$this->moneda]['total']);
        $this->assertSame(1200.50, $resumen[$this->moneda]['tipos']['product']);
    }

    public function test_un_tipo_desconocido_no_llega_a_la_base(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tipo de costo desconocido');

        Costos::anotar($campanaId, 'catering', 'Almuerzo', 50.0, $this->moneda,
            now()->toDateString(), null, (int) $this->usuarioCon('admin')->id);
    }

    /**
     * **Deliberado.** El producto se compra antes de confirmar la campaña, y una
     * campaña cancelada puede tener gastos de verdad: no poder anotarlos dejaría
     * la pérdida sin registrar, que es justo el caso en que interesa.
     */
    public function test_una_campana_en_borrador_admite_gastos(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId, ['status' => 'draft']);

        Costos::anotar($campanaId, 'shipping', 'Envío al creador', 40.0, $this->moneda,
            now()->toDateString(), null, (int) $this->usuarioCon('admin')->id);

        $this->assertSame(40.0, Costos::resumen($campanaId)[$this->moneda]['total']);
    }

    public function test_una_campana_cancelada_tambien(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId, ['status' => 'cancelled']);

        Costos::anotar($campanaId, 'production', 'Sesión de fotos ya pagada', 900.0,
            $this->moneda, now()->toDateString(), null, (int) $this->usuarioCon('admin')->id);

        $this->assertSame(900.0, Costos::resumen($campanaId)[$this->moneda]['total']);
    }

    // ------------------------------------------------------- las monedas

    /**
     * **La que importa de esta iteración.** Dos monedas no se suman.
     *
     * Sumarlas exige un tipo de cambio, y cuál —compra, venta o media— es una
     * decisión contable abierta (`Q-63`) que da tres márgenes distintos para los
     * mismos hechos. Es la misma decisión que el saldo del creador en `9.8`.
     */
    public function test_dos_monedas_no_se_suman(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId);
        $otra = (string) DB::table('currencies')->where('code', '<>', $this->moneda)->value('code');
        $autor = (int) $this->usuarioCon('admin')->id;

        Costos::anotar($campanaId, 'product', 'Producto local', 100.0, $this->moneda,
            now()->toDateString(), null, $autor);
        Costos::anotar($campanaId, 'shipping', 'Courier internacional', 30.0, $otra,
            now()->toDateString(), null, $autor);

        $resumen = Costos::resumen($campanaId);

        $this->assertCount(2, $resumen);
        $this->assertSame(100.0, $resumen[$this->moneda]['total']);
        $this->assertSame(30.0, $resumen[$otra]['total']);
    }

    // ------------------------------------------------------- la anulación

    public function test_anular_saca_el_gasto_del_resumen_y_lo_deja_en_la_lista(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId);
        $autor = (int) $this->usuarioCon('admin')->id;

        Costos::anotar($campanaId, 'product', 'Cargado a la campaña equivocada', 500.0,
            $this->moneda, now()->toDateString(), null, $autor);
        $costoId = (int) DB::table('campaign_costs')->where('campaign_id', $campanaId)->value('id');

        Costos::anular($costoId, 'Era de la otra campaña.', $autor);

        $this->assertSame([], Costos::resumen($campanaId));
        // Sigue en la lista: quien mira una cifra que no le cuadra necesita ver
        // que hubo una corrección.
        $this->assertCount(1, Costos::deUnaCampana($campanaId));
        $this->assertSame('Era de la otra campaña.',
            (string) DB::table('campaign_costs')->where('id', $costoId)->value('voided_reason'));
    }

    public function test_anular_sin_motivo_no_se_puede(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId);
        $autor = (int) $this->usuarioCon('admin')->id;

        Costos::anotar($campanaId, 'other', 'Un gasto', 10.0, $this->moneda,
            now()->toDateString(), null, $autor);
        $costoId = (int) DB::table('campaign_costs')->where('campaign_id', $campanaId)->value('id');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exige decir por que');

        Costos::anular($costoId, '   ', $autor);
    }

    public function test_un_gasto_anulado_no_se_anula_dos_veces(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId);
        $autor = (int) $this->usuarioCon('admin')->id;

        Costos::anotar($campanaId, 'other', 'Un gasto', 10.0, $this->moneda,
            now()->toDateString(), null, $autor);
        $costoId = (int) DB::table('campaign_costs')->where('campaign_id', $campanaId)->value('id');
        Costos::anular($costoId, 'Estaba duplicado.', $autor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya estaba anulado');

        Costos::anular($costoId, 'Otra vez.', $autor);
    }

    /** El margen de ayer tiene que poder reconstruirse: `tg_cco_inmutable`. */
    public function test_la_base_no_deja_reescribir_un_costo(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId);
        Costos::anotar($campanaId, 'product', 'Producto', 4000.0, $this->moneda,
            now()->toDateString(), null, (int) $this->usuarioCon('admin')->id);
        $costoId = (int) DB::table('campaign_costs')->where('campaign_id', $campanaId)->value('id');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no se reescribe');

        // fixture-invalido-a-proposito: la prueba afirma que la base lo rechaza.
        DB::table('campaign_costs')->where('id', $costoId)->update(['amount' => 400]);
    }

    /** Y `tg_cco_fecha`: un gasto no se incurre en el futuro. */
    public function test_la_base_no_deja_un_gasto_del_futuro(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no se incurre en el futuro');

        // fixture-invalido-a-proposito: la prueba afirma que la base lo rechaza.
        Costos::anotar($campanaId, 'product', 'Producto de 2027', 100.0, $this->moneda,
            now()->addMonth()->toDateString(), null, (int) $this->usuarioCon('admin')->id);
    }

    // ------------------------------------------------- lo de los creadores

    /**
     * **La decisión de esta iteración.** El costo del creador entra al
     * DEVENGARSE, no al pagarse.
     *
     * La deuda existe desde que acepta (`9.4`) y su importe está congelado desde
     * `7.5`. Esperar al pago haría que una campaña terminada en marzo pareciera
     * rentabilísima hasta el lote de abril.
     *
     * Y **no** es `Compromiso::comprometido()`, que cuenta también a los
     * `shortlisted`: allí es correcto —su trabajo es proteger el presupuesto
     * antes de invitar— y aquí contaría dinero de gente que puede decir que no.
     */
    public function test_el_creador_cuenta_desde_que_acepta_y_no_antes(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'recruiting', 'revenue_amount' => 15000, 'creator_budget_amount' => 5000,
            'billing_legal_entity_id' => $this->entidadLegal(),
            'starts_on' => now()->addDays(10)->toDateString(),
            'ends_on' => now()->addMonths(2)->toDateString(),
        ]);
        $this->mercadoDe($campanaId, (int) DB::table('countries')->where('iso2', 'PE')->value('id'),
            ['target_creators' => null]);
        $this->requisitoDe($campanaId, ['quantity' => 1]);

        $campana = DB::table('campaigns')->where('id', $campanaId)->first();
        $creadorId = $this->creadorActivo();
        ListaCorta::anadir($campana, $creadorId);

        $participacionId = (int) DB::table('campaign_creators')
            ->where('campaign_id', $campanaId)->where('creator_id', $creadorId)->value('id');
        DB::table('campaign_creators')->where('id', $participacionId)
            ->update(['agreed_amount' => 700]);

        // En lista corta con importe puesto: `Compromiso` ya lo cuenta y el
        // costo todavia no, porque el creador aun puede decir que no.
        $this->assertSame([], Costos::creadoresPorMoneda($campanaId));

        $token = Invitaciones::invitar(
            $campana,
            DB::table('campaign_creators')->where('id', $participacionId)->first(),
            (int) $this->usuarioCon('admin')->id,
        );
        Invitaciones::aceptar($token, '203.0.113.9');

        $this->assertSame([$this->moneda => 700.0], Costos::creadoresPorMoneda($campanaId));
    }

    /** Un devengo anulado ya no se debe, y deja de costar. */
    public function test_un_devengo_anulado_deja_de_contar(): void
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'recruiting', 'revenue_amount' => 15000, 'creator_budget_amount' => 5000,
            'billing_legal_entity_id' => $this->entidadLegal(),
            'starts_on' => now()->addDays(10)->toDateString(),
            'ends_on' => now()->addMonths(2)->toDateString(),
        ]);
        $this->mercadoDe($campanaId, (int) DB::table('countries')->where('iso2', 'PE')->value('id'),
            ['target_creators' => null]);
        $this->requisitoDe($campanaId, ['quantity' => 1]);

        $campana = DB::table('campaigns')->where('id', $campanaId)->first();
        $creadorId = $this->creadorActivo();
        ListaCorta::anadir($campana, $creadorId);
        $participacionId = (int) DB::table('campaign_creators')
            ->where('campaign_id', $campanaId)->where('creator_id', $creadorId)->value('id');
        DB::table('campaign_creators')->where('id', $participacionId)
            ->update(['agreed_amount' => 700]);

        $token = Invitaciones::invitar(
            $campana,
            DB::table('campaign_creators')->where('id', $participacionId)->first(),
            (int) $this->usuarioCon('admin')->id,
        );
        Invitaciones::aceptar($token, '203.0.113.9');

        $asientoId = (int) DB::table('ledger_entries')
            ->where('campaign_creator_id', $participacionId)->value('id');
        Ledger::anular($asientoId, 'La campana se cayo antes de empezar.',
            (int) $this->usuarioCon('finance')->id);

        $this->assertSame([], Costos::creadoresPorMoneda($campanaId));
    }

    // ------------------------------------------------------- la pantalla

    public function test_quien_lleva_campanas_puede_cargar_gastos(): void
    {
        $campana = $this->campanaDe($this->clienteId, $this->marcaId);
        $uuid = (string) DB::table('campaigns')->where('id', $campana)->value('uuid');

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('costos.index', $uuid))
            ->assertOk()
            ->assertSee('Gastos de la campaña', false);
    }

    public function test_un_creador_no_entra_a_los_gastos(): void
    {
        $campana = $this->campanaDe($this->clienteId, $this->marcaId);
        $uuid = (string) DB::table('campaigns')->where('id', $campana)->value('uuid');

        $this->actingAs($this->usuarioCon('creator'))
            ->get(route('costos.index', $uuid))
            ->assertForbidden();
    }

    public function test_la_pantalla_de_gastos_no_ensena_el_ingreso(): void
    {
        $campana = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'recruiting', 'revenue_amount' => 18000,
            'billing_legal_entity_id' => $this->entidadLegal(),
        ]);
        $uuid = (string) DB::table('campaigns')->where('id', $campana)->value('uuid');

        $respuesta = $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('costos.index', $uuid))->assertOk();

        // Ver el costo no es ver el margen: sin el ingreso al lado, un total de
        // gasto no dice nada de la rentabilidad.
        $respuesta->assertDontSee('18,000');
        $respuesta->assertDontSee('18000');
    }

    /**
     * **`DEC-181`.** Quien lleva campañas ya no ve el margen.
     *
     * Y no basta con quitarlo de la matriz del seeder: `CimientosSeeder` usa
     * `updateOrInsert`, así que concede y no revoca nunca. La revocación vive en
     * la migración, que es lo único que corre en todas las bases.
     */
    public function test_quien_lleva_campanas_ya_no_ve_el_margen(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');

        $this->assertFalse(Permisos::tiene((int) $gestor->id, 'campaign.view_margin'));
        $this->assertTrue(Permisos::tiene((int) $gestor->id, 'finance.cost.manage'));
    }

    public function test_finanzas_sigue_viendo_el_margen(): void
    {
        $finanzas = $this->usuarioCon('finance');

        $this->assertTrue(Permisos::tiene((int) $finanzas->id, 'campaign.view_margin'));
        $this->assertTrue(Permisos::tiene((int) $finanzas->id, 'finance.cost.manage'));
    }
}
