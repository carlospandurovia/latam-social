<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tarifas, disponibilidad y agenda del creador (iteración 3.9).
 *
 * La prueba que carga con el peso de la iteración es
 * `test_una_tarifa_nueva_cierra_la_anterior_el_dia_antes`: es la que hace que
 * «cuánto costaba el 1 de mayo» tenga una sola respuesta (`H-16`). Y
 * `test_un_bloqueo_que_pisa_una_campana_aceptada_se_registra_y_avisa` es la que
 * comprueba que marcar no es rechazar (`DEC-070`).
 */
final class PerfilComercialTest extends TestCase
{
    use RefreshDatabase;

    private string $uuid;

    private int $creadorId;

    private int $formatoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->uuid = (string) Str::uuid();
        $this->creadorId = (int) DB::table('creators')->insertGetId([
            'uuid' => $this->uuid,
            'first_name' => 'Ana', 'last_name' => 'Torres', 'display_name' => 'anatorres',
            'birth_date' => '1998-05-12', 'email' => 'ana@ejemplo.test',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'document_country_code' => 'PE', 'document_type' => 'DNI', 'document_number' => '40000001',
            'status' => 'active', 'payment_term_days' => 30, 'preferred_currency_code' => 'PEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->formatoId = (int) DB::table('content_formats')->orderBy('id')->value('id');
    }

    private function usuarioCon(string $rol): User
    {
        $usuario = User::factory()->create();
        DB::table('role_user')->insert([
            'user_id' => $usuario->id,
            'role_id' => DB::table('roles')->where('code', $rol)->value('id'),
            'assigned_at' => now(),
        ]);
        Permisos::olvidar((int) $usuario->id);

        return $usuario;
    }

    /**
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private function tarifa(array $cambios = []): array
    {
        return array_merge([
            'content_format_id' => $this->formatoId,
            'currency_code' => 'PEN',
            'amount' => '1000',
            'source' => 'self_declared',
            'valid_from' => '2026-01-01',
        ], $cambios);
    }

    // ------------------------------------------------------------ autorización

    public function test_ver_la_tarifa_no_es_lo_mismo_que_fijarla(): void
    {
        // La tarifa es el COSTO del creador, no el margen: mirarla basta con
        // `creator.view`. Fijarla pide `creator.rate.manage` (DEC-069).
        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->get("/creadores/{$this->uuid}/comercial")->assertOk();

        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->post("/creadores/{$this->uuid}/comercial/tarifa", $this->tarifa())
            ->assertForbidden();

        $this->assertDatabaseCount('creator_rates', 0);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/creadores/{$this->uuid}/comercial/tarifa", $this->tarifa())
            ->assertRedirect(route('creadores.comercial', $this->uuid));

        $this->assertDatabaseCount('creator_rates', 1);
    }

    // --------------------------------------------------------------- tarifas

    public function test_la_tarifa_dice_quien_la_puso_y_de_donde_sale(): void
    {
        $quien = $this->usuarioCon('campaign_manager');
        $this->actingAs($quien)->post("/creadores/{$this->uuid}/comercial/tarifa", $this->tarifa());

        $t = DB::table('creator_rates')->first();

        $this->assertSame((int) $quien->id, (int) $t->created_by_user_id, 'H-18: alguien firma el precio.');
        $this->assertSame('self_declared', $t->source);
        $this->assertNull($t->valid_to);
        $this->assertSame(0, (int) $t->is_gratis);
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator_rate.set']);
    }

    public function test_sin_decir_de_donde_sale_el_precio_no_se_guarda(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/creadores/{$this->uuid}/comercial/tarifa", $this->tarifa(['source' => null]))
            ->assertSessionHasErrors('source');

        $this->assertDatabaseCount('creator_rates', 0);
    }

    /**
     * LA PRUEBA DE LA ITERACIÓN (`H-16`).
     *
     * `valid_to` es inclusivo. Si la anterior se cerrara el mismo día en que
     * empieza la nueva, las dos estarían vigentes esa fecha y la pregunta
     * «cuánto costaba» volvería a tener dos respuestas.
     */
    public function test_una_tarifa_nueva_cierra_la_anterior_el_dia_antes(): void
    {
        $quien = $this->usuarioCon('campaign_manager');

        $this->actingAs($quien)->post("/creadores/{$this->uuid}/comercial/tarifa", $this->tarifa());
        $this->actingAs($quien)->post("/creadores/{$this->uuid}/comercial/tarifa",
            $this->tarifa(['amount' => '2500', 'source' => 'negotiated', 'valid_from' => '2026-03-01']));

        $anterior = DB::table('creator_rates')->where('amount', 1000)->first();
        $nueva = DB::table('creator_rates')->where('amount', 2500)->first();

        $this->assertSame('2026-02-28', $anterior->valid_to, 'El dia ANTES, no el mismo dia.');
        $this->assertNull($nueva->valid_to);

        // Y la propiedad que todo esto existe para garantizar.
        $enMayo = DB::table('creator_rates')
            ->where('creator_id', $this->creadorId)
            ->where('valid_from', '<=', '2026-05-01')
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', '2026-05-01'))
            ->count();

        $this->assertSame(1, $enMayo, 'El 2026-05-01 tiene que haber UNA tarifa, no dos.');
    }

    public function test_una_tarifa_que_empieza_antes_que_la_vigente_se_rechaza_con_palabras(): void
    {
        $quien = $this->usuarioCon('campaign_manager');
        $this->actingAs($quien)->post("/creadores/{$this->uuid}/comercial/tarifa",
            $this->tarifa(['valid_from' => '2026-06-01']));

        // Cerrarla el dia antes la dejaria terminando antes de empezar. La base
        // lo rechaza igual; el controlador lo dice con palabras.
        $this->actingAs($quien)->post("/creadores/{$this->uuid}/comercial/tarifa",
            $this->tarifa(['amount' => '2000', 'valid_from' => '2026-01-01']))
            ->assertSessionHas('aviso');

        $this->assertDatabaseCount('creator_rates', 1);
    }

    public function test_una_colaboracion_gratuita_se_declara_no_se_teclea_un_cero(): void
    {
        $quien = $this->usuarioCon('campaign_manager');

        // Cero a secas: lo rechaza la validacion antes de llegar a la base.
        $this->actingAs($quien)->post("/creadores/{$this->uuid}/comercial/tarifa",
            $this->tarifa(['amount' => '0']))->assertSessionHasErrors('amount');

        // Declarada gratuita: entra, y el importe queda en cero.
        $this->actingAs($quien)->post("/creadores/{$this->uuid}/comercial/tarifa",
            $this->tarifa(['is_gratis' => '1', 'amount' => null]))
            ->assertRedirect(route('creadores.comercial', $this->uuid));

        $t = DB::table('creator_rates')->first();
        $this->assertSame(1, (int) $t->is_gratis);
        $this->assertSame(0.0, (float) $t->amount);
    }

    public function test_dos_tarifas_solapadas_la_base_lo_impide(): void
    {
        $quien = $this->usuarioCon('campaign_manager');
        $this->actingAs($quien)->post("/creadores/{$this->uuid}/comercial/tarifa", $this->tarifa());

        // Sin pasar por el controlador, que es quien cierra la anterior.
        $this->expectException(QueryException::class);

        DB::table('creator_rates')->insert([
            'creator_id' => $this->creadorId,
            'content_format_id' => $this->formatoId,
            'currency_code' => 'PEN',
            'amount' => 3000, 'source' => 'negotiated',
            'created_by_user_id' => $quien->id,
            'valid_from' => '2026-06-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // --------------------------------------------------------- disponibilidad

    public function test_la_disponibilidad_nueva_cierra_la_anterior_el_dia_antes(): void
    {
        $quien = $this->usuarioCon('campaign_manager');
        $base = ['min_lead_time_days' => 3, 'valid_from' => '2026-01-01'];

        $this->actingAs($quien)->post("/creadores/{$this->uuid}/comercial/disponibilidad", $base);
        $this->actingAs($quien)->post("/creadores/{$this->uuid}/comercial/disponibilidad",
            ['min_lead_time_days' => 10, 'valid_from' => '2026-03-01']);

        $this->assertSame('2026-02-28',
            DB::table('creator_availability')->where('min_lead_time_days', 3)->value('valid_to'));
        $this->assertNull(
            DB::table('creator_availability')->where('min_lead_time_days', 10)->value('valid_to'));
    }

    public function test_si_dice_que_viaja_tiene_que_decir_hasta_donde(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/creadores/{$this->uuid}/comercial/disponibilidad", [
                'accepts_travel' => '1', 'min_lead_time_days' => 3, 'valid_from' => '2026-01-01',
            ])
            ->assertSessionHasErrors('travel_scope');

        $this->assertDatabaseCount('creator_availability', 0);
    }

    // ---------------------------------------------------------- agenda

    public function test_un_bloqueo_no_puede_terminar_antes_de_empezar(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/creadores/{$this->uuid}/comercial/bloqueo",
                ['starts_on' => '2026-08-15', 'ends_on' => '2026-08-01'])
            ->assertSessionHasErrors('ends_on');
    }

    /**
     * `DEC-070`: marcar, no rechazar. Si el creador se opera, el bloqueo es un
     * hecho; lo que el sistema no puede hacer es callárselo.
     */
    public function test_un_bloqueo_que_pisa_una_campana_aceptada_se_registra_y_avisa(): void
    {
        $this->campana('CMP-0001', 'Campaña de julio', '2026-07-01', '2026-07-31', 'accepted');

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/creadores/{$this->uuid}/comercial/bloqueo",
                ['starts_on' => '2026-07-10', 'ends_on' => '2026-07-20', 'reason' => 'Cirugía'])
            ->assertSessionHas('aviso');

        // Se registra igual. Eso es lo que distingue marcar de rechazar.
        $this->assertDatabaseCount('creator_blackouts', 1);
        $this->assertStringContainsString('CMP-0001', (string) session('aviso'));
    }

    public function test_una_campana_solo_invitada_no_produce_aviso(): void
    {
        // Todavia no hay compromiso: invitar no es aceptar.
        $this->campana('CMP-0002', 'Campaña sin aceptar', '2026-07-01', '2026-07-31', 'invited');

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/creadores/{$this->uuid}/comercial/bloqueo",
                ['starts_on' => '2026-07-10', 'ends_on' => '2026-07-20'])
            ->assertSessionHas('exito');

        $this->assertDatabaseCount('creator_blackouts', 1);
    }

    public function test_una_campana_fuera_de_las_fechas_no_produce_aviso(): void
    {
        $this->campana('CMP-0003', 'Campaña de septiembre', '2026-09-01', '2026-09-30', 'accepted');

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/creadores/{$this->uuid}/comercial/bloqueo",
                ['starts_on' => '2026-07-10', 'ends_on' => '2026-07-20'])
            ->assertSessionHas('exito');
    }

    public function test_un_bloqueo_registrado_por_error_se_borra(): void
    {
        $quien = $this->usuarioCon('campaign_manager');
        $this->actingAs($quien)->post("/creadores/{$this->uuid}/comercial/bloqueo",
            ['starts_on' => '2026-07-10', 'ends_on' => '2026-07-20']);

        $id = (int) DB::table('creator_blackouts')->value('id');

        $this->actingAs($quien)
            ->delete("/creadores/{$this->uuid}/comercial/bloqueo/{$id}")
            ->assertRedirect(route('creadores.comercial', $this->uuid));

        $this->assertDatabaseCount('creator_blackouts', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator_blackout.deleted']);
    }

    // ------------------------------------------------------------------ apoyo

    private function campana(string $codigo, string $nombre, string $desde, string $hasta, string $estado): void
    {
        $orgId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_code' => 'CLI-'.substr($codigo, -4),
            'commercial_name' => 'Cliente '.$codigo,
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $marcaId = (int) DB::table('client_brands')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_organization_id' => $orgId,
            'name' => 'Marca '.$codigo,
            'slug' => 'marca-'.mb_strtolower($codigo),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $campanaId = (int) DB::table('campaigns')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'code' => $codigo, 'name' => $nombre,
            'client_organization_id' => $orgId, 'client_brand_id' => $marcaId,
            'currency_code' => 'PEN',
            'starts_on' => $desde, 'ends_on' => $hasta,
            'status' => 'in_progress',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('campaign_creators')->insert([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campanaId,
            'creator_id' => $this->creadorId,
            'currency_code' => 'PEN',
            'status' => $estado,
            'accepted_at' => $estado === 'invited' ? null : now(),
            'invited_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
