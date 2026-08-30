<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Campaign\Services\Invitaciones;
use App\Modules\Campaign\Services\ListaCorta;
use App\Modules\Content\Services\Entregables;
use App\Modules\Content\Services\Revisiones;
use App\Modules\Finance\Services\Ledger;
use App\Modules\Finance\Services\Lotes;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Database\Seeders\PlantillasDeCorreoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Lotes de pago (iteración 9.6).
 *
 * Aquí sale el dinero, y lo que esta iteración existe para impedir son tres
 * cosas:
 *
 * 1. Que **pague la sociedad equivocada** — `BR-LE-009` y `DEC-157`, la deuda
 *    que el roadmap tenía aparcada en `9.11`.
 * 2. Que **firme quien armó** el lote (`BR-FIN-005`).
 * 3. Que se pague un devengo **dos veces**.
 */
final class LotesTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $paisPE;

    private int $clienteId;

    private int $marcaId;

    private int $entidadId;

    private string $moneda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        $this->seed(PlantillasDeCorreoSeeder::class);
        Permisos::olvidar();
        Queue::fake();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');
        $this->moneda = (string) DB::table('currencies')->value('code');
        $this->entidadId = $this->entidadLegal(['code' => 'CTS-PE']);

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

    // ------------------------------------------------------------ el lote

    /** **La que descubre si las demás mienten.** El caso normal funciona. */
    public function test_armar_firmar_y_ejecutar_un_lote(): void
    {
        [$asientoId] = $this->pagable(500.0);

        $armador = $this->usuarioCon('finance');
        $uuid = Lotes::armar($this->entidadId, $this->moneda, (int) $armador->id);
        $lote = DB::table('payout_batches')->where('uuid', $uuid)->first();

        $this->assertSame(Lotes::BORRADOR, $lote->status);
        $this->assertSame(1, DB::table('payouts')->where('payout_batch_id', $lote->id)->count());
        $this->assertSame('500.0000',
            (string) DB::table('payouts')->where('payout_batch_id', $lote->id)->value('amount'));

        // Otra persona firma. `ck_pbatch_segregation` lo exige en la base.
        $firmante = $this->usuarioCon('finance');
        Lotes::aprobar($lote, (int) $firmante->id);

        $lote = DB::table('payout_batches')->where('uuid', $uuid)->first();
        $this->assertSame(Lotes::APROBADO, $lote->status);

        $pagos = Lotes::ejecutar($lote, (int) $armador->id);

        $this->assertSame(1, $pagos);
        $this->assertSame(Ledger::PAGADO,
            DB::table('ledger_entries')->where('id', $asientoId)->value('status'));
        // `BR-FIN-013`: un asiento de pago por payout, negativo.
        $pago = DB::table('ledger_entries')->where('entry_type', Ledger::PAGO)->first();
        $this->assertSame('-500.0000', (string) $pago->amount);
        $this->assertNotNull($pago->payout_id);
    }

    /** El importe del pago **no se teclea**: sale de sumar lo que liquida. */
    public function test_el_importe_del_pago_es_la_suma_de_lo_que_liquida(): void
    {
        $this->pagable(300.0);
        $this->pagable(200.0);

        $uuid = Lotes::armar($this->entidadId, $this->moneda, (int) $this->usuarioCon('finance')->id);
        $loteId = (int) DB::table('payout_batches')->where('uuid', $uuid)->value('id');

        $this->assertSame('500.0000',
            (string) DB::table('payouts')->where('payout_batch_id', $loteId)->sum('amount'));
        $this->assertNull(Lotes::descuadre($loteId));
    }

    public function test_no_se_arma_un_lote_vacio(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Un lote vacio no es un lote');

        Lotes::armar($this->entidadId, $this->moneda, (int) $this->usuarioCon('finance')->id);
    }

    // ------------------------------------------------------- `BR-LE-009`

    /**
     * **La de `DEC-157`.** Un lote no paga el trabajo de otra sociedad.
     *
     * `campaigns.billing_legal_entity_id` decía quién factura y
     * `payout_batches.legal_entity_id` quién paga, y entre los dos no había
     * nada: un lote de CTS Colombia podía pagar una campaña de CTS Perú y
     * ninguna restricción lo notaba. Eso es una operación intercompañía sin
     * documentar.
     */
    public function test_un_lote_no_paga_el_trabajo_de_otra_sociedad(): void
    {
        [$asientoId] = $this->pagable(500.0);

        $otra = $this->entidadLegal(['code' => 'CTS-CO']);
        $loteId = (int) DB::table('payout_batches')->insertGetId([
            'uuid' => (string) Str::uuid(), 'code' => 'LOTE-OTRA',
            'legal_entity_id' => $otra, 'currency_code' => $this->moneda,
            'status' => Lotes::BORRADOR,
            'created_by_user_id' => (int) $this->usuarioCon('finance')->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pagoId = $this->pagoSuelto($loteId, $asientoId);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sociedad que paga tiene que ser la de la campana');

        // fixture-invalido-a-proposito: la prueba afirma que la base lo rechaza.
        DB::table('payout_earnings')->insert([
            'payout_id' => $pagoId, 'ledger_entry_id' => $asientoId,
            'amount' => 500, 'created_at' => now(),
        ]);
    }

    /** Y armando por sociedad, la de la otra ni aparece en la cola. */
    public function test_la_cola_de_pagables_es_por_sociedad(): void
    {
        $this->pagable(500.0);

        $otra = $this->entidadLegal(['code' => 'CTS-CO']);

        $this->assertCount(1, Lotes::loQueSePodriaPagar($this->entidadId, $this->moneda));
        $this->assertCount(0, Lotes::loQueSePodriaPagar($otra, $this->moneda));
    }

    /** Sólo se liquida un devengo **pagable**: uno devengado todavía no. */
    public function test_no_se_liquida_un_devengo_que_no_es_pagable(): void
    {
        [$asientoId] = $this->pagable(500.0);
        Ledger::retener($asientoId, 'Se cayo el post.', (int) $this->usuarioCon('finance')->id);

        $loteId = (int) DB::table('payout_batches')->insertGetId([
            'uuid' => (string) Str::uuid(), 'code' => 'LOTE-X',
            'legal_entity_id' => $this->entidadId, 'currency_code' => $this->moneda,
            'status' => Lotes::BORRADOR,
            'created_by_user_id' => (int) $this->usuarioCon('finance')->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pagoId = $this->pagoSuelto($loteId, $asientoId);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Solo se liquida un devengo pagable');

        // fixture-invalido-a-proposito: la prueba afirma que la base lo rechaza.
        DB::table('payout_earnings')->insert([
            'payout_id' => $pagoId, 'ledger_entry_id' => $asientoId,
            'amount' => 500, 'created_at' => now(),
        ]);
    }

    /** `uq_pe_viva`: un devengo no se liquida dos veces a la vez. */
    public function test_un_devengo_no_entra_en_dos_lotes(): void
    {
        $this->pagable(500.0);
        Lotes::armar($this->entidadId, $this->moneda, (int) $this->usuarioCon('finance')->id);

        // El segundo intento no encuentra nada: ya esta liquidado.
        $this->assertCount(0, Lotes::loQueSePodriaPagar($this->entidadId, $this->moneda));

        $this->expectException(RuntimeException::class);
        Lotes::armar($this->entidadId, $this->moneda, (int) $this->usuarioCon('finance')->id);
    }

    // ------------------------------------------------------ las dos firmas

    /** `BR-FIN-005`: quien arma no firma, y se dice **antes** de pulsar. */
    public function test_quien_arma_el_lote_no_lo_puede_firmar(): void
    {
        $this->pagable(500.0);
        $armador = $this->usuarioCon('finance');

        $uuid = Lotes::armar($this->entidadId, $this->moneda, (int) $armador->id);
        $lote = DB::table('payout_batches')->where('uuid', $uuid)->first();

        $veto = Lotes::vetoParaAprobar($lote, (int) $armador->id);

        $this->assertNotNull($veto);
        $this->assertStringContainsString('no puede aprobarlo', $veto);

        $this->expectException(RuntimeException::class);
        Lotes::aprobar($lote, (int) $armador->id);
    }

    /** Y ni por SQL: `ck_pbatch_segregation` lo impide en la base. */
    public function test_la_base_tampoco_deja_que_el_autor_firme(): void
    {
        $this->pagable(500.0);
        $armador = $this->usuarioCon('finance');
        $uuid = Lotes::armar($this->entidadId, $this->moneda, (int) $armador->id);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: la prueba afirma que la base lo rechaza.
        DB::table('payout_batches')->where('uuid', $uuid)->update([
            'status' => Lotes::APROBADO,
            'approved_by_user_id' => (int) $armador->id,
            'approved_at' => now(),
        ]);
    }

    public function test_un_lote_sin_firmar_no_se_ejecuta(): void
    {
        $this->pagable(500.0);
        $uuid = Lotes::armar($this->entidadId, $this->moneda, (int) $this->usuarioCon('finance')->id);
        $lote = DB::table('payout_batches')->where('uuid', $uuid)->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Solo se ejecuta un lote aprobado');

        Lotes::ejecutar($lote, (int) $this->usuarioCon('finance')->id);
    }

    // ------------------------------------------------- sacar del lote (9.6)

    /**
     * Un pago sale del lote y **el resto se paga igual**.
     *
     * El importe total baja, nunca sube, y eso no puede sorprender a quien ya
     * firmó. Devolver el lote entero a borrador castigaría a diez creadores por
     * el problema de uno.
     */
    public function test_sacar_un_pago_no_obliga_a_firmar_otra_vez(): void
    {
        [$primero] = $this->pagable(500.0);
        $this->pagable(300.0);

        $armador = $this->usuarioCon('finance');
        $uuid = Lotes::armar($this->entidadId, $this->moneda, (int) $armador->id);
        $lote = DB::table('payout_batches')->where('uuid', $uuid)->first();
        Lotes::aprobar($lote, (int) $this->usuarioCon('finance')->id);

        $pagoId = (int) DB::table('payout_earnings')->where('ledger_entry_id', $primero)->value('payout_id');

        Lotes::sacarDelLote($pagoId, 'Se cayo el post y se retuvo el devengo.', (int) $armador->id);

        $lote = DB::table('payout_batches')->where('uuid', $uuid)->first();
        $this->assertSame(Lotes::APROBADO, $lote->status, 'sigue firmado');

        $ejecutados = Lotes::ejecutar($lote, (int) $armador->id);

        $this->assertSame(1, $ejecutados, 'se paga el otro, no el sacado');
        $this->assertSame(Ledger::PAGABLE,
            DB::table('ledger_entries')->where('id', $primero)->value('status'),
            'el devengo sacado vuelve a la cola');
    }

    /** Y esa liquidación **no se borra**: es evidencia de que estuvo dentro. */
    public function test_la_liquidacion_anulada_se_queda(): void
    {
        [$asientoId] = $this->pagable(500.0);
        $armador = $this->usuarioCon('finance');
        Lotes::armar($this->entidadId, $this->moneda, (int) $armador->id);

        $pagoId = (int) DB::table('payout_earnings')->where('ledger_entry_id', $asientoId)->value('payout_id');
        Lotes::sacarDelLote($pagoId, 'Se cayo el post y se retuvo el devengo.', (int) $armador->id);

        $liquidacion = DB::table('payout_earnings')->where('ledger_entry_id', $asientoId)->first();

        $this->assertNotNull($liquidacion);
        $this->assertNotNull($liquidacion->voided_at);
        $this->assertSame((int) $armador->id, (int) $liquidacion->voided_by_user_id);
    }

    /** Sacarlo sin decir por qué no se puede, ni por SQL. */
    public function test_sacar_sin_motivo_no_se_puede(): void
    {
        [$asientoId] = $this->pagable(500.0);
        Lotes::armar($this->entidadId, $this->moneda, (int) $this->usuarioCon('finance')->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('exige quien lo saco y por que');

        DB::table('payout_earnings')->where('ledger_entry_id', $asientoId)
            ->update(['voided_at' => now()]);
    }

    /** Un lote ya ejecutado no se toca: eso se corrige con una devolución. */
    public function test_no_se_saca_un_pago_de_un_lote_ejecutado(): void
    {
        [$asientoId] = $this->pagable(500.0);
        $armador = $this->usuarioCon('finance');
        $uuid = Lotes::armar($this->entidadId, $this->moneda, (int) $armador->id);
        $lote = DB::table('payout_batches')->where('uuid', $uuid)->first();
        Lotes::aprobar($lote, (int) $this->usuarioCon('finance')->id);
        Lotes::ejecutar(DB::table('payout_batches')->where('uuid', $uuid)->first(), (int) $armador->id);

        $pagoId = (int) DB::table('payout_earnings')->where('ledger_entry_id', $asientoId)->value('payout_id');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('el dinero salio');

        Lotes::sacarDelLote($pagoId, 'Tarde.', (int) $armador->id);
    }

    // -------------------------------------------------------- la pantalla

    public function test_la_pantalla_exige_permiso(): void
    {
        $this->actingAs($this->usuarioCon('content_reviewer'))->get('/lotes')->assertForbidden();
        $this->actingAs($this->usuarioCon('finance'))->get('/lotes')->assertOk();
    }

    public function test_el_csv_lleva_lo_que_hace_falta_para_pagar(): void
    {
        $this->pagable(500.0);
        $uuid = Lotes::armar($this->entidadId, $this->moneda, (int) $this->usuarioCon('finance')->id);

        $respuesta = $this->actingAs($this->usuarioCon('finance'))->get("/lotes/{$uuid}/csv");

        $respuesta->assertOk();
        $respuesta->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('creador,beneficiario,cuenta,importe,moneda',
            (string) $respuesta->getContent());
    }

    // ------------------------------------------------------------- apoyo

    /**
     * Un devengo **pagable** de una campaña de `$this->entidadId`.
     *
     * @return array{0:int,1:int} [asiento, participacion]
     */
    private function pagable(float $importe): array
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'recruiting', 'revenue_amount' => 15000, 'creator_budget_amount' => 5000,
            'billing_legal_entity_id' => $this->entidadId,
            'starts_on' => now()->addDays(10)->toDateString(),
            'ends_on' => now()->addMonths(2)->toDateString(),
        ]);
        $this->mercadoDe($campanaId, $this->paisPE, ['target_creators' => null]);
        $this->requisitoDe($campanaId, ['quantity' => 1]);

        $creadorId = $this->creadorActivo();
        $campana = DB::table('campaigns')->where('id', $campanaId)->first();
        ListaCorta::anadir($campana, $creadorId);

        $id = (int) DB::table('campaign_creators')
            ->where('campaign_id', $campanaId)->where('creator_id', $creadorId)->value('id');
        DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => $importe]);

        $token = Invitaciones::invitar($campana, DB::table('campaign_creators')->where('id', $id)->first(),
            (int) $this->usuarioCon('admin')->id);
        Invitaciones::aceptar($token, '203.0.113.9');

        $gestor = $this->usuarioCon('campaign_manager');
        $fila = DB::table('deliverables')->where('campaign_creator_id', $id)->first();
        Entregables::entregar($fila, ['external_url' => 'https://a.example/'.$id], null, (int) $gestor->id, null);
        $entregable = Revisiones::entregable((string) $fila->uuid);
        Revisiones::emitir($entregable, Revisiones::ultimaVersion((int) $entregable->id), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $gestor->id, null);

        DB::table('campaign_creators')->where('id', $id)->update(['completed_at' => now()]);

        DB::table('creator_tax_profiles')->insert([
            'creator_id' => $creadorId, 'country_id' => $this->paisPE,
            'tax_regime_code' => 'RER', 'tax_id_type' => 'RUC',
            'issued_document_type' => 'recibo_honorarios',
            'withholding_status' => 'not_applicable', 'withholding_rate' => 0,
            'status' => 'approved',
            'created_by_user_id' => (int) $this->usuarioCon('finance')->id,
            'approved_by_user_id' => (int) $this->usuarioCon('finance')->id,
            'approved_at' => now(), 'valid_from' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->medioDePago($creadorId, '0021234567890123'.random_int(1000, 9999), ['status' => 'verified']);

        $asientoId = (int) DB::table('ledger_entries')
            ->where('campaign_creator_id', $id)->where('entry_type', Ledger::DEVENGO)->value('id');

        $this->assertTrue(Ledger::revisarPagable($asientoId), 'la premisa: cumple las cinco');

        return [$asientoId, $id];
    }

    /** Un `payout` a mano, para poder probar el disparador sin pasar por el servicio. */
    private function pagoSuelto(int $loteId, int $asientoId): int
    {
        $creadorId = (int) DB::table('ledger_entries')->where('id', $asientoId)->value('creator_id');
        $medio = DB::table('creator_payment_methods')->where('creator_id', $creadorId)
            ->where('status', 'verified')->first(['id', 'holder_name', 'account_number_masked']);

        return (int) DB::table('payouts')->insertGetId([
            'uuid' => (string) Str::uuid(), 'payout_batch_id' => $loteId,
            'creator_id' => $creadorId, 'payment_method_id' => (int) $medio->id,
            'beneficiary_name_snapshot' => (string) $medio->holder_name,
            'account_masked_snapshot' => (string) $medio->account_number_masked,
            'amount' => 500, 'currency_code' => $this->moneda, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
