<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Campaign\Services\Compromiso;
use App\Modules\Campaign\Services\Invitaciones;
use App\Modules\Campaign\Services\ListaCorta;
use App\Shared\Auth\Permisos;
use App\Shared\Eventos\CorreoPedido;
use Database\Seeders\CimientosSeeder;
use Database\Seeders\PlantillasDeCorreoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * La invitación a una campaña (iteración 7.6).
 *
 * ### Las tres decisiones que estas pruebas fijan
 *
 * | Decisión (2026-08-26) | Qué se afirma |
 * |---|---|
 * | El creador contesta **él**, por enlace | hay IP, hora y un token que sólo él tenía |
 * | Plazo fijo por campaña | y caducar libera presupuesto y cupo |
 * | Rechazar no cierra la puerta | se reinvita, y **quedan las dos rondas** |
 *
 * ### Y la que apareció al construirlo
 *
 * `BR-CREATOR-008` congela el precio **al aceptar**. Entre el envío y la
 * respuesta, `agreed_amount` se podía mover — y el creador aceptaría una cifra
 * que nunca vio. Se cierra por los dos lados: la invitación copia el importe, y
 * la base impide moverlo mientras la invitación viva.
 */
final class InvitacionesTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $campanaId;

    private string $uuid;

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
        ]);
        $this->uuid = (string) DB::table('campaigns')->where('id', $this->campanaId)->value('uuid');
        $this->mercadoDe($this->campanaId, $this->paisPE);

        // La premisa: la plantilla existe. Sin ella el oyente registra el fallo
        // y sigue --que es lo decidido-- y todas las aserciones de «salio el
        // correo» saldrian rojas por el motivo equivocado.
        $this->assertTrue(
            DB::table('email_templates')->where('code', 'campaign.invitation')->exists(),
            'falta la plantilla `campaign.invitation`',
        );
    }

    // ------------------------------------------------------------ el veto

    /** **La afirmación que descubre si las demás mienten.** Lo normal se puede invitar. */
    public function test_una_participacion_con_importe_se_puede_invitar(): void
    {
        $id = $this->participacion(500.0);

        $this->assertSame([], Invitaciones::vetoParaInvitar($this->campana(), $this->fila($id)));
    }

    public function test_sin_importe_no_se_invita(): void
    {
        $id = $this->participacion(0.0);

        $motivos = Invitaciones::vetoParaInvitar($this->campana(), $this->fila($id));

        $this->assertNotEmpty($motivos);
        $this->assertStringContainsString('BR-CREATOR-008', implode(' ', $motivos));
    }

    /** Salvo que la campaña sea un canje, que 7.2 declaró legítimo. */
    public function test_en_una_campana_gratuita_el_cero_si_se_invita(): void
    {
        DB::table('campaigns')->where('id', $this->campanaId)
            ->update(['is_gratis' => 1, 'revenue_amount' => 0]);
        $id = $this->participacion(0.0);

        $this->assertSame([], Invitaciones::vetoParaInvitar($this->campana(), $this->fila($id)));
    }

    public function test_no_se_invita_desde_una_campana_en_borrador(): void
    {
        $id = $this->participacion(500.0);
        DB::table('campaigns')->where('id', $this->campanaId)
            ->update(['status' => 'draft', 'confirmed_at' => null]);

        $motivos = Invitaciones::vetoParaInvitar($this->campana(), $this->fila($id));

        $this->assertStringContainsString('no esta confirmada', implode(' ', $motivos));
    }

    public function test_no_se_invita_dos_veces_a_la_vez(): void
    {
        $id = $this->participacion(500.0);
        Invitaciones::invitar($this->campana(), $this->fila($id));

        $motivos = Invitaciones::vetoParaInvitar($this->campana(), $this->fila($id));

        $this->assertStringContainsString('invitacion viva', implode(' ', $motivos));
    }

    // ---------------------------------------------------------- invitar

    public function test_invitar_deja_la_participacion_invitada_y_manda_el_correo(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);

        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame('invited', $this->fila($id)->status);
        $this->assertNotNull($this->fila($id)->invited_at);

        $registro = DB::table('email_log')->latest('id')->first();
        $this->assertSame('campaign.invitation', $registro->template_code);
    }

    public function test_de_la_invitacion_se_guarda_la_huella_y_no_el_token(): void
    {
        $id = $this->participacion(500.0);

        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $fila = DB::table('invitations')->where('campaign_creator_id', $id)->first();
        $this->assertSame(hash('sha256', $token), $fila->token_hash);

        foreach ((array) $fila as $columna => $valor) {
            $this->assertNotSame($token, (string) $valor, "El token aparece en `{$columna}`.");
        }
    }

    public function test_la_invitacion_copia_el_importe_con_el_que_salio(): void
    {
        $id = $this->participacion(500.0);

        Invitaciones::invitar($this->campana(), $this->fila($id));

        $fila = DB::table('invitations')->where('campaign_creator_id', $id)->first();
        $this->assertSame(500.0, (float) $fila->amount_snapshot);
        $this->assertNotNull($fila->currency_snapshot);
    }

    public function test_el_plazo_sale_de_la_campana(): void
    {
        DB::table('campaigns')->where('id', $this->campanaId)->update(['invitation_hours' => 5]);
        $id = $this->participacion(500.0);

        Invitaciones::invitar($this->campana(), $this->fila($id));

        $caduca = Carbon::parse(
            (string) DB::table('invitations')->where('campaign_creator_id', $id)->value('expires_at'),
        );

        $this->assertSame(5, (int) round(now()->diffInMinutes($caduca) / 60));
    }

    public function test_el_correo_lleva_el_enlace_y_el_importe_pero_nada_interno(): void
    {
        Event::fake([CorreoPedido::class]);
        $id = $this->participacion(500.0);

        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        Event::assertDispatched(CorreoPedido::class, function (CorreoPedido $e) use ($token): bool {
            $texto = json_encode($e->variables, JSON_THROW_ON_ERROR);

            return $e->codigo === 'campaign.invitation'
                && str_contains((string) $e->variables['enlace'], $token)
                && str_contains($texto, '500.00')
                // Ni el ingreso del cliente ni el presupuesto (`BR-SEC-001`).
                && !str_contains($texto, '15000')
                && !str_contains($texto, '5000');
        });
    }

    /** El plazo tiene tope arriba y abajo: la base no admite un cero. */
    public function test_un_plazo_imposible_lo_rechaza_la_base(): void
    {
        $this->expectException(QueryException::class);

        DB::table('campaigns')->where('id', $this->campanaId)->update(['invitation_hours' => 0]);
    }

    // ------------------------------------------- el importe con invitacion viva

    /**
     * **El hueco que apareció al construir esto.**
     *
     * Sin esta regla: le llega «te pagamos 1.500», alguien lo baja a 900, y el
     * creador acepta 900 sin haberlo visto nunca. No hace falta mala fe — basta
     * con dos personas trabajando sobre la misma campaña.
     */
    public function test_con_invitacion_viva_no_se_cambia_el_importe(): void
    {
        $id = $this->participacion(500.0);
        Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->expectException(QueryException::class);

        DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => 900]);
    }

    public function test_y_el_servicio_lo_dice_con_palabras_antes(): void
    {
        $id = $this->participacion(500.0);
        Invitaciones::invitar($this->campana(), $this->fila($id));

        $aviso = (string) Invitaciones::vetoPorInvitacionViva($id);

        $this->assertStringContainsString('500.00', $aviso);
        $this->assertStringContainsString('Anule la invitacion', $aviso);
    }

    public function test_anulada_la_invitacion_el_importe_se_puede_mover(): void
    {
        $id = $this->participacion(500.0);
        Invitaciones::invitar($this->campana(), $this->fila($id));

        Invitaciones::anular($id, 'renegociacion');
        DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => 900]);

        $this->assertSame(900.0, (float) $this->fila($id)->agreed_amount);
        $this->assertNull(Invitaciones::vetoPorInvitacionViva($id));
    }

    // ----------------------------------------------------------- responder

    public function test_aceptar_congela_el_acuerdo(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->assertTrue(Invitaciones::aceptar($token, '203.0.113.9')['ok']);

        $fila = $this->fila($id);
        $this->assertSame('accepted', $fila->status);
        $this->assertNotNull($fila->accepted_at);

        // Y a partir de aqui manda `tg_ccr_compromiso` (7.5).
        $this->expectException(QueryException::class);
        DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => 501]);
    }

    public function test_aceptar_registra_desde_donde(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        Invitaciones::aceptar($token, '203.0.113.9');

        $fila = DB::table('invitations')->where('campaign_creator_id', $id)->first();
        $this->assertSame('203.0.113.9', inet_ntop($fila->responded_ip));
        $this->assertSame('accepted', $fila->response);
    }

    public function test_rechazar_exige_motivo_de_la_lista(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->assertFalse(Invitaciones::rechazar($token, 'porque_no', null, '203.0.113.9')['ok']);
        $this->assertSame('invited', $this->fila($id)->status, 'y no lo ha movido');

        $this->assertTrue(Invitaciones::rechazar($token, 'amount', 'Muy poco', '203.0.113.9')['ok']);
        $this->assertSame('declined', $this->fila($id)->status);
    }

    /** Y la base tampoco admite un motivo inventado, venga de donde venga. */
    public function test_la_base_rechaza_un_motivo_que_no_esta_en_la_lista(): void
    {
        $id = $this->participacion(500.0);
        Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->expectException(QueryException::class);

        DB::table('invitations')->where('campaign_creator_id', $id)->update([
            'responded_at' => now(), 'response' => 'declined',
            'decline_reason' => 'me_cae_mal', 'responded_ip' => inet_pton('203.0.113.9'),
        ]);
    }

    public function test_un_enlace_no_vale_dos_veces(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        Invitaciones::aceptar($token, '203.0.113.9');

        $this->assertSame('respondida', Invitaciones::validar($token)['motivo']);
        $this->assertSame('respondida', Invitaciones::rechazar($token, 'amount', null, '203.0.113.9')['motivo']);
    }

    public function test_un_enlace_caducado_no_sirve(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->vencer($id);

        $this->assertSame('caducada', Invitaciones::validar($token)['motivo']);
        $this->assertFalse(Invitaciones::aceptar($token, '203.0.113.9')['ok']);
    }

    public function test_si_la_campana_se_cierra_el_enlace_deja_de_servir(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        DB::table('campaigns')->where('id', $this->campanaId)
            ->update(['status' => 'completed', 'closed_at' => now()]);

        $this->assertSame('campana_cerrada', Invitaciones::validar($token)['motivo']);
        $this->assertFalse(Invitaciones::aceptar($token, '203.0.113.9')['ok']);
    }

    // ------------------------------------------------- el nuevo mata al viejo

    public function test_invitar_otra_vez_anula_la_anterior(): void
    {
        $id = $this->participacion(500.0);
        $vieja = Invitaciones::invitar($this->campana(), $this->fila($id));
        Invitaciones::anular($id, 'renegociacion');
        DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => 900]);
        $nueva = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->assertSame('anulada', Invitaciones::validar($vieja)['motivo']);
        $this->assertTrue(Invitaciones::validar($nueva)['ok']);
    }

    /** Anular no es responder, y esa diferencia es evidencia. */
    public function test_anular_no_es_responder(): void
    {
        $id = $this->participacion(500.0);
        Invitaciones::invitar($this->campana(), $this->fila($id));

        Invitaciones::anular($id, 'sustituida');

        $fila = DB::table('invitations')->where('campaign_creator_id', $id)->first();
        $this->assertNull($fila->responded_at);
        $this->assertNull($fila->response);
        $this->assertNotNull($fila->revoked_at);
        $this->assertSame('sustituida', $fila->revoked_reason);
    }

    /** La base es la que garantiza «una viva», no el servicio. */
    public function test_la_base_impide_dos_invitaciones_vivas(): void
    {
        $id = $this->participacion(500.0);
        Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->expectException(QueryException::class);

        DB::table('invitations')->insert([
            'uuid' => (string) Str::uuid(), 'campaign_creator_id' => $id, 'channel' => 'email',
            'token_hash' => hash('sha256', 'otro'), 'sent_at' => now(),
            'expires_at' => now()->addHour(), 'amount_snapshot' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ----------------------------------------------------------- reinvitar

    /**
     * Rechazar no cierra la campaña para ese creador — y **quedan las dos**.
     *
     * La constancia vive en `invitations`, una fila por ronda con su motivo, que
     * es exactamente para lo que la Fase 2 diseñó la tabla.
     */
    public function test_a_quien_rechazo_se_le_puede_volver_a_invitar(): void
    {
        $id = $this->participacion(500.0);
        $primera = Invitaciones::invitar($this->campana(), $this->fila($id));
        Invitaciones::rechazar($primera, 'amount', 'Muy poco', '203.0.113.9');

        // Finanzas sube la oferta.
        DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => 900]);

        $this->assertSame([], Invitaciones::vetoParaInvitar($this->campana(), $this->fila($id)));

        $segunda = Invitaciones::invitar($this->campana(), $this->fila($id));
        $this->assertTrue(Invitaciones::aceptar($segunda, '203.0.113.9')['ok']);

        $historial = Invitaciones::historial($id);
        $this->assertCount(2, $historial, 'las dos rondas quedan');
        $this->assertSame('accepted', $historial[0]->response);
        $this->assertSame('declined', $historial[1]->response);
        $this->assertSame('amount', $historial[1]->decline_reason);
        $this->assertSame(500.0, (float) $historial[1]->amount_snapshot, 'y lo que se ofrecio entonces');
    }

    /**
     * Reinvitar limpia `declined_at` de la participación.
     *
     * Esa columna dice cuándo se rechazó la ronda **actual**. Dejarla puesta haría
     * que un `accepted` conviviera con una fecha de rechazo, y eso no significa
     * nada. El historial completo está en `invitations`.
     */
    public function test_reinvitar_limpia_el_rechazo_de_la_participacion(): void
    {
        $id = $this->participacion(500.0);
        $primera = Invitaciones::invitar($this->campana(), $this->fila($id));
        Invitaciones::rechazar($primera, 'dates', null, '203.0.113.9');

        $this->assertNotNull($this->fila($id)->declined_at);

        Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->assertNull($this->fila($id)->declined_at);
        $this->assertSame(1, Invitaciones::historial($id)->where('response', 'declined')->count(),
            'pero el rechazo sigue en el historial');
    }

    // ------------------------------------------------------------ caducar

    public function test_caducar_libera_presupuesto_y_cupo(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));
        $this->vencer($id);

        $this->assertSame(1, Invitaciones::caducar());

        $this->assertSame('expired', $this->fila($id)->status);
        $this->assertSame('caducada', Invitaciones::validar($token)['motivo']);
        // Y su importe deja de contar: `Compromiso::MUERTAS` incluye `expired`.
        $this->assertSame(0.0, Compromiso::comprometido($this->campanaId));
    }

    /**
     * Y al creador se le dice **caducada**, no «te mandamos otra».
     *
     * En la tabla las dos muertes son un `revoked_at`, y la primera versión las
     * contestaba igual: a quien simplemente se pasó del plazo le decía que
     * buscara en su buzón un correo más reciente que no existe.
     */
    public function test_una_caducada_no_se_confunde_con_una_sustituida(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));
        $this->vencer($id);
        Invitaciones::caducar();

        $this->assertSame('caducada', Invitaciones::validar($token)['motivo']);
        $this->assertStringContainsString('plazo', Invitaciones::FALLOS['caducada']);

        // Y la sustituida sigue diciendo lo suyo.
        $otro = $this->participacion(500.0);
        $vieja = Invitaciones::invitar($this->campana(), $this->fila($otro));
        Invitaciones::anular($otro, 'sustituida');

        $this->assertSame('anulada', Invitaciones::validar($vieja)['motivo']);
    }

    public function test_caducar_no_toca_las_que_siguen_en_plazo(): void
    {
        $id = $this->participacion(500.0);
        Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->assertSame(0, Invitaciones::caducar());
        $this->assertSame('invited', $this->fila($id)->status);
    }

    public function test_caducar_no_pisa_una_ya_respondida(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));
        Invitaciones::aceptar($token, '203.0.113.9');
        $this->vencer($id);

        $this->assertSame(0, Invitaciones::caducar());
        $this->assertSame('accepted', $this->fila($id)->status);
    }

    public function test_el_comando_dice_cuantas_cerro(): void
    {
        $id = $this->participacion(500.0);
        Invitaciones::invitar($this->campana(), $this->fila($id));
        $this->vencer($id);

        $this->artisan('invitaciones:caducar')
            ->expectsOutputToContain('1 invitacion(es) caducada(s).')
            ->assertExitCode(0);
    }

    // ---------------------------------------------------------- las pantallas

    public function test_abrir_el_enlace_redirige_a_una_url_sin_token_y_ensena_la_oferta(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->get(route('invitacion.ver', ['token' => $token]))
            ->assertRedirect(route('invitacion.oferta'));

        $this->assertStringNotContainsString($token, route('invitacion.oferta'));

        $this->get(route('invitacion.oferta'))
            ->assertOk()
            ->assertSee('500.00')
            ->assertSee('Marca ACME');
    }

    /** Y abrirlo consta: «¿lo leyó siquiera?» no es «¿contestó?». */
    public function test_abrir_el_enlace_queda_anotado(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->assertNull(DB::table('invitations')->where('campaign_creator_id', $id)->value('opened_at'));

        $this->get(route('invitacion.ver', ['token' => $token]));

        $this->assertNotNull(DB::table('invitations')->where('campaign_creator_id', $id)->value('opened_at'));
    }

    /** **`BR-SEC-001`.** La pantalla del creador no enseña nada interno. */
    public function test_la_oferta_no_ensena_ni_el_ingreso_ni_el_presupuesto(): void
    {
        $id = $this->participacion(500.0);
        $otro = $this->participacion(1234.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->get(route('invitacion.ver', ['token' => $token]));

        $this->get(route('invitacion.oferta'))
            ->assertOk()
            ->assertDontSee('15,000')
            ->assertDontSee('5,000')
            // Ni lo que cobra otro creador de la misma campana.
            ->assertDontSee('1,234')
            ->assertDontSee((string) $otro);
    }

    public function test_el_recorrido_de_aceptar_por_pantalla(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->get(route('invitacion.ver', ['token' => $token]));
        $this->post(route('invitacion.aceptar'))->assertRedirect(route('invitacion.gracias'));

        $this->assertSame('accepted', $this->fila($id)->status);

        // Y el enlace ya no vale: se vuelve a abrir como haria alguien con el
        // correo delante.
        $this->get(route('invitacion.ver', ['token' => $token]))
            ->assertRedirect(route('invitacion.caducada'));
    }

    public function test_el_recorrido_de_rechazar_por_pantalla(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->get(route('invitacion.ver', ['token' => $token]));
        $this->post(route('invitacion.rechazar'), ['motivo' => 'dates', 'nota' => 'Estoy de viaje'])
            ->assertRedirect(route('invitacion.gracias'));

        $fila = DB::table('invitations')->where('campaign_creator_id', $id)->first();
        $this->assertSame('dates', $fila->decline_reason);
        $this->assertSame('Estoy de viaje', $fila->decline_note);
    }

    public function test_rechazar_sin_motivo_no_pasa_y_no_quema_el_enlace(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->get(route('invitacion.ver', ['token' => $token]));
        $this->post(route('invitacion.rechazar'), ['motivo' => ''])
            ->assertSessionHasErrors('motivo');

        $this->assertTrue(Invitaciones::validar($token)['ok'], 'el enlace sigue sirviendo');
    }

    public function test_sin_haber_abierto_el_enlace_no_hay_oferta(): void
    {
        $this->get(route('invitacion.oferta'))
            ->assertRedirect(route('invitacion.caducada'))
            ->assertSessionHas('fallo');
    }

    public function test_abrir_el_enlace_vacia_la_sesion(): void
    {
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->withSession(['plantado_por_otro' => 'x'])
            ->get(route('invitacion.ver', ['token' => $token]));

        $this->assertNull(session('plantado_por_otro'));
        $this->get(route('invitacion.oferta'))->assertOk();
    }

    // ------------------------------------------------------ el back-office

    public function test_invitar_desde_la_pantalla_de_candidatos(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post(route('campanas.candidatos.invitar', [$this->uuid, $id]))
            ->assertRedirect();

        $this->assertSame('invited', $this->fila($id)->status);
    }

    /**
     * Y la pantalla comprueba el veto, no sólo el permiso.
     *
     * Sobrevivió una mutación aquí: quitar la comprobación del controlador no
     * ponía nada en rojo, porque todas las pruebas del back-office recorrían el
     * camino feliz. Un veto que sólo existe en el servicio es un veto que se
     * salta cualquier ruta nueva.
     */
    public function test_la_pantalla_no_invita_a_quien_el_veto_rechaza(): void
    {
        Queue::fake();
        $id = $this->participacion(0.0);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post(route('campanas.candidatos.invitar', [$this->uuid, $id]))
            ->assertRedirect()
            ->assertSessionHas('aviso');

        $this->assertSame('shortlisted', $this->fila($id)->status);
        $this->assertSame(0, DB::table('invitations')->where('campaign_creator_id', $id)->count());
    }

    public function test_invitar_exige_su_permiso(): void
    {
        $id = $this->participacion(500.0);

        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->post(route('campanas.candidatos.invitar', [$this->uuid, $id]))
            ->assertForbidden();

        $this->assertSame('shortlisted', $this->fila($id)->status);
    }

    public function test_anular_desde_la_pantalla_devuelve_a_la_lista_corta(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);
        Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post(route('campanas.candidatos.anular', [$this->uuid, $id]), ['motivo' => 'renegociamos'])
            ->assertRedirect();

        $this->assertSame('shortlisted', $this->fila($id)->status);
        $this->assertNull($this->fila($id)->invited_at);
        $this->assertNull(Invitaciones::viva($id));
    }

    public function test_la_pantalla_de_candidatos_dice_hasta_cuando(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);
        Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('campanas.candidatos', $this->uuid))
            ->assertOk()
            ->assertSee('sin abrir')
            ->assertSee('ofrecido');
    }

    // ------------------------------------------------ `T-38`: las preguntas

    /**
     * Preguntar **no es contestar**: la invitación sigue viva.
     *
     * Es la mitad que faltaba de `7.6`. Sin un sitio donde preguntar, una duda se
     * convierte en un rechazo — y ese rechazo entra en `decline_reason` como si
     * fuera una opinión sobre la oferta, contaminando la única estadística que
     * esa columna existe para producir.
     */
    public function test_preguntar_no_gasta_la_invitacion(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), $this->invitador());

        $this->assertTrue(Invitaciones::preguntar($token, '.El producto llega antes del rodaje?', '203.0.113.9')['ok']);

        $this->assertTrue(Invitaciones::validar($token)['ok'], 'la invitacion sigue viva');
        $this->assertSame('invited', $this->fila($id)->status);
        $this->assertCount(1, Invitaciones::preguntas($id));
    }

    /** Y el plazo no se mueve: es la decisión de negocio, y se afirma. */
    public function test_preguntar_no_mueve_el_plazo(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), $this->invitador());

        $antes = (string) DB::table('invitations')->where('campaign_creator_id', $id)->value('expires_at');
        Invitaciones::preguntar($token, 'Una duda cualquiera', '203.0.113.9');

        $this->assertSame(
            $antes,
            (string) DB::table('invitations')->where('campaign_creator_id', $id)->value('expires_at'),
            'congelar el plazo dejaria el importe comprometido para siempre si nadie contesta',
        );
    }

    public function test_la_pregunta_llega_a_quien_invito(): void
    {
        Event::fake([CorreoPedido::class]);
        $invitador = $this->usuarioCon('campaign_manager');
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), (int) $invitador->id);

        Invitaciones::preguntar($token, 'Sobre el envio del producto', '203.0.113.9');

        Event::assertDispatched(
            CorreoPedido::class,
            fn (CorreoPedido $e): bool => $e->codigo === 'campaign.invitation_question'
                && $e->destinatario === $invitador->email
                && str_contains((string) $e->variables['pregunta'], 'envio del producto'),
        );
    }

    /** Sin invitador —la emitió un proceso— no se manda nada y no se rompe nada. */
    public function test_sin_invitador_la_pregunta_se_guarda_igual(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->assertTrue(Invitaciones::preguntar($token, 'Una duda', '203.0.113.9')['ok']);
        $this->assertCount(1, Invitaciones::preguntas($id));
    }

    public function test_una_pregunta_vacia_la_rechaza_la_base(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);
        Invitaciones::invitar($this->campana(), $this->fila($id));
        $invitacionId = (int) DB::table('invitations')->where('campaign_creator_id', $id)->value('id');

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: esta fila EXISTE para que la base la
        // rechace. `verificar-fixturas.py` la saltaria como un fixture que
        // miente si no se le dice.
        DB::table('invitation_questions')->insert([
            'uuid' => (string) Str::uuid(), 'invitation_id' => $invitacionId,
            'body' => '  ', 'asked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_marcar_vista_exige_decir_quien(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);
        Invitaciones::invitar($this->campana(), $this->fila($id));
        $invitacionId = (int) DB::table('invitations')->where('campaign_creator_id', $id)->value('id');
        $preguntaId = (int) DB::table('invitation_questions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'invitation_id' => $invitacionId,
            'body' => 'Una duda', 'asked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('invitation_questions')->where('id', $preguntaId)->update(['seen_at' => now()]);
    }

    public function test_una_caducada_ya_no_admite_preguntas(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));
        $this->vencer($id);

        $this->assertSame('caducada', Invitaciones::preguntar($token, 'Llego tarde', '203.0.113.9')['motivo']);
        $this->assertCount(0, Invitaciones::preguntas($id));
    }

    // --------------------------------------- `T-38` por pantalla y back-office

    public function test_preguntar_desde_la_pantalla_del_creador(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), $this->invitador());

        $this->get(route('invitacion.ver', ['token' => $token]));

        $this->post(route('invitacion.preguntar'), ['pregunta' => 'Cuando llega el producto?'])
            ->assertRedirect(route('invitacion.oferta'))
            ->assertSessionHas('preguntado');

        // Y sigue pudiendo aceptar: preguntar no gasta el enlace.
        $this->post(route('invitacion.aceptar'))->assertRedirect(route('invitacion.gracias'));
        $this->assertSame('accepted', $this->fila($id)->status);
    }

    public function test_la_pantalla_avisa_de_que_el_plazo_sigue_corriendo(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->get(route('invitacion.ver', ['token' => $token]));

        // Callarlo dejaria a alguien esperando tranquilo mientras su invitacion
        // caduca, que es peor que no dejarle preguntar.
        $this->get(route('invitacion.oferta'))->assertOk()->assertSee('plazo sigue corriendo');
    }

    public function test_una_pregunta_demasiado_corta_no_pasa(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id));

        $this->get(route('invitacion.ver', ['token' => $token]));
        $this->post(route('invitacion.preguntar'), ['pregunta' => 'a'])
            ->assertSessionHasErrors('pregunta');

        $this->assertCount(0, Invitaciones::preguntas($id));
        $this->assertTrue(Invitaciones::validar($token)['ok'], 'y no le cuesta el enlace');
    }

    public function test_la_pregunta_sale_en_la_pantalla_de_candidatos(): void
    {
        Queue::fake();
        $gestor = $this->usuarioCon('campaign_manager');
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), (int) $gestor->id);
        Invitaciones::preguntar($token, 'Sobre el envio del producto', '203.0.113.9');

        $this->actingAs($gestor)
            ->get(route('campanas.candidatos', $this->uuid))
            ->assertOk()
            ->assertSee('Sobre el envio del producto')
            ->assertSee('Me hago cargo');
    }

    public function test_hacerse_cargo_deja_dueno(): void
    {
        Queue::fake();
        $gestor = $this->usuarioCon('campaign_manager');
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), (int) $gestor->id);
        Invitaciones::preguntar($token, 'Sobre el envio del producto', '203.0.113.9');
        $preguntaId = (int) Invitaciones::preguntas($id)->first()->id;

        $this->actingAs($gestor)
            ->post(route('campanas.candidatos.pregunta', [$this->uuid, $id, $preguntaId]))
            ->assertRedirect()
            ->assertSessionHas('exito');

        $this->assertNotNull(Invitaciones::preguntas($id)->first()->seen_at);
        $this->assertSame(0, Invitaciones::preguntasPendientes($this->campanaId));
    }

    /**
     * Y el que se hizo cargo **no se pisa**.
     *
     * Quién atendió una pregunta es evidencia igual que quién invitó: si el
     * segundo clic sobrescribe al primero, «¿quién se hizo cargo?» pasa a
     * responderse con «el último que pulsó», que no es lo mismo.
     */
    public function test_hacerse_cargo_dos_veces_no_cambia_el_dueno(): void
    {
        Queue::fake();
        $primero = $this->usuarioCon('campaign_manager');
        $segundo = $this->usuarioCon('campaign_manager');
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), (int) $primero->id);
        Invitaciones::preguntar($token, 'Sobre el envio', '203.0.113.9');
        $preguntaId = (int) Invitaciones::preguntas($id)->first()->id;

        Invitaciones::marcarVista($preguntaId, (int) $primero->id);
        Invitaciones::marcarVista($preguntaId, (int) $segundo->id);

        $this->assertSame($primero->name, Invitaciones::preguntas($id)->first()->visto_por);
    }

    /** Una pregunta de OTRA participación no se atiende desde ésta. */
    public function test_no_se_atiende_una_pregunta_ajena(): void
    {
        Queue::fake();
        $gestor = $this->usuarioCon('campaign_manager');
        $mio = $this->participacion(500.0);
        $ajeno = $this->participacion(500.0);

        $token = Invitaciones::invitar($this->campana(), $this->fila($ajeno), (int) $gestor->id);
        Invitaciones::preguntar($token, 'Una duda del otro', '203.0.113.9');
        $preguntaId = (int) Invitaciones::preguntas($ajeno)->first()->id;

        $this->actingAs($gestor)
            ->post(route('campanas.candidatos.pregunta', [$this->uuid, $mio, $preguntaId]))
            ->assertNotFound();

        $this->assertNull(Invitaciones::preguntas($ajeno)->first()->seen_at);
    }

    // ------------------------------------------- el aviso a quien invito

    public function test_aceptar_avisa_a_quien_invito(): void
    {
        Event::fake([CorreoPedido::class]);
        $invitador = $this->usuarioCon('campaign_manager');
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), (int) $invitador->id);

        Invitaciones::aceptar($token, '203.0.113.9');

        Event::assertDispatched(
            CorreoPedido::class,
            fn (CorreoPedido $e): bool => $e->codigo === 'campaign.invitation_accepted'
                && $e->destinatario === $invitador->email
                && str_contains((string) $e->variables['importe'], '500.00'),
        );
    }

    public function test_rechazar_avisa_con_el_motivo(): void
    {
        Event::fake([CorreoPedido::class]);
        $invitador = $this->usuarioCon('campaign_manager');
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), (int) $invitador->id);

        Invitaciones::rechazar($token, 'amount', 'Muy poco', '203.0.113.9');

        Event::assertDispatched(
            CorreoPedido::class,
            fn (CorreoPedido $e): bool => $e->codigo === 'campaign.invitation_declined'
                && $e->destinatario === $invitador->email
                && $e->variables['motivo'] === Invitaciones::MOTIVOS['amount'],
        );
    }

    /**
     * Y si el invitador se desactivó, **no se le escribe** y la respuesta sigue.
     *
     * Escribir a la dirección de alguien que ya no está no avisa a nadie y sí
     * puede acabar en un buzón reasignado. El hecho no se pierde: queda en
     * `domain_events` y se ve en la lista de candidatos.
     *
     * El precio, dicho: una invitación mandada por quien luego se va queda
     * **muda**. Se acepta porque la alternativa —avisar a todo el equipo— se
     * descartó expresamente, y porque la pantalla lo enseña igual.
     */
    public function test_con_el_invitador_desactivado_no_se_avisa_pero_la_respuesta_sigue(): void
    {
        Event::fake([CorreoPedido::class]);
        $invitador = $this->usuarioCon('campaign_manager');
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), (int) $invitador->id);

        DB::table('users')->where('id', $invitador->id)->update(['status' => 'deactivated']);

        $this->assertTrue(Invitaciones::aceptar($token, '203.0.113.9')['ok']);
        $this->assertSame('accepted', $this->fila($id)->status);

        Event::assertNotDispatched(
            CorreoPedido::class,
            fn (CorreoPedido $e): bool => $e->codigo === 'campaign.invitation_accepted',
        );

        // Y el hecho NO se pierde.
        $this->assertSame(1, DB::table('domain_events')
            ->where('event_name', 'campaign_creator.accepted')->where('entity_id', $id)->count());
    }

    // ------------------------------------------------------------------ apoyo

    private function invitador(): int
    {
        return (int) $this->usuarioCon('campaign_manager')->id;
    }

    private function campana(): object
    {
        return DB::table('campaigns')->where('id', $this->campanaId)->first();
    }

    private function fila(int $id): object
    {
        return DB::table('campaign_creators')->where('id', $id)->first();
    }

    /**
     * Retrasa la invitación en el tiempo, en vez de sólo adelantar su caducidad.
     *
     * `ck_inv_dates` exige `expires_at > sent_at`, así que empujar sólo la
     * caducidad al pasado lo rechaza la base — con razón. Se mueven las dos, que
     * además es lo que pasa de verdad: la invitación se mandó hace días.
     */
    private function vencer(int $id): void
    {
        DB::table('invitations')->where('campaign_creator_id', $id)->update([
            'sent_at' => now()->subDays(5),
            'expires_at' => now()->subMinute(),
        ]);
    }

    private function participacion(float $importe): int
    {
        $creadorId = $this->creadorActivo();
        ListaCorta::anadir($this->campana(), $creadorId);

        $id = (int) DB::table('campaign_creators')
            ->where('campaign_id', $this->campanaId)->where('creator_id', $creadorId)->value('id');

        if ($importe > 0.0) {
            DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => $importe]);
        }

        return $id;
    }
}
