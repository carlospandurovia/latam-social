<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Campaign\Services\Invitaciones;
use App\Modules\Campaign\Services\ListaCorta;
use App\Modules\Content\Services\Entregables;
use App\Modules\Content\Services\Revisiones;
use App\Modules\Finance\Services\Ledger;
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
 * El libro mayor del creador (iteración 9.3).
 *
 * `ledger_entries` estaba en el esquema desde la Fase 2 con doce `CHECK` y sus
 * dos disparadores de inmutabilidad — **y cero filas**. Lo que esta iteración
 * existe para impedir son tres cosas:
 *
 * 1. Que un devengo se cree **dos veces** para la misma participación.
 * 2. Que un asiento **retroceda**: un pagado que vuelve a pendiente es dinero
 *    ya pagado que reaparece como deuda.
 * 3. Que un pago se pare **sin que nadie diga por qué**.
 */
final class LedgerTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $campanaId;

    private int $paisPE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        $this->seed(PlantillasDeCorreoSeeder::class);
        Permisos::olvidar();
        Queue::fake();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');

        $clienteId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'commercial_name' => 'ACME', 'client_code' => 'ACME-01',
            'country_id' => $this->paisPE, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $marcaId = (int) DB::table('client_brands')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_organization_id' => $clienteId,
            'name' => 'Marca ACME', 'slug' => 'marca-acme', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->campanaId = $this->campanaDe($clienteId, $marcaId, [
            'status' => 'recruiting', 'revenue_amount' => 15000, 'creator_budget_amount' => 5000,
            'starts_on' => now()->addDays(10)->toDateString(),
            'ends_on' => now()->addMonths(2)->toDateString(),
        ]);
        $this->mercadoDe($this->campanaId, $this->paisPE, ['target_creators' => null]);
    }

    // ---------------------------------------------------------- el devengo

    /** **La que descubre si las demás mienten.** El caso normal funciona. */
    public function test_aceptar_y_devengar_deja_un_asiento_en_la_moneda_pactada(): void
    {
        $id = $this->aceptado(500.0);

        $uuid = Ledger::devengar($id);

        $asiento = DB::table('ledger_entries')->where('uuid', $uuid)->first();

        $this->assertSame(Ledger::DEVENGO, $asiento->entry_type);
        $this->assertSame(Ledger::DEVENGADO, $asiento->status);
        $this->assertSame('500.0000', $asiento->amount);
        $this->assertSame(
            DB::table('campaign_creators')->where('id', $id)->value('currency_code'),
            $asiento->currency_code,
            'la moneda del asiento es la PACTADA, no la de la sociedad',
        );
        $this->assertNull($asiento->status_changed_at, 'nace devengado: no ha cambiado de estado');
    }

    /**
     * **`uq_ledger_devengo`.** Un devengo por participación, y lo dice la base.
     *
     * `BR-FIN-015` exigía que un devengo tuviera participación; no decía que
     * fuera **una**. Sin esta clave, dos llamadas —o `9.4` devengando por evento
     * mientras alguien lo hace a mano— le pagan dos veces la misma campaña.
     */
    public function test_no_se_devenga_dos_veces_la_misma_participacion(): void
    {
        $id = $this->aceptado(500.0);
        Ledger::devengar($id);

        $this->expectException(QueryException::class);
        Ledger::devengar($id);
    }

    /** Pero si el primero se anula, la participación vuelve a poder devengar. */
    public function test_anular_el_devengo_libera_el_sitio(): void
    {
        $id = $this->aceptado(500.0);
        $uuid = Ledger::devengar($id);
        $asientoId = (int) DB::table('ledger_entries')->where('uuid', $uuid)->value('id');

        Ledger::anular($asientoId, 'Se devengo sobre la participacion equivocada.',
            (int) $this->usuarioCon('finance')->id);

        $segundo = Ledger::devengar($id);

        $this->assertNotSame($uuid, $segundo);
        $this->assertSame(2, DB::table('ledger_entries')->where('campaign_creator_id', $id)->count());
    }

    public function test_no_se_devenga_lo_que_el_creador_no_acepto(): void
    {
        $id = $this->participacion(500.0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no acepto');

        Ledger::devengar($id);
    }

    /** Una colaboración gratuita es legítima, y no devenga: un cero no es un asiento. */
    /**
     * Una colaboración gratuita es legítima y **no devenga**: un cero no es un
     * asiento (`ck_ledger_amount`).
     *
     * El importe se pone a cero ANTES de aceptar: bajarlo después lo impide
     * `BR-CREATOR-008` —el monto acordado de una participación aceptada exige
     * una enmienda firmada por las dos partes— y esta prueba lo descubrió
     * intentándolo.
     */
    public function test_una_colaboracion_gratuita_no_devenga(): void
    {
        // La campana tiene que estar marcada como gratuita: invitar con importe
        // cero sin declararlo lo impide `BR-CREATOR-008` --«no se invita a un
        // creador sin decirle cuanto se le paga»--, y esa regla es de `7.5`.
        DB::table('campaigns')->where('id', $this->campanaId)
            ->update(['is_gratis' => 1, 'revenue_amount' => 0, 'creator_budget_amount' => 0]);

        $id = $this->aceptado(0.0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gratuita');

        Ledger::devengar($id);
    }

    // ------------------------------------------------ las cinco condiciones

    /** `BR-FIN-003`: se enseñan **las cinco**, no la primera que falla. */
    public function test_los_requisitos_se_contestan_todos_a_la_vez(): void
    {
        $id = $this->aceptado(500.0);

        $requisitos = Ledger::requisitos($id);

        $this->assertCount(5, $requisitos);
        $this->assertArrayHasKey('participacion', $requisitos);
        $this->assertArrayHasKey('fiscal', $requisitos);
        $this->assertArrayHasKey('medio_de_pago', $requisitos);
        // Recien aceptada no cumple ninguna de las que dependen de trabajo.
        $this->assertFalse($requisitos['participacion']['cumple']);
        $this->assertGreaterThanOrEqual(2, count(Ledger::loQueFalta($id)));
    }

    /** Un perfil fiscal con la retención sin decidir NO habilita el pago (`DEC-048`). */
    public function test_un_perfil_fiscal_sin_decidir_la_retencion_no_cumple(): void
    {
        $id = $this->aceptado(500.0);
        $creadorId = (int) DB::table('campaign_creators')->where('id', $id)->value('creator_id');

        // Un perfil PENDIENTE, que es lo que hay antes de que finanzas lo mire.
        // No se puede aprobar uno con la retencion sin decidir --lo impide
        // `ck_ctp_withholding_decided` desde `3.6`-- asi que el caso real es
        // este: existe, y todavia no vale.
        DB::table('creator_tax_profiles')->insert([
            'creator_id' => $creadorId, 'country_id' => $this->paisPE,
            'tax_regime_code' => 'RER', 'tax_id_type' => 'RUC',
            'issued_document_type' => 'recibo_honorarios',
            'withholding_status' => 'pending_review', 'withholding_rate' => 0,
            'status' => 'pending', 'valid_from' => '2026-01-01',
            'created_by_user_id' => (int) $this->usuarioCon('finance')->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse(Ledger::requisitos($id)['fiscal']['cumple']);
        $this->assertStringContainsString('aprobado y vigente', Ledger::requisitos($id)['fiscal']['dice']);
    }

    /** Y con las cinco puestas, el barrido lo mueve solo. */
    public function test_con_las_cinco_el_barrido_lo_pasa_a_pagable(): void
    {
        [$id, $asientoId] = $this->devengoCompleto();

        $this->artisan('ledger:revisar')->assertSuccessful();

        $asiento = DB::table('ledger_entries')->where('id', $asientoId)->first();

        $this->assertSame(Ledger::PAGABLE, $asiento->status);
        $this->assertSame(Ledger::MOTIVO_AUTOMATICO, $asiento->status_reason);
        $this->assertNotNull($asiento->status_changed_at);
        $this->assertNull($asiento->status_changed_by_user_id, 'no lo movio una persona');
        $this->assertSame([], Ledger::loQueFalta($id));
    }

    /** Y correr el barrido dos veces no vuelve a moverlo ni revienta. */
    public function test_el_barrido_es_idempotente(): void
    {
        [, $asientoId] = $this->devengoCompleto();

        $this->artisan('ledger:revisar')->assertSuccessful();
        $cuando = DB::table('ledger_entries')->where('id', $asientoId)->value('status_changed_at');

        $this->artisan('ledger:revisar')->assertSuccessful();

        $this->assertSame($cuando, DB::table('ledger_entries')->where('id', $asientoId)->value('status_changed_at'));
    }

    /** Si falta una sola de las cinco, no se mueve. */
    public function test_sin_medio_de_pago_verificado_no_pasa_a_pagable(): void
    {
        [, $asientoId] = $this->devengoCompleto(conMedioDePago: false);

        $this->artisan('ledger:revisar')->assertSuccessful();

        $this->assertSame(Ledger::DEVENGADO,
            DB::table('ledger_entries')->where('id', $asientoId)->value('status'));
    }

    // ------------------------------------------------ la máquina de estados

    /**
     * **`tg_ledger_estado`.** Un pagado no vuelve.
     *
     * Es la misma forma que `tg_del_rondas` en `8.4`: un contador que podía
     * bajar. Aquí lo que baja es dinero ya pagado, que reaparecería como deuda.
     */
    public function test_un_asiento_pagado_no_vuelve_a_pendiente(): void
    {
        [, $asientoId] = $this->devengoCompleto();
        Ledger::revisarPagable($asientoId);

        DB::table('ledger_entries')->where('id', $asientoId)->update([
            'status' => Ledger::PAGADO, 'status_changed_at' => now(),
            'status_reason' => 'Pagado en el lote de prueba.',
        ]);

        $this->expectException(QueryException::class);

        DB::table('ledger_entries')->where('id', $asientoId)->update([
            'status' => Ledger::DEVENGADO, 'status_changed_at' => now(),
            'status_reason' => 'Vuelta atras a mano.',
        ]);
    }

    /** Ni un devengo salta directo a pagado sin pasar por pagable. */
    public function test_un_devengo_no_salta_a_pagado(): void
    {
        [, $asientoId] = $this->devengoCompleto();

        $this->expectException(QueryException::class);

        DB::table('ledger_entries')->where('id', $asientoId)->update([
            'status' => Ledger::PAGADO, 'status_changed_at' => now(),
            'status_reason' => 'Salto.',
        ]);
    }

    /** Y mover un asiento **sin decir por qué** no se puede, ni por SQL. */
    public function test_mover_un_asiento_sin_motivo_no_se_puede(): void
    {
        [, $asientoId] = $this->devengoCompleto();

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: la prueba afirma que la base lo rechaza.
        DB::table('ledger_entries')->where('id', $asientoId)->update([
            'status' => Ledger::RETENIDO, 'status_changed_at' => now(),
        ]);
    }

    /** Retener saca el asiento del saldo, y liberar lo devuelve. */
    public function test_retener_y_liberar_un_asiento(): void
    {
        [, $asientoId] = $this->devengoCompleto();
        $creadorId = (int) DB::table('ledger_entries')->where('id', $asientoId)->value('creator_id');
        Ledger::revisarPagable($asientoId);

        $finanzas = (int) $this->usuarioCon('finance')->id;

        $this->assertTrue(Ledger::retener($asientoId, 'El post se cayo antes de tiempo (8.8).', $finanzas));
        $this->assertSame(Ledger::RETENIDO,
            DB::table('ledger_entries')->where('id', $asientoId)->value('status'));
        $this->assertTrue(Ledger::saldo($creadorId)->isEmpty(), 'un asiento retenido no es saldo');

        $this->assertTrue(Ledger::liberar($asientoId, 'El creador repuso el post.', $finanzas));
        $this->assertSame('500.0000', (string) Ledger::saldo($creadorId)->first()->total);
    }

    /** Y de las dos queda rastro, con su motivo y su autor. */
    public function test_cada_movimiento_deja_su_rastro(): void
    {
        [, $asientoId] = $this->devengoCompleto();
        $finanzas = (int) $this->usuarioCon('finance')->id;

        Ledger::revisarPagable($asientoId);
        Ledger::retener($asientoId, 'El post se cayo antes de tiempo (8.8).', $finanzas);

        $rastro = DB::table('status_transitions')
            ->where('entity_type', 'ledger_entry')->where('entity_id', $asientoId)
            ->orderBy('id')->get();

        $this->assertCount(2, $rastro);
        $this->assertSame(Ledger::DEVENGADO, $rastro[0]->from_status);
        $this->assertNull($rastro[0]->actor_user_id, 'la automatica no tiene autor');
        $this->assertSame($finanzas, (int) $rastro[1]->actor_user_id);
        $this->assertStringContainsString('se cayo', (string) $rastro[1]->reason);
    }

    /** La bitácora guarda lo que decide una PERSONA, no el ruido del barrido. */
    public function test_la_bitacora_solo_recoge_lo_que_decide_una_persona(): void
    {
        [, $asientoId] = $this->devengoCompleto();

        Ledger::revisarPagable($asientoId);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ledger.status']);

        Ledger::retener($asientoId, 'Motivo humano.', (int) $this->usuarioCon('finance')->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ledger.status']);
    }

    // ----------------------------------------------------------- el saldo

    /** `BR-FIN-001`: el saldo es una **suma**, no una columna. */
    public function test_el_saldo_es_la_suma_de_los_asientos(): void
    {
        [, $asientoId] = $this->devengoCompleto();
        $creadorId = (int) DB::table('ledger_entries')->where('id', $asientoId)->value('creator_id');

        $this->assertFalse(
            in_array('balance', DB::getSchemaBuilder()->getColumnListing('creators'), true),
            'BR-FIN-001: `creators` no puede tener una columna de saldo',
        );

        $this->assertSame('500.0000', (string) Ledger::saldo($creadorId)->first()->total);
    }

    /** Un asiento anulado no cuenta. */
    public function test_un_asiento_anulado_no_suma(): void
    {
        [, $asientoId] = $this->devengoCompleto();
        $creadorId = (int) DB::table('ledger_entries')->where('id', $asientoId)->value('creator_id');

        Ledger::anular($asientoId, 'Devengo por error.', (int) $this->usuarioCon('finance')->id);

        $this->assertTrue(Ledger::saldo($creadorId)->isEmpty());
    }

    // --------------------------------------------------------------- apoyo

    /** @return array{0:int,1:int} [participacion, asiento] */
    private function devengoCompleto(bool $conMedioDePago = true): array
    {
        // Se pasa por los SERVICIOS de verdad y no se escribe el estado a mano:
        // un entregable `approved` exige `submitted_at` y su version aprobada
        // (`ck_del_submitted`, `ck_del_approved_version`), y fabricar esa fila a
        // mano es fabricar un estado que el sistema no produce --que es como un
        // fixture empieza a mentir--.
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);

        $id = $this->aceptado(500.0);
        $creadorId = (int) DB::table('campaign_creators')->where('id', $id)->value('creator_id');
        $gestor = $this->usuarioCon('campaign_manager');

        $fila = DB::table('deliverables')->where('campaign_creator_id', $id)
            ->orderBy('sequence_number')->first();

        Entregables::entregar($fila, ['external_url' => 'https://a.example/1'], null, (int) $gestor->id, null);

        $entregable = Revisiones::entregable((string) $fila->uuid);
        Revisiones::emitir($entregable, Revisiones::ultimaVersion((int) $entregable->id), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $gestor->id, null);

        DB::table('campaign_creators')->where('id', $id)->update(['completed_at' => now()]);

        $this->perfilFiscalAprobado($creadorId);

        if ($conMedioDePago) {
            $this->medioDePago($creadorId, '00212345678901234567', ['status' => 'verified']);
        }

        $uuid = Ledger::devengar($id);

        return [$id, (int) DB::table('ledger_entries')->where('uuid', $uuid)->value('id')];
    }

    /**
     * Un perfil fiscal aprobado y con la retención decidida.
     *
     * `creadorActivo()` **no** crea ninguno: aprobar un creador y aprobar su
     * perfil fiscal son dos firmas distintas desde `3.6`, y el segundo lo da
     * finanzas. Que este ayudante exista lo descubrió esta suite —el barrido
     * decía «no tiene perfil fiscal aprobado y vigente» y tenía razón—.
     */
    private function perfilFiscalAprobado(int $creadorId): int
    {
        return (int) DB::table('creator_tax_profiles')->insertGetId([
            'creator_id' => $creadorId,
            'country_id' => $this->paisPE,
            'tax_regime_code' => 'RER',
            'tax_id_type' => 'RUC',
            'tax_id_number' => (string) random_int(10000000000, 10999999999),
            'issued_document_type' => 'recibo_honorarios',
            'withholding_status' => 'not_applicable',
            'withholding_rate' => 0,
            'status' => 'approved',
            'created_by_user_id' => (int) $this->usuarioCon('finance')->id,
            'approved_by_user_id' => (int) $this->usuarioCon('finance')->id,
            'approved_at' => now(),
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function participacion(float $importe): int
    {
        $creadorId = $this->creadorActivo();
        $campana = DB::table('campaigns')->where('id', $this->campanaId)->first();
        ListaCorta::anadir($campana, $creadorId);

        $id = (int) DB::table('campaign_creators')
            ->where('campaign_id', $this->campanaId)->where('creator_id', $creadorId)->value('id');

        DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => $importe]);

        return $id;
    }

    private function aceptado(float $importe): int
    {
        $id = $this->participacion($importe);
        $campana = DB::table('campaigns')->where('id', $this->campanaId)->first();
        $fila = DB::table('campaign_creators')->where('id', $id)->first();

        $token = Invitaciones::invitar($campana, $fila, (int) $this->usuarioCon('admin')->id);
        Invitaciones::aceptar($token, '203.0.113.9');

        return $id;
    }
}
