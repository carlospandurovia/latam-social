<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Campaign\Services\Invitaciones;
use App\Modules\Campaign\Services\ListaCorta;
use App\Modules\Content\Services\Entregables;
use App\Modules\Content\Services\Revisiones;
use App\Shared\Auth\Permisos;
use App\Shared\Eventos\CorreoPedido;
use Database\Seeders\CimientosSeeder;
use Database\Seeders\PlantillasDeCorreoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * La revisión (iteración 8.3).
 *
 * ### Las cuatro decisiones que estas pruebas fijan
 *
 * | Decisión (2026-08-26) | Qué se afirma |
 * |---|---|
 * | Sólo las rondas del **cliente** cuentan | una corrección interna no gasta ninguna |
 * | Y son **por entregable** | dos piezas del mismo creador no comparten cupo |
 * | Pasarse exige **decidir y firmar** | y el permiso de quien lo autoriza |
 * | Aprobar es **su propio permiso** | quien revisa no cierra por defecto |
 */
final class RevisionTest extends TestCase
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
            'included_revision_rounds' => 2,
            'starts_on' => now()->addDays(10)->toDateString(),
            'ends_on' => now()->addMonths(2)->toDateString(),
        ]);
        $this->mercadoDe($this->campanaId, $this->paisPE, ['target_creators' => null]);
    }

    // ------------------------------------------------- el contador de rondas

    /**
     * El contador se fue de `campaign_creators` y hay UNA sola columna así.
     *
     * Se afirma sobre `information_schema` y no sobre el modelo: la migración
     * añade la columna nueva y quita la vieja, y una de las dos mitades podría
     * quedarse sin hacer sin que ninguna otra prueba se enterase.
     */
    public function test_el_contador_de_rondas_vive_en_deliverables(): void
    {
        $columnas = DB::table('information_schema.columns')
            ->whereRaw('table_schema = DATABASE()')
            ->where('column_name', 'revision_rounds_used')
            ->pluck('TABLE_NAME');

        $this->assertSame(['deliverables'], $columnas->all());
    }

    public function test_una_correccion_interna_no_gasta_ronda(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();

        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::CAMBIOS,
            'reviewer_side' => 'platform',
            'comments' => 'El logo se ve cortado en el segundo 4.',
        ], (int) $usuario->id, '203.0.113.9');

        $this->assertSame(0, (int) DB::table('deliverables')
            ->where('id', $entregable->id)->value('revision_rounds_used'));
    }

    public function test_una_correccion_del_cliente_si_gasta_ronda(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();

        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::CAMBIOS,
            'reviewer_side' => 'client',
            'comments' => 'Prefieren el plano abierto del primer envio.',
        ], (int) $usuario->id, null);

        $this->assertSame(1, (int) DB::table('deliverables')
            ->where('id', $entregable->id)->value('revision_rounds_used'));
    }

    public function test_aprobar_no_gasta_ronda_venga_de_quien_venga(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();

        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR,
            'reviewer_side' => 'client',
        ], (int) $usuario->id, null);

        $this->assertSame(0, (int) DB::table('deliverables')
            ->where('id', $entregable->id)->value('revision_rounds_used'));
    }

    /**
     * Dos piezas del mismo creador **no comparten** cupo de rondas.
     *
     * Es la prueba de la decisión: con el contador en `campaign_creators`, la
     * segunda pieza habría nacido con una ronda ya gastada.
     */
    public function test_las_rondas_son_de_cada_pieza_y_no_del_creador(): void
    {
        [$primero, $usuario, $participacionId] = $this->listoParaRevisar(['quantity' => 2]);

        Revisiones::emitir($primero, $this->version($primero), [
            'outcome' => Revisiones::CAMBIOS,
            'reviewer_side' => 'client',
            'comments' => 'Cambien la musica del primero.',
        ], (int) $usuario->id, null);

        $segundo = DB::table('deliverables')->where('campaign_creator_id', $participacionId)
            ->where('sequence_number', 2)->first();

        $this->assertSame(1, (int) DB::table('deliverables')->where('id', $primero->id)->value('revision_rounds_used'));
        $this->assertSame(0, (int) $segundo->revision_rounds_used, 'la segunda pieza tiene sus propias rondas');
    }

    /**
     * El contador de rondas se lee **con la fila bloqueada**.
     *
     * Esta prueba mira el SQL y no el resultado, a propósito, y por la misma
     * razón que su gemela de `8.1`: PHPUnit corre en una conexión y dentro de
     * una transacción, así que una carrera de verdad no se puede montar aquí —lo
     * explica `tools/pruebas/4.11-concurrencia.sh`—.
     *
     * Sin el `FOR UPDATE`, dos revisores sobre la misma pieza leen el mismo
     * contador y la segunda ronda del cliente **no se cuenta**: el cliente
     * consigue una corrección gratis y nadie se entera, porque el número que
     * queda es plausible.
     */
    public function test_el_contador_de_rondas_se_lee_con_la_fila_bloqueada(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();

        $consultas = [];
        DB::listen(static function ($evento) use (&$consultas): void {
            $consultas[] = strtolower((string) $evento->sql);
        });

        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'client',
            'comments' => 'Prefieren el plano abierto del primer envio.',
        ], (int) $usuario->id, null);

        $lecturas = array_values(array_filter(
            $consultas,
            static fn (string $sql): bool => str_contains($sql, '`revision_rounds_used`')
                && str_starts_with($sql, 'select'),
        ));

        $this->assertCount(1, $lecturas, 'el contador se lee una sola vez');
        $this->assertStringContainsString('for update', $lecturas[0]);
    }

    // ------------------------------------------------------ la ronda de más

    public function test_pasarse_de_rondas_exige_decir_que_se_hace_con_ella(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        $this->gastarRondas($entregable, 2);

        $motivos = Revisiones::vetoParaRevisar(
            Revisiones::entregable($entregable->uuid),
            ['outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'client', 'comments' => 'La tercera vuelta.'],
        );

        $this->assertCount(1, $motivos);
        $this->assertStringContainsString('ya las gasto', $motivos[0]);
    }

    public function test_con_la_decision_tomada_la_ronda_de_mas_pasa_y_queda_firmada(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        $this->gastarRondas($entregable, 2);
        $fresco = Revisiones::entregable($entregable->uuid);

        Revisiones::emitir($fresco, $this->version($entregable), [
            'outcome' => Revisiones::CAMBIOS,
            'reviewer_side' => 'client',
            'comments' => 'La tercera vuelta, la pide el cliente.',
            'billing_decision' => 'charge',
        ], (int) $usuario->id, null);

        $revision = DB::table('content_reviews')->where('over_included', 1)->first();

        $this->assertNotNull($revision);
        $this->assertSame('charge', $revision->billing_decision);
        $this->assertSame((int) $usuario->id, (int) $revision->authorized_by_user_id);
    }

    public function test_una_ronda_interna_nunca_se_pasa_de_las_incluidas(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        $this->gastarRondas($entregable, 2);

        // Nuestra, con las rondas del cliente agotadas: no consume ni se pasa.
        $motivos = Revisiones::vetoParaRevisar(
            Revisiones::entregable($entregable->uuid),
            ['outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'platform', 'comments' => 'Esta es nuestra.'],
        );

        $this->assertSame([], $motivos);
    }

    // ------------------------------------------ 8.4: el techo, en la base
    //
    // Las tres reglas de arriba las aplicaba `Revisiones` y NADA las respaldaba
    // en el esquema. Un `if` de un servicio sólo protege al que pasa por ese
    // servicio, y `8.5` escribe revisiones del cliente desde un enlace firmado.
    // Estas pruebas escriben a mano, saltándose el servicio a propósito.

    /**
     * Una revisión NUESTRA no puede gastarle una ronda al cliente.
     *
     * `DEC-133` vivía sólo en `consumeRonda()`. `ck_cvw_round` decía «consume o
     * es una corrección» y no decía **de quién**.
     */
    public function test_la_base_no_deja_que_una_ronda_nuestra_cuente_contra_el_precio(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `ck_cvw_round` la rechaza.
        DB::table('content_reviews')->insert([
            'uuid' => (string) Str::uuid(),
            'deliverable_version_id' => $this->version($entregable)->id,
            'reviewer_user_id' => $usuario->id,
            'reviewer_side' => 'platform',
            'outcome' => Revisiones::CAMBIOS,
            'comments' => 'Esta es nuestra y quiere gastar ronda.',
            'consumes_round' => 1,
            'over_included' => 0,
            'reviewed_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * Con las rondas agotadas, `over_included = 0` es mentira. Y es dinero:
     * `rondasCobrables()` cuenta esa columna para facturar.
     */
    public function test_la_base_no_deja_colar_una_ronda_de_mas_sin_declararla(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        $this->gastarRondas($entregable, 2);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_cvw_techo` la rechaza.
        DB::table('content_reviews')->insert([
            'uuid' => (string) Str::uuid(),
            'deliverable_version_id' => $this->version($entregable)->id,
            'reviewer_user_id' => $usuario->id,
            'reviewer_side' => 'client',
            'outcome' => Revisiones::CAMBIOS,
            'comments' => 'La tercera, y sin decirlo.',
            'consumes_round' => 1,
            'over_included' => 0,
            'reviewed_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** Y la mitad simétrica: no se cobra como extra lo que todavía entraba. */
    public function test_la_base_no_deja_cobrar_como_extra_una_ronda_incluida(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_cvw_techo` la rechaza. Quedan 2.
        DB::table('content_reviews')->insert([
            'uuid' => (string) Str::uuid(),
            'deliverable_version_id' => $this->version($entregable)->id,
            'reviewer_user_id' => $usuario->id,
            'reviewer_side' => 'client',
            'outcome' => Revisiones::CAMBIOS,
            'comments' => 'La primera, cobrada como si fuera la tercera.',
            'consumes_round' => 1,
            'over_included' => 1,
            'billing_decision' => 'charge',
            'authorized_by_user_id' => $usuario->id,
            'reviewed_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * El contador no baja.
     *
     * Es la mitad del daño que no tiene dueño: bajarlo no necesita firma de
     * nadie y devuelve al cliente rondas que ya gastó.
     */
    public function test_el_contador_de_rondas_no_puede_bajar(): void
    {
        [$entregable] = $this->listoParaRevisar();
        $this->gastarRondas($entregable, 2);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_del_rondas` lo rechaza.
        DB::table('deliverables')->where('id', $entregable->id)
            ->update(['revision_rounds_used' => 0]);
    }

    /**
     * Y sí puede subir de golpe, a propósito.
     *
     * La primera versión de `tg_del_rondas` exigía `+1` exacto y rompía siete
     * pruebas que ponían el contador a 2 para simular una pieza gastada — y una
     * importación desde otro sistema tendría el mismo problema. El daño no es
     * simétrico: subirlo hace que la siguiente corrección se cobre, y eso ya
     * exige firma y decisión de facturación, las dos auditadas.
     */
    public function test_pero_si_puede_subir_de_golpe(): void
    {
        [$entregable] = $this->listoParaRevisar();

        DB::table('deliverables')->where('id', $entregable->id)
            ->update(['revision_rounds_used' => 2]);

        $this->assertSame(2, (int) DB::table('deliverables')
            ->where('id', $entregable->id)->value('revision_rounds_used'));
    }

    public function test_la_ronda_cobrada_sale_en_los_cargos_pendientes(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        $this->gastarRondas($entregable, 2);

        Revisiones::emitir(Revisiones::entregable($entregable->uuid), $this->version($entregable), [
            'outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'client',
            'comments' => 'La tercera, y se cobra.', 'billing_decision' => 'charge',
        ], (int) $usuario->id, null);

        $this->assertCount(1, Revisiones::rondasCobrables($this->campanaId));
    }

    /** La absorbida NO es un cargo: es lo contrario de un cargo. */
    public function test_la_ronda_absorbida_no_sale_en_los_cargos(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        $this->gastarRondas($entregable, 2);

        Revisiones::emitir(Revisiones::entregable($entregable->uuid), $this->version($entregable), [
            'outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'client',
            'comments' => 'Esta la asumimos nosotros.', 'billing_decision' => 'absorb',
        ], (int) $usuario->id, null);

        $this->assertCount(0, Revisiones::rondasCobrables($this->campanaId));
    }

    // ------------------------------------------------------------- el veredicto

    public function test_pedir_cambios_sin_decir_cuales_no_pasa(): void
    {
        [$entregable] = $this->listoParaRevisar();

        $motivos = Revisiones::vetoParaRevisar($entregable, [
            'outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'platform', 'comments' => 'no',
        ]);

        $this->assertCount(1, $motivos);
        $this->assertStringContainsString('decir cuales', $motivos[0]);
    }

    public function test_aprobar_deja_el_entregable_aprobado_y_firmado(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();

        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        $fila = DB::table('deliverables')->where('id', $entregable->id)->first();

        $this->assertSame('approved', $fila->status);
        $this->assertNotNull($fila->approved_at);
        $this->assertSame((int) $usuario->id, (int) $fila->approved_by_user_id);
    }

    /** Pedir cambios devuelve la pieza al creador, no la cierra. */
    public function test_pedir_cambios_reabre_la_pieza_para_el_creador(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();

        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'platform',
            'comments' => 'El logo se ve cortado en el segundo 4.',
        ], (int) $usuario->id, null);

        $fila = DB::table('deliverables')->where('id', $entregable->id)->first();

        $this->assertSame('changes_requested', $fila->status);
        $this->assertContains($fila->status, Entregables::ABIERTOS);
    }

    public function test_un_entregable_ya_aprobado_no_admite_mas_veredictos(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        $motivos = Revisiones::vetoParaRevisar(Revisiones::entregable($entregable->uuid), [
            'outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'platform', 'comments' => 'Me lo he repensado.',
        ]);

        $this->assertCount(1, $motivos);
        $this->assertStringContainsString('ya no admite veredictos', $motivos[0]);
    }

    public function test_la_base_impide_revisar_una_version_que_ya_no_es_la_ultima(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        $primera = $this->version($entregable);
        Entregables::entregar($entregable, ['external_url' => 'https://a.example/2'], null, (int) $usuario->id, null);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: esta fila EXISTE para que
        // `tg_cvw_ultima_version` la rechace.
        DB::table('content_reviews')->insert([
            'uuid' => (string) Str::uuid(), 'deliverable_version_id' => $primera->id,
            'reviewer_user_id' => $usuario->id, 'reviewer_side' => 'platform',
            'outcome' => 'approved', 'reviewed_at' => now(), 'created_at' => now(),
        ]);
    }

    public function test_la_base_impide_editar_un_veredicto(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'platform',
            'comments' => 'El logo se ve cortado en el segundo 4.',
        ], (int) $usuario->id, null);

        $this->expectException(QueryException::class);

        DB::table('content_reviews')->update(['outcome' => 'approved']);
    }

    // ------------------------------------------------------------- el historial

    public function test_el_historial_guarda_las_dos_vueltas(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'platform',
            'comments' => 'El logo se ve cortado en el segundo 4.',
        ], (int) $usuario->id, null);

        // Hay que MOVER el reloj: sin esto los dos veredictos caen en el mismo
        // milisegundo y «el mas reciente» sale a suertes. Lo destapo esta prueba
        // y el arreglo de verdad esta en el servicio --`orderByDesc('rv.id')` de
        // desempate--; esto ademas hace que la prueba afirme lo que dice.
        $this->travel(2)->minutes();

        Entregables::entregar(
            DB::table('deliverables')->where('id', $entregable->id)->first(),
            ['external_url' => 'https://a.example/2'], null, (int) $usuario->id, null,
        );
        Revisiones::emitir(Revisiones::entregable($entregable->uuid), $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        $historial = Revisiones::historial((int) $entregable->id);

        $this->assertCount(2, $historial);
        $this->assertSame('approved', $historial->first()->outcome, 'el mas reciente primero');
        $this->assertSame(1, Revisiones::vueltasDe((int) $entregable->participacion_id));
    }

    // ------------------------------------------------- 8.2: el puntero y reabrir

    public function test_aprobar_deja_apuntada_la_version_aprobada(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        $version = $this->version($entregable);

        Revisiones::emitir($entregable, $version, [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        $this->assertSame((int) $version->id, (int) DB::table('deliverables')
            ->where('id', $entregable->id)->value('approved_version_id'));
        $this->assertSame((int) $version->id, (int) Revisiones::versionAprobada((int) $entregable->id)->id);
    }

    /** Se aprueba la SEGUNDA versión: el puntero tiene que decir eso y no «la primera». */
    public function test_el_puntero_senala_la_version_que_de_verdad_se_aprobo(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'platform',
            'comments' => 'El logo se ve cortado en el segundo 4.',
        ], (int) $usuario->id, null);
        Entregables::entregar(
            DB::table('deliverables')->where('id', $entregable->id)->first(),
            ['external_url' => 'https://a.example/2'], null, (int) $usuario->id, null,
        );
        $segunda = $this->version($entregable);

        Revisiones::emitir(Revisiones::entregable($entregable->uuid), $segunda, [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        $aprobada = Revisiones::versionAprobada((int) $entregable->id);

        $this->assertSame(2, (int) $aprobada->version_number);
    }

    /**
     * La clave ajena es COMPUESTA: el puntero no puede ser de otro entregable.
     *
     * Es donde está casi todo el valor de la restricción. Una clave simple
     * aceptaría esta fila sin pestañear —la versión existe— y el entregable de
     * uno quedaría apuntando a lo aprobado del otro.
     */
    public function test_la_base_impide_apuntar_a_la_version_de_otro_entregable(): void
    {
        [$primero, $usuario, $participacionId] = $this->listoParaRevisar(['quantity' => 2]);
        $segundo = DB::table('deliverables')->where('campaign_creator_id', $participacionId)
            ->where('sequence_number', 2)->first();
        Entregables::entregar($segundo, ['external_url' => 'https://a.example/b'], null, (int) $usuario->id, null);
        $ajena = Revisiones::ultimaVersion((int) $segundo->id);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: esta fila EXISTE para que
        // `fk_del_approved_version` la rechace.
        DB::table('deliverables')->where('id', $primero->id)->update([
            'status' => 'approved', 'approved_at' => now(),
            'approved_by_user_id' => $usuario->id,
            'approved_version_id' => $ajena->id,
        ]);
    }

    public function test_la_base_impide_aprobar_sin_decir_que_version(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `ck_del_approved_version` exige las dos.
        DB::table('deliverables')->where('id', $entregable->id)->update([
            'status' => 'approved', 'approved_at' => now(), 'approved_by_user_id' => $usuario->id,
        ]);
    }

    /**
     * La mitad que faltaba de `vetoParaEntregar()`: ahora está en la base.
     *
     * Ese veto vivía en el servicio desde `8.1` y nada lo respaldaba: un comando
     * o un import podían meter una versión encima de algo ya aprobado, y el
     * entregable pasaba a tener aprobado un contenido que nadie aprobó.
     */
    public function test_la_base_impide_entregar_sobre_un_entregable_aprobado(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_dv_entregable_abierto` la rechaza.
        DB::table('deliverable_versions')->insert([
            'uuid' => (string) Str::uuid(), 'deliverable_id' => $entregable->id,
            'version_number' => 99, 'external_url' => 'https://a.example/x',
            'submitted_at' => now(), 'created_at' => now(),
        ]);
    }

    public function test_reabrir_devuelve_la_pieza_a_la_cola_y_limpia_el_puntero(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        Revisiones::reabrir(
            Revisiones::entregable($entregable->uuid),
            'client_changed', null, (int) $usuario->id, '203.0.113.9',
        );

        $fila = DB::table('deliverables')->where('id', $entregable->id)->first();

        $this->assertSame('in_review', $fila->status);
        $this->assertNull($fila->approved_at);
        $this->assertNull($fila->approved_version_id);
        $this->assertCount(1, Revisiones::cola());
    }

    /** Reabrir NO deshace: la aprobación anterior se queda en el historial. */
    public function test_reabrir_no_borra_la_aprobacion_anterior(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);
        $this->travel(2)->minutes();

        Revisiones::reabrir(
            Revisiones::entregable($entregable->uuid),
            'approved_by_mistake', null, (int) $usuario->id, null,
        );

        $historial = Revisiones::historial((int) $entregable->id);

        $this->assertCount(2, $historial);
        $this->assertSame(Revisiones::REABRIR, $historial->first()->outcome);
        $this->assertSame('approved', $historial->last()->outcome);
    }

    public function test_reabierto_el_creador_puede_volver_a_entregar(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);
        Revisiones::reabrir(Revisiones::entregable($entregable->uuid),
            'brief_changed', null, (int) $usuario->id, null);

        $numero = Entregables::entregar(
            DB::table('deliverables')->where('id', $entregable->id)->first(),
            ['external_url' => 'https://a.example/2'], null, (int) $usuario->id, null,
        );

        $this->assertSame(2, $numero);
    }

    public function test_solo_se_reabre_lo_aprobado(): void
    {
        [$entregable] = $this->listoParaRevisar();

        $veto = Revisiones::vetoParaReabrir($entregable, 'client_changed', null);

        $this->assertNotNull($veto);
        $this->assertStringContainsString('solo se reabre lo aprobado', $veto);
    }

    /**
     * Un motivo que no está en la lista no pasa **por el servicio**.
     *
     * `ReabrirRequest` ya lo filtra con un `in:`, así que desde la pantalla no
     * llega — y por eso esta prueba llama al servicio directamente. El veto no
     * sobra: `reabrir()` lee `MOTIVOS_REAPERTURA[$motivo]` para componer el
     * texto, y con una clave inventada eso es un fatal, no un rechazo.
     */
    public function test_un_motivo_que_no_esta_en_la_lista_no_pasa(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        $veto = Revisiones::vetoParaReabrir(
            Revisiones::entregable($entregable->uuid), 'porque_si', null,
        );

        $this->assertNotNull($veto);
        $this->assertStringContainsString('motivos de la lista', $veto);
    }

    public function test_otro_motivo_sin_explicar_no_pasa(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);
        $aprobado = Revisiones::entregable($entregable->uuid);

        $this->assertNotNull(Revisiones::vetoParaReabrir($aprobado, 'other', 'corto'));
        $this->assertNull(Revisiones::vetoParaReabrir($aprobado, 'other', 'El claim de la campana cambio entero.'));
    }

    public function test_reabrir_no_gasta_ronda(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        Revisiones::reabrir(Revisiones::entregable($entregable->uuid),
            'client_changed', null, (int) $usuario->id, null);

        $this->assertSame(0, (int) DB::table('deliverables')
            ->where('id', $entregable->id)->value('revision_rounds_used'));
    }

    public function test_sin_permiso_de_reapertura_no_se_reabre(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);
        $soloRevisa = $this->usuarioConPermisos(['content.review']);

        $this->actingAs($soloRevisa)
            ->post(route('revision.reabrir', $entregable->uuid), ['motivo' => 'client_changed'])
            ->assertRedirect();

        $this->assertSame('approved', (string) DB::table('deliverables')
            ->where('id', $entregable->id)->value('status'));
    }

    /**
     * Reabrir algo que no está aprobado se responde con un aviso, no con un 500.
     *
     * Sin el veto del controlador, `reabrir()` intentaría escribir una revisión
     * sobre `approved_version_id = null` y la clave ajena lo tumbaría con un
     * error crudo en la cara de quien revisa. Es la misma clase de fallo que los
     * dos 500 que aparecieron probando el sistema: la regla existía y la pantalla
     * no sabía contarla.
     */
    public function test_reabrir_lo_que_no_esta_aprobado_avisa_y_no_revienta(): void
    {
        [$entregable] = $this->listoParaRevisar();

        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->post(route('revision.reabrir', $entregable->uuid), ['motivo' => 'client_changed'])
            ->assertRedirect()
            ->assertSessionHas('aviso');

        $this->assertSame(0, DB::table('content_reviews')->count());
    }

    public function test_un_revisor_reabre_desde_la_pantalla(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);
        $revisor = $this->usuarioCon('content_reviewer');

        $this->actingAs($revisor)->get(route('revision.ver', $entregable->uuid))
            ->assertOk()->assertSee('ya está aprobado', false);

        $this->actingAs($revisor)
            ->post(route('revision.reabrir', $entregable->uuid), ['motivo' => 'client_changed'])
            ->assertRedirect(route('revision.ver', $entregable->uuid));

        $this->assertSame('in_review', (string) DB::table('deliverables')
            ->where('id', $entregable->id)->value('status'));
    }

    // ------------------------------------------------------------------ la cola

    public function test_la_cola_es_global_y_ordena_por_lo_que_lleva_mas_esperando(): void
    {
        [$primero, $usuario, $participacionId] = $this->listoParaRevisar(['quantity' => 2]);
        DB::table('deliverables')->where('id', $primero->id)->update(['submitted_at' => now()->subDays(5)]);
        $segundo = DB::table('deliverables')->where('campaign_creator_id', $participacionId)
            ->where('sequence_number', 2)->first();
        Entregables::entregar($segundo, ['external_url' => 'https://a.example/2'], null, (int) $usuario->id, null);

        $cola = Revisiones::cola();

        $this->assertCount(2, $cola);
        $this->assertSame((int) $primero->id, (int) $cola->first()->id, 'lo mas viejo primero');
    }

    public function test_la_cola_deja_de_ensenar_lo_ya_revisado(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        $this->assertCount(0, Revisiones::cola());
    }

    public function test_la_cola_se_puede_filtrar_por_campana(): void
    {
        [$entregable] = $this->listoParaRevisar();

        $this->assertCount(1, Revisiones::cola(['campana' => $this->campanaId]));
        $this->assertCount(0, Revisiones::cola(['campana' => $this->campanaId + 999]));
    }

    // --------------------------------------------------------------- pantallas

    public function test_la_cola_exige_su_permiso(): void
    {
        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('revision.cola'))
            ->assertForbidden();
    }

    public function test_un_revisor_ve_la_cola_y_pide_cambios(): void
    {
        [$entregable] = $this->listoParaRevisar();
        $revisor = $this->usuarioCon('content_reviewer');

        $this->actingAs($revisor)->get(route('revision.cola'))->assertOk()->assertSee('Revisar');

        $this->actingAs($revisor)
            ->post(route('revision.revisar', $entregable->uuid), [
                'outcome' => Revisiones::CAMBIOS,
                'reviewer_side' => 'platform',
                'comments' => 'El logo se ve cortado en el segundo 4.',
            ])
            ->assertRedirect(route('revision.cola'));

        $this->assertSame('changes_requested', (string) DB::table('deliverables')
            ->where('id', $entregable->id)->value('status'));
    }

    /**
     * Aprobar es su propio permiso, y se comprueba **en el POST**.
     *
     * Esconder el botón no es una regla de autorización: el formulario se manda
     * igual desde fuera de la pantalla.
     */
    public function test_sin_permiso_de_aprobacion_no_se_aprueba_aunque_se_mande_el_formulario(): void
    {
        [$entregable] = $this->listoParaRevisar();
        $soloRevisa = $this->usuarioConPermisos(['content.review']);

        $this->actingAs($soloRevisa)
            ->post(route('revision.revisar', $entregable->uuid), [
                'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
            ])
            ->assertRedirect();

        $this->assertSame('submitted', (string) DB::table('deliverables')
            ->where('id', $entregable->id)->value('status'));
    }

    public function test_sin_permiso_de_ronda_extra_no_se_autoriza_el_cargo(): void
    {
        [$entregable] = $this->listoParaRevisar();
        $this->gastarRondas($entregable, 2);
        $revisor = $this->usuarioCon('content_reviewer');

        $this->actingAs($revisor)
            ->post(route('revision.revisar', $entregable->uuid), [
                'outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'client',
                'comments' => 'La tercera vuelta, la pide el cliente.',
                'billing_decision' => 'charge',
            ])
            ->assertRedirect();

        $this->assertSame(0, DB::table('content_reviews')->where('over_included', 1)->count());
    }

    public function test_quien_lleva_la_campana_si_autoriza_la_ronda_de_mas(): void
    {
        [$entregable] = $this->listoParaRevisar();
        $this->gastarRondas($entregable, 2);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post(route('revision.revisar', $entregable->uuid), [
                'outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'client',
                'comments' => 'La tercera vuelta, la pide el cliente.',
                'billing_decision' => 'charge',
            ])
            ->assertRedirect(route('revision.cola'));

        $this->assertSame(1, DB::table('content_reviews')->where('over_included', 1)->count());
    }

    public function test_un_entregable_de_otra_campana_que_no_existe_da_404(): void
    {
        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->get(route('revision.ver', (string) Str::uuid()))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------- correo

    public function test_pedir_cambios_avisa_al_creador(): void
    {
        // El `fake` va DESPUES del montaje: invitar y aceptar mandan sus propios
        // `CorreoPedido` (7.6), y capturarlos aqui haria que esta prueba contara
        // correos de otra iteracion.
        [$entregable, $usuario] = $this->listoParaRevisar();
        Event::fake([CorreoPedido::class]);

        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'platform',
            'comments' => 'El logo se ve cortado en el segundo 4.',
        ], (int) $usuario->id, null);

        Event::assertDispatched(CorreoPedido::class, static fn (CorreoPedido $c): bool => $c->codigo === 'content.changes_requested');
    }

    /** Aprobar no manda correo: no le pide nada al creador y lo ve en su portal. */
    public function test_aprobar_no_manda_correo(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        Event::fake([CorreoPedido::class]);

        Revisiones::emitir($entregable, $this->version($entregable), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        Event::assertNotDispatched(CorreoPedido::class);
    }

    public function test_la_plantilla_del_aviso_existe_y_esta_vigente(): void
    {
        $this->assertSame(1, DB::table('email_templates')
            ->where('code', 'content.changes_requested')->count());
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Un entregable ENVIADO y esperando veredicto.
     *
     * @return array{0: object, 1: User, 2: int}
     */
    private function listoParaRevisar(array $requisito = []): array
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, array_merge(['quantity' => 1], $requisito));
        $participacionId = $this->aceptado(500.0);
        $usuario = $this->usuarioCon('campaign_manager');

        $entregable = DB::table('deliverables')->where('campaign_creator_id', $participacionId)
            ->orderBy('sequence_number')->first();

        Entregables::entregar($entregable, ['external_url' => 'https://a.example/1'], null, (int) $usuario->id, null);

        return [Revisiones::entregable((string) $entregable->uuid), $usuario, $participacionId];
    }

    private function version(object $entregable): object
    {
        return Revisiones::ultimaVersion((int) $entregable->id);
    }

    /** Gasta `$n` rondas del cliente sobre esa pieza, sin pasar por el servicio. */
    private function gastarRondas(object $entregable, int $n): void
    {
        DB::table('deliverables')->where('id', $entregable->id)
            ->update(['revision_rounds_used' => $n]);
    }

    private function usuarioConPermisos(array $permisos): User
    {
        $usuario = User::factory()->create();
        $rolId = (int) DB::table('roles')->insertGetId([
            'code' => 'solo_'.Str::random(6), 'name' => 'Rol de prueba',
            'scope' => 'internal', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($permisos as $codigo) {
            DB::table('permission_role')->insert([
                'role_id' => $rolId,
                'permission_id' => (int) DB::table('permissions')->where('code', $codigo)->value('id'),
            ]);
        }

        DB::table('role_user')->insert([
            'user_id' => $usuario->id, 'role_id' => $rolId, 'assigned_at' => now(),
        ]);
        Permisos::olvidar((int) $usuario->id);

        return $usuario;
    }

    private function campana(): object
    {
        return DB::table('campaigns')->where('id', $this->campanaId)->first();
    }

    private function fila(int $id): object
    {
        return DB::table('campaign_creators')->where('id', $id)->first();
    }

    private function aceptado(float $importe): int
    {
        $creadorId = $this->creadorActivo();
        ListaCorta::anadir($this->campana(), $creadorId);

        $id = (int) DB::table('campaign_creators')
            ->where('campaign_id', $this->campanaId)->where('creator_id', $creadorId)->value('id');
        DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => $importe]);

        $token = Invitaciones::invitar($this->campana(), $this->fila($id), (int) $this->usuarioCon('admin')->id);
        Invitaciones::aceptar($token, '203.0.113.9');

        return $id;
    }
}
