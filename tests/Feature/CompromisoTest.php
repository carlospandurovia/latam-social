<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Campaign\Services\Compromiso;
use App\Modules\Campaign\Services\ListaCorta;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El compromiso económico con los creadores (iteración 7.5).
 *
 * ### `BR-CAMPAIGN-005`: el dato que la regla nombraba no existía
 *
 * > *El costo comprometido con creadores no puede exceder el **presupuesto de
 * > creadores de la campaña** sin aprobación explícita de un rol autorizado, que
 * > queda auditada.* 🔴
 *
 * `campaigns` tenía `revenue_amount` —lo que se le cobra al cliente— y nada más.
 * Cuarto caso del patrón de 7.1 a 7.4 y el peor: en los otros faltaba el código,
 * aquí faltaba **la columna**.
 *
 * ### `BR-CREATOR-008`: se fija al invitar, se congela al aceptar
 *
 * Decisión de negocio (2026-08-26). La invitación lleva el precio —el creador no
 * puede aceptar un número que no ha visto— y en cuanto acepta, cambiarlo exige
 * una enmienda. Lo impide un disparador y no el controlador: de este número sale
 * lo que se le paga a una persona.
 */
final class CompromisoTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $campanaId;

    private string $uuid;

    private int $clienteId;

    private int $marcaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');

        $this->clienteId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'commercial_name' => 'ACME', 'client_code' => 'ACME-01',
            'country_id' => $paisPE, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->marcaId = (int) DB::table('client_brands')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_organization_id' => $this->clienteId,
            'name' => 'Marca ACME', 'slug' => 'marca-acme', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->campanaId = $this->campanaDe($this->clienteId, $this->marcaId, [
            'revenue_amount' => 15000, 'creator_budget_amount' => 1000,
        ]);
        $this->uuid = (string) DB::table('campaigns')->where('id', $this->campanaId)->value('uuid');
        $this->mercadoDe($this->campanaId, $paisPE);

        $this->assertSame(1000.0, (float) $this->campana()->creator_budget_amount,
            'la premisa: la campana tiene presupuesto de creadores');
    }

    // ---------------------------------------------- lo que cuenta y lo que no

    /** **La afirmación que descubre si las demás mienten.** Dentro del techo, entra. */
    public function test_un_importe_que_cabe_no_se_veta(): void
    {
        $this->assertNull(Compromiso::vetoPorPresupuesto($this->campana(), 400.0));
    }

    public function test_un_importe_que_no_cabe_se_veta_con_los_tres_numeros(): void
    {
        $this->participacion(700.0);

        $aviso = (string) Compromiso::vetoPorPresupuesto($this->campana(), 500.0);

        $this->assertStringContainsString('1,200.00', $aviso, 'lo que quedaria comprometido');
        $this->assertStringContainsString('1,000.00', $aviso, 'el techo');
        $this->assertStringContainsString('200.00', $aviso, 'por cuanto se pasa');
        $this->assertStringContainsString('BR-CAMPAIGN-005', $aviso);
    }

    /** Sin presupuesto no se compromete nada, y el mensaje dice qué hacer. */
    public function test_sin_presupuesto_no_se_compromete_dinero(): void
    {
        DB::table('campaigns')->where('id', $this->campanaId)->update(['creator_budget_amount' => 0]);

        $aviso = (string) Compromiso::vetoPorPresupuesto($this->campana(), 1.0);

        $this->assertStringContainsString('no tiene presupuesto', $aviso);
    }

    /**
     * Un creador que rechazó **no** consume presupuesto.
     *
     * Contarlo dejaría campañas bloqueadas por dinero que nadie se va a gastar.
     */
    public function test_las_participaciones_muertas_no_consumen_presupuesto(): void
    {
        $viva = $this->participacion(600.0);
        $muerta = $this->participacion(600.0);
        DB::table('campaign_creators')->where('id', $muerta)
            ->update(['status' => 'declined', 'declined_at' => now()]);

        $this->assertSame(600.0, Compromiso::comprometido($this->campanaId));
        $this->assertNull(Compromiso::vetoPorPresupuesto($this->campana(), 400.0),
            'con 600 vivos, 400 mas caben en 1000');
        $this->assertGreaterThan(0, $viva);
    }

    /**
     * Subir el importe de una participación no cuenta dos veces el que ya tenía.
     *
     * Sin `$excepto`, subir de 500 a 600 parecía gastar 1.100 y se vetaba a sí
     * mismo. Es el fallo que este parámetro existe para impedir.
     */
    public function test_cambiar_un_importe_no_cuenta_dos_veces_el_anterior(): void
    {
        $id = $this->participacion(500.0);

        $this->assertNotNull(Compromiso::vetoPorPresupuesto($this->campana(), 600.0),
            'sin excluirla, 500 + 600 se pasa de 1000');
        $this->assertNull(Compromiso::vetoPorPresupuesto($this->campana(), 600.0, $id),
            'excluyendola, 600 cabe de sobra');
    }

    // ------------------------------------------------- la autorizacion

    public function test_la_autorizacion_de_finanzas_levanta_el_techo(): void
    {
        $this->participacion(900.0);

        $this->assertNotNull(Compromiso::vetoPorPresupuesto($this->campana(), 500.0));

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/campanas/{$this->uuid}/sobrecosto",
                ['budget_override_reason' => 'El cliente amplio el alcance a dos ciudades mas.'])
            ->assertSessionHas('exito');

        $this->assertNull(Compromiso::vetoPorPresupuesto($this->campana(), 500.0));
    }

    public function test_autorizar_exige_un_motivo_de_verdad(): void
    {
        $this->actingAs($this->usuarioCon('finance'))
            ->post("/campanas/{$this->uuid}/sobrecosto", ['budget_override_reason' => 'porque'])
            ->assertSessionHas('aviso');

        $this->assertNull($this->campana()->budget_override_at);
    }

    /** Quien monta la campaña no autoriza el sobrecosto: es de finanzas. */
    public function test_el_gestor_no_autoriza_su_propio_sobrecosto(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/campanas/{$this->uuid}/sobrecosto",
                ['budget_override_reason' => 'Lo necesito para cerrar la campana hoy.'])
            ->assertForbidden();

        $this->assertNull($this->campana()->budget_override_at);
    }

    /**
     * **La prueba que justifica que sea un `CHECK`.**
     *
     * Una autorización sin motivo es una firma sin explicación. Las tres
     * columnas van juntas o no van, ni siquiera por SQL.
     */
    public function test_una_autorizacion_sin_motivo_no_entra_ni_por_sql(): void
    {
        $this->expectException(QueryException::class);

        DB::table('campaigns')->where('id', $this->campanaId)->update([
            'budget_override_by_user_id' => $this->usuarioCon('finance')->id,
            'budget_override_at' => now(),
        ]);
    }

    // --------------------------------------------------- el congelado

    public function test_mientras_no_acepta_el_monto_se_corrige(): void
    {
        $id = $this->participacion(400.0);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/campanas/{$this->uuid}/candidatos/{$id}/monto", ['agreed_amount' => '550.00'])
            ->assertSessionHas('exito');

        $this->assertSame(550.0, (float) DB::table('campaign_creators')->where('id', $id)->value('agreed_amount'));
    }

    public function test_en_cuanto_acepta_el_monto_queda_congelado(): void
    {
        $id = $this->participacion(400.0);
        $this->aceptar($id);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/campanas/{$this->uuid}/candidatos/{$id}/monto", ['agreed_amount' => '900.00'])
            ->assertSessionHas('aviso');

        $this->assertSame(400.0, (float) DB::table('campaign_creators')->where('id', $id)->value('agreed_amount'));
    }

    /** **Y no se descongela ni por SQL.** De este número sale lo que cobra una persona. */
    public function test_el_monto_congelado_no_se_cambia_ni_por_sql(): void
    {
        $id = $this->participacion(400.0);
        $this->aceptar($id);

        $this->expectException(QueryException::class);

        DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => 900]);
    }

    /** Lo demás de una participación aceptada sí se puede tocar. */
    public function test_lo_demas_de_una_participacion_aceptada_si_se_toca(): void
    {
        $id = $this->participacion(400.0);
        $this->aceptar($id);

        // Era `revision_rounds_used`, que en 8.3 se fue de esta tabla: las rondas
        // son por ENTREGABLE. Sirve igual cualquier columna que no sea el monto;
        // lo que se afirma es que el congelado es del importe, no de la fila.
        DB::table('campaign_creators')->where('id', $id)->update(['completed_at' => now()]);

        $this->assertNotNull(DB::table('campaign_creators')->where('id', $id)->value('completed_at'));
    }

    // ------------------------------------ no se invita sin decir cuanto

    public function test_no_se_invita_a_un_creador_con_monto_cero(): void
    {
        $id = $this->participacion(0.0);

        $this->expectException(QueryException::class);

        DB::table('campaign_creators')->where('id', $id)
            ->update(['status' => 'invited', 'invited_at' => now()]);
    }

    /** Salvo que la campaña sea un canje: es lo que 7.2 declaró legítimo. */
    public function test_en_una_campana_gratuita_si_se_invita_con_cero(): void
    {
        DB::table('campaigns')->where('id', $this->campanaId)
            ->update(['revenue_amount' => 0, 'is_gratis' => 1]);
        $id = $this->participacion(0.0);

        DB::table('campaign_creators')->where('id', $id)
            ->update(['status' => 'invited', 'invited_at' => now()]);

        $this->assertSame('invited', DB::table('campaign_creators')->where('id', $id)->value('status'));
    }

    // ------------------------------------------------------- el margen

    public function test_el_margen_es_lo_que_se_cobra_menos_lo_que_se_paga(): void
    {
        $this->participacion(600.0);

        $m = Compromiso::margen($this->campana());

        $this->assertSame(15000.0, $m['ingreso']);
        $this->assertSame(600.0, $m['comprometido']);
        $this->assertSame(14400.0, $m['margen']);
        $this->assertSame(96.0, $m['porcentaje']);
    }

    /** Una campaña gratuita tiene ingreso cero: el porcentaje no se inventa. */
    public function test_en_una_campana_gratuita_el_porcentaje_es_nulo_y_no_una_division_por_cero(): void
    {
        DB::table('campaigns')->where('id', $this->campanaId)
            ->update(['revenue_amount' => 0, 'is_gratis' => 1]);

        $m = Compromiso::margen($this->campana());

        $this->assertNull($m['porcentaje']);
        $this->assertSame(0.0, $m['margen']);
    }

    // ------------------------------------------------------------ pantalla

    public function test_la_pantalla_ensena_el_presupuesto_y_lo_comprometido(): void
    {
        $this->participacion(600.0);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get("/campanas/{$this->uuid}/candidatos")
            ->assertOk()
            ->assertSee('Comprometido con creadores', false)
            ->assertSee('600.00', false);
    }

    public function test_no_se_pone_monto_a_la_participacion_de_otra_campana(): void
    {
        $otra = $this->campanaDe($this->clienteId, $this->marcaId);
        $id = (int) DB::table('campaign_creators')->insertGetId([
            'uuid' => (string) Str::uuid(), 'campaign_id' => $otra,
            'creator_id' => $this->creadorActivo(), 'status' => 'shortlisted',
            'agreed_amount' => 0, 'currency_code' => 'PEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/campanas/{$this->uuid}/candidatos/{$id}/monto", ['agreed_amount' => '100'])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------ apoyo

    private function campana(): object
    {
        return DB::table('campaigns')->where('id', $this->campanaId)->first();
    }

    /** Una participación en la lista corta con el importe que se pida. */
    private function participacion(float $importe): int
    {
        $creadorId = $this->creadorActivo();
        ListaCorta::anadir($this->campana(), $creadorId);
        $id = (int) DB::table('campaign_creators')
            ->where('campaign_id', $this->campanaId)->where('creator_id', $creadorId)->value('id');

        DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => $importe]);

        return $id;
    }

    /**
     * Lleva la participación a `accepted`, que es lo que dispara el congelado.
     *
     * Pasa por `invited` a propósito: `ck_ccr_accepted` exige `accepted_at`, y
     * saltarse la invitación escondería que el importe tiene que estar puesto
     * ANTES —que es justo la mitad de la decisión de negocio de 7.5—.
     */
    private function aceptar(int $id): void
    {
        DB::table('campaign_creators')->where('id', $id)
            ->update(['status' => 'invited', 'invited_at' => now()]);
        DB::table('campaign_creators')->where('id', $id)
            ->update(['status' => 'accepted', 'accepted_at' => now()]);
    }
}
