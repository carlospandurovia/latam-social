<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Campaign\Services\Invitaciones;
use App\Modules\Campaign\Services\ListaCorta;
use App\Modules\Content\Services\Aprobaciones;
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
 * El visto bueno del cliente, por enlace firmado (iteración 8.5).
 *
 * ### Las tres decisiones que estas pruebas fijan
 *
 * | Decisión (2026-08-27) | Qué se afirma |
 * |---|---|
 * | `DEC-151` la respuesta se registra, el equipo cierra | contestar **no mueve** el entregable |
 * | `DEC-152` el silencio no hace nada | caducado sólo impide responder |
 * | `DEC-153` una petición sin rondas queda pendiente | no se convierte en ronda sola |
 */
final class AprobacionesTest extends TestCase
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
            'starts_on' => now()->addDays(10)->toDateString(),
            'ends_on' => now()->addMonths(2)->toDateString(),
        ]);
        $this->mercadoDe($this->campanaId, $this->paisPE, ['target_creators' => null]);
    }

    // --------------------------------------------------------- mandar el enlace

    public function test_mandar_el_enlace_deja_su_huella_y_no_el_token(): void
    {
        [$entregable, $usuario] = $this->aprobado();

        $token = Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);

        $fila = DB::table('approval_links')->where('deliverable_id', $entregable->id)->first();

        $this->assertSame(64, mb_strlen($token));
        $this->assertSame(hash('sha256', $token), $fila->token_hash);
        // El token NO está en ninguna columna: si alguien se lleva la tabla, no
        // se lleva los enlaces.
        $this->assertStringNotContainsString($token, json_encode($fila, JSON_THROW_ON_ERROR));
        $this->assertSame('marketing@acme.example', $fila->sent_to);
        $this->assertSame((int) $entregable->approved_version_id, (int) $fila->deliverable_version_id);
    }

    /** `BR-CONTENT-002`: al cliente no le llega nada sin aprobación interna. */
    public function test_la_base_no_deja_mandarle_al_cliente_algo_sin_aprobar(): void
    {
        [$entregable, $usuario] = $this->listoParaRevisar();
        $version = Revisiones::ultimaVersion((int) $entregable->id);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_apl_version_aprobada` lo rechaza.
        DB::table('approval_links')->insert([
            'uuid' => (string) Str::uuid(),
            'deliverable_id' => $entregable->id,
            'deliverable_version_id' => $version->id,
            'token_hash' => hash('sha256', 'x'),
            'sent_to' => 'marketing@acme.example',
            'sent_by_user_id' => $usuario->id,
            'sent_at' => now(),
            'expires_at' => now()->addDays(5),
            'created_at' => now(),
        ]);
    }

    /**
     * Un enlace vivo por pieza — otra columna puerta.
     *
     * Dos enlaces vivos son dos respuestas posibles y contradictorias del mismo
     * cliente, y ninguna forma de saber cuál vale.
     */
    public function test_reenviar_anula_el_anterior_y_solo_queda_uno_vivo(): void
    {
        [$entregable, $usuario] = $this->aprobado();

        $primero = Aprobaciones::pedir($entregable, 'uno@acme.example', (int) $usuario->id);
        $segundo = Aprobaciones::pedir($entregable, 'dos@acme.example', (int) $usuario->id);

        $this->assertSame('anulado', Aprobaciones::validar($primero)['motivo']);
        $this->assertTrue(Aprobaciones::validar($segundo)['ok']);
        $this->assertSame(1, DB::table('approval_links')
            ->where('deliverable_id', $entregable->id)->whereNotNull('viva_gate')->count());
    }

    public function test_la_base_impide_dos_enlaces_vivos_sobre_la_misma_pieza(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        Aprobaciones::pedir($entregable, 'uno@acme.example', (int) $usuario->id);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `uq_apl_viva` lo rechaza.
        DB::table('approval_links')->insert([
            'uuid' => (string) Str::uuid(),
            'deliverable_id' => $entregable->id,
            'deliverable_version_id' => $entregable->approved_version_id,
            'token_hash' => hash('sha256', 'otro'),
            'sent_to' => 'dos@acme.example',
            'sent_at' => now(),
            'expires_at' => now()->addDays(5),
            'created_at' => now(),
        ]);
    }

    public function test_al_cliente_le_llega_su_correo_sin_un_solo_numero(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        Event::fake([CorreoPedido::class]);

        Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);

        Event::assertDispatchedTimes(CorreoPedido::class, 1);
        Event::assertDispatched(CorreoPedido::class, function (CorreoPedido $correo): bool {
            // `BR-SEC-001`: ni importes, ni presupuesto, ni margen.
            $prohibidas = ['importe', 'presupuesto', 'margen', 'amount', 'budget'];
            $claves = array_map('mb_strtolower', array_keys($correo->variables));

            return $correo->codigo === 'content.client_approval'
                && $correo->destinatario === 'marketing@acme.example'
                && array_intersect($prohibidas, $claves) === [];
        });
    }

    // ------------------------------------------------------------- contestar

    /** `DEC-151`: la respuesta se registra y **no mueve el entregable**. */
    public function test_contestar_no_mueve_la_pieza(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        $token = Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);

        $resultado = Aprobaciones::responder($token, Aprobaciones::APROBADA, 'Perfecto', '203.0.113.9');

        $this->assertTrue($resultado['ok']);
        $fila = DB::table('approval_links')->where('deliverable_id', $entregable->id)->first();
        $this->assertSame('approved', (string) $fila->response);
        $this->assertNotNull($fila->responded_at);
        $this->assertNotNull($fila->responded_ip);
        // Y la pieza sigue exactamente donde estaba.
        $this->assertSame('approved', (string) DB::table('deliverables')
            ->where('id', $entregable->id)->value('status'));
        $this->assertSame(0, (int) DB::table('content_reviews')
            ->join('deliverable_versions as v', 'v.id', '=', 'content_reviews.deliverable_version_id')
            ->where('v.deliverable_id', $entregable->id)
            ->where('content_reviews.reviewer_side', 'client')->count());
    }

    public function test_pedir_cambios_sin_decir_cuales_lo_rechaza_la_base(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `ck_apl_cambios` lo rechaza.
        DB::table('approval_links')->where('deliverable_id', $entregable->id)->update([
            'responded_at' => now(), 'response' => 'changes_requested', 'comments' => 'no',
        ]);
    }

    /** La conformidad del cliente no se reescribe. */
    public function test_la_respuesta_del_cliente_no_se_puede_cambiar(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        $token = Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);
        Aprobaciones::responder($token, Aprobaciones::APROBADA, null, null);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_apl_respuesta_inmutable` lo rechaza.
        DB::table('approval_links')->where('deliverable_id', $entregable->id)
            ->update(['response' => 'changes_requested', 'comments' => 'ahora digo otra cosa']);
    }

    public function test_el_mismo_enlace_no_sirve_dos_veces(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        $token = Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);
        Aprobaciones::responder($token, Aprobaciones::APROBADA, null, null);

        $this->assertSame('contestado', Aprobaciones::responder($token, Aprobaciones::CAMBIOS, 'Ahora no me vale', null)['motivo']);
    }

    /** `DEC-152`: caducar sólo impide contestar. No cambia nada más. */
    public function test_caducar_no_aprueba_ni_rechaza_nada(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        $token = Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);

        // Se mueven LAS DOS: `ck_apl_plazo` exige que caduque después de
        // mandarse, así que un enlace vencido es uno que se mandó hace tiempo,
        // no uno que nació caducado. La restricción tenía razón en quejarse.
        DB::table('approval_links')->where('deliverable_id', $entregable->id)->update([
            'sent_at' => now()->subDays(6),
            'expires_at' => now()->subDay(),
        ]);

        $this->assertSame('caducado', Aprobaciones::validar($token)['motivo']);
        // La pieza sigue aprobada y sin respuesta: nadie firmó por el cliente.
        $this->assertSame('approved', (string) DB::table('deliverables')
            ->where('id', $entregable->id)->value('status'));
        $this->assertNull(DB::table('approval_links')
            ->where('deliverable_id', $entregable->id)->value('response'));
    }

    public function test_un_token_inventado_no_dice_si_existe_o_no(): void
    {
        $this->assertSame('desconocido', Aprobaciones::validar(str_repeat('a', 64))['motivo']);
    }

    // ---------------------------------------------------- lo que el cliente ve

    /** `BR-SEC-001` es 🔴. La frontera es esta consulta. */
    public function test_el_cliente_no_ve_ni_un_importe(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        $token = Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);
        $enlace = Aprobaciones::porToken($token);

        /** @var array<string, mixed> $pieza */
        $pieza = (array) Aprobaciones::pieza($enlace);
        $claves = array_map('mb_strtolower', array_keys($pieza));

        foreach (['amount', 'importe', 'budget', 'presupuesto', 'margin', 'margen', 'rate', 'revenue'] as $prohibida) {
            foreach ($claves as $clave) {
                $this->assertStringNotContainsString($prohibida, $clave,
                    'la consulta del cliente no puede traer '.$clave);
            }
        }

        $this->assertArrayHasKey('campana', $pieza);
        $this->assertArrayHasKey('formato', $pieza);
    }

    // -------------------------------------------------------- la pantalla

    public function test_la_pantalla_publica_no_deja_el_token_en_la_url(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        $token = Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);

        $this->get(route('aprobacion.ver', ['token' => $token]))
            ->assertRedirect(route('aprobacion.pieza'));

        // Y que lo abrió consta antes de que decida nada.
        $this->assertNotNull(DB::table('approval_links')
            ->where('deliverable_id', $entregable->id)->value('opened_at'));
    }

    public function test_el_cliente_contesta_desde_la_pantalla(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        $token = Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);

        $this->get(route('aprobacion.ver', ['token' => $token]));
        $this->get(route('aprobacion.pieza'))->assertOk();

        $this->post(route('aprobacion.responder'), [
            'respuesta' => 'changes_requested',
            'comentario' => 'El logo se ve cortado en el segundo cuatro.',
        ])->assertRedirect(route('aprobacion.gracias'));

        $this->assertSame('changes_requested', (string) DB::table('approval_links')
            ->where('deliverable_id', $entregable->id)->value('response'));
    }

    public function test_la_pantalla_no_deja_pedir_cambios_sin_decir_cuales(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        $token = Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);
        $this->get(route('aprobacion.ver', ['token' => $token]));

        $this->post(route('aprobacion.responder'), ['respuesta' => 'changes_requested'])
            ->assertSessionHasErrors('comentario');

        $this->assertNull(DB::table('approval_links')
            ->where('deliverable_id', $entregable->id)->value('response'));
    }

    /** Un «perfecto» de ocho letras al aprobar es una respuesta válida. */
    public function test_al_aprobar_no_se_le_exige_escribir_diez_caracteres(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        $token = Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);
        $this->get(route('aprobacion.ver', ['token' => $token]));

        $this->post(route('aprobacion.responder'), [
            'respuesta' => 'approved', 'comentario' => 'Perfecto',
        ])->assertRedirect(route('aprobacion.gracias'));

        $this->assertSame('approved', (string) DB::table('approval_links')
            ->where('deliverable_id', $entregable->id)->value('response'));
    }

    // -------------------------------------------------- la vuelta al equipo

    public function test_la_respuesta_espera_a_que_alguien_la_cierre(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        $token = Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);
        Aprobaciones::responder($token, Aprobaciones::CAMBIOS, 'Cambiad el encuadre del final.', null);

        $this->assertCount(1, Aprobaciones::pendientes());
        $this->assertNotNull(Aprobaciones::respuestaPendiente((int) $entregable->id));
    }

    /**
     * Y al cerrarla queda atada al veredicto que la contestó.
     *
     * La pieza está aprobada, así que el camino real es reabrirla —`8.2`— y
     * emitir el veredicto del lado cliente. Eso es lo que hace la pantalla.
     */
    public function test_al_transcribirla_deja_de_estar_pendiente(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        $token = Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);
        Aprobaciones::responder($token, Aprobaciones::CAMBIOS, 'Cambiad el encuadre del final.', null);

        $pendiente = Aprobaciones::respuestaPendiente((int) $entregable->id);
        $revisionId = (int) DB::table('content_reviews')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'deliverable_version_id' => $entregable->approved_version_id,
            'reviewer_user_id' => $usuario->id,
            'reviewer_side' => 'client',
            'outcome' => 'reopened',
            'comments' => 'El cliente pide cambios: encuadre del final.',
            'consumes_round' => 0,
            'reviewed_at' => now(),
            'created_at' => now(),
        ]);

        Aprobaciones::transcribir((int) $pendiente->id, $revisionId);

        $this->assertCount(0, Aprobaciones::pendientes());
        $this->assertNull(Aprobaciones::respuestaPendiente((int) $entregable->id));
    }

    public function test_la_conformidad_del_cliente_no_se_borra(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        $token = Aprobaciones::pedir($entregable, 'marketing@acme.example', (int) $usuario->id);
        Aprobaciones::responder($token, Aprobaciones::APROBADA, null, null);

        $this->expectException(QueryException::class);

        // `approval_links` entró en la lista de 3.12 con 8.5.
        DB::table('approval_links')->where('deliverable_id', $entregable->id)->delete();
    }

    // ------------------------------------------------------------------ apoyo

    /** @return array{0: object, 1: User} */
    private function listoParaRevisar(): array
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $participacionId = $this->aceptado(500.0);
        $usuario = $this->usuarioCon('campaign_manager');

        $entregable = DB::table('deliverables')->where('campaign_creator_id', $participacionId)
            ->orderBy('sequence_number')->first();
        Entregables::entregar($entregable, ['external_url' => 'https://a.example/1'], null, (int) $usuario->id, null);

        return [Revisiones::entregable((string) $entregable->uuid), $usuario];
    }

    /** Una pieza aprobada internamente, que es lo único que se le manda al cliente. */
    private function aprobado(): array
    {
        [$entregable, $usuario] = $this->listoParaRevisar();

        Revisiones::emitir($entregable, Revisiones::ultimaVersion((int) $entregable->id), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        return [Revisiones::entregable((string) $entregable->uuid), $usuario];
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
