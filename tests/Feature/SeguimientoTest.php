<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Campaign\Services\Invitaciones;
use App\Modules\Campaign\Services\ListaCorta;
use App\Modules\Campaign\Services\Seguimiento;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Database\Seeders\PlantillasDeCorreoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El panel de seguimiento de una campaña (iteración 7.7).
 *
 * ### Qué preguntas contesta esta pantalla
 *
 * | Pregunta | Qué la contesta |
 * |---|---|
 * | ¿Por dónde va cada uno? | el embudo |
 * | ¿Me faltan creadores? | el cupo por mercado |
 * | ¿Me cabe otro? | el dinero |
 * | ¿Qué atiendo hoy? | las alertas |
 *
 * ### Las dos que más fácil se cuentan mal
 *
 * **«Cubierto» es aceptado, no invitado.** Una invitación sin contestar es una
 * plaza esperando; contarla como cubierta es cómo se llega al día de arranque
 * con la mitad del equipo.
 *
 * **El margen no llega a la vista si no se puede ver.** No se calcula y luego se
 * esconde: no se calcula (`BR-SEC-001`, 🔴).
 */
final class SeguimientoTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $campanaId;

    private string $uuid;

    private int $paisPE;

    private int $mercadoId;

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
            // Lejos, para que las alertas de fecha no se disparen solas: cada
            // prueba enciende la suya. Un fixture que dispara alertas por su
            // cuenta hace que todas las afirmaciones sobre alertas mientan.
            'starts_on' => now()->addMonths(3)->toDateString(),
            'ends_on' => now()->addMonths(4)->toDateString(),
        ]);
        $this->uuid = (string) DB::table('campaigns')->where('id', $this->campanaId)->value('uuid');
        // SIN cupo declarado, para que la campana arranque sin ninguna alerta y
        // cada prueba encienda la suya. Un fixture que dispara alertas por su
        // cuenta hace que todas las afirmaciones sobre alertas mientan: la que
        // dice «sale UNA alerta» sale verde con dos.
        // `target_creators` a NULL EXPLICITO: el fixture pone 5 por omision, y
        // con cupo declarado la campana nace ya con la alerta de «faltan 5 de 5».
        $this->mercadoId = $this->mercadoDe($this->campanaId, $this->paisPE, ['target_creators' => null]);

        $this->assertSame([], Seguimiento::alertas($this->campana()),
            'la premisa: la campana arranca sin alertas, y cada prueba enciende la suya');
    }

    // ------------------------------------------------------------- el embudo

    public function test_el_embudo_ensena_todos_los_pasos_aunque_esten_a_cero(): void
    {
        $embudo = Seguimiento::embudo($this->campanaId);

        $this->assertSame(array_keys(Seguimiento::EMBUDO), array_keys($embudo['pasos']));
        $this->assertSame(0, $embudo['total']);
    }

    public function test_el_embudo_cuenta_donde_esta_cada_uno(): void
    {
        Queue::fake();
        $this->participacion(500.0);
        $this->invitado(500.0);
        $this->aceptado(500.0);

        $embudo = Seguimiento::embudo($this->campanaId);

        $this->assertSame(1, $embudo['pasos']['shortlisted']);
        $this->assertSame(1, $embudo['pasos']['invited']);
        $this->assertSame(1, $embudo['pasos']['accepted']);
        $this->assertSame(3, $embudo['vivos']);
    }

    /**
     * Los que salieron **no** cuentan como vivos.
     *
     * Sumarlos al embudo haría que el total pareciera progreso: tres rechazos
     * se leerían igual que tres aceptaciones.
     */
    public function test_los_que_salieron_se_cuentan_aparte(): void
    {
        Queue::fake();
        $vivo = $this->participacion(500.0);
        $muerto = $this->participacion(500.0);
        DB::table('campaign_creators')->where('id', $muerto)
            ->update(['status' => 'declined', 'declined_at' => now()]);

        $embudo = Seguimiento::embudo($this->campanaId);

        $this->assertSame(1, $embudo['vivos']);
        $this->assertSame(1, $embudo['salidas']['declined']);
        $this->assertSame(2, $embudo['total'], 'pero en el total si estan');
        $this->assertGreaterThan(0, $vivo);
    }

    public function test_los_participantes_salen_en_el_orden_del_embudo(): void
    {
        Queue::fake();
        // Se crean al reves del orden en que tienen que salir.
        $this->aceptado(500.0);
        $this->participacion(500.0);

        $estados = Seguimiento::participantes($this->campanaId)->pluck('status')->all();

        $this->assertSame(['shortlisted', 'accepted'], $estados);
    }

    // -------------------------------------------------------------- el cupo

    /**
     * **La afirmación que descubre si las demás mienten.**
     *
     * Cubierto es aceptado, **no** invitado.
     */
    public function test_un_invitado_no_cuenta_como_cupo_cubierto(): void
    {
        Queue::fake();
        $this->conCupo(3);
        $this->invitado(500.0);

        $cupo = Seguimiento::cupos($this->campanaId)->first();

        $this->assertSame(0, $cupo->cubiertos, 'una invitacion sin contestar es una plaza ESPERANDO');
        $this->assertSame(1, $cupo->invitados);
        $this->assertSame(3, $cupo->faltan);
    }

    public function test_un_aceptado_si_cubre(): void
    {
        Queue::fake();
        $this->conCupo(3);
        $this->aceptado(500.0);

        $cupo = Seguimiento::cupos($this->campanaId)->first();

        $this->assertSame(1, $cupo->cubiertos);
        $this->assertSame(2, $cupo->faltan);
    }

    /**
     * Un mercado **sin cupo declarado** no puede estar corto.
     *
     * `target_creators` a `NULL` significa «nadie dijo cuántos hacen falta», y
     * eso no es cero: no hay contra qué comparar.
     */
    public function test_un_mercado_sin_cupo_declarado_no_falta_de_nada(): void
    {
        DB::table('campaign_markets')->where('id', $this->mercadoId)
            ->update(['target_creators' => null]);

        $this->assertNull(Seguimiento::cupos($this->campanaId)->first()->faltan);
        $this->assertSame([], Seguimiento::alertas($this->campana()));
    }

    public function test_un_cupo_completo_no_falta_de_nada(): void
    {
        Queue::fake();
        $this->conCupo(1);
        $this->aceptado(500.0);

        $this->assertSame(0, Seguimiento::cupos($this->campanaId)->first()->faltan);
        $this->assertSame([], Seguimiento::alertas($this->campana()));
    }

    // ------------------------------------------------------------- el dinero

    public function test_el_dinero_sale_de_lo_comprometido_con_los_vivos(): void
    {
        Queue::fake();
        $this->aceptado(1200.0);

        $dinero = Seguimiento::dinero($this->campana());

        $this->assertSame(5000.0, $dinero['presupuesto']);
        $this->assertSame(1200.0, $dinero['comprometido']);
        $this->assertSame(3800.0, $dinero['disponible']);
    }

    /** Y se enseña **negativo**: redondearlo a cero escondería el caso que hay que ver. */
    public function test_el_disponible_puede_salir_negativo(): void
    {
        Queue::fake();
        DB::table('campaigns')->where('id', $this->campanaId)->update([
            'creator_budget_amount' => 100,
            'budget_override_at' => now(),
            'budget_override_by_user_id' => $this->usuarioCon('finance')->id,
            'budget_override_reason' => 'El cliente subio el alcance',
        ]);
        $this->aceptado(500.0);

        $dinero = Seguimiento::dinero($this->campana());

        $this->assertSame(-400.0, $dinero['disponible']);
        $this->assertTrue($dinero['autorizado']);
    }

    // ----------------------------------------------------------- las alertas

    public function test_alerta_de_cupo_sin_cubrir(): void
    {
        Queue::fake();
        $this->conCupo(3);
        $this->aceptado(500.0);

        $alertas = Seguimiento::alertas($this->campana());

        $this->assertCount(1, $alertas);
        $this->assertStringContainsString('faltan 2 de 3', $alertas[0]['titulo']);
        $this->assertStringContainsString('No hay ninguna invitacion pendiente', $alertas[0]['detalle']);
    }

    /** Y si hay invitaciones en el aire, lo dice: cambia lo que hay que hacer. */
    public function test_la_alerta_de_cupo_cuenta_las_invitaciones_en_el_aire(): void
    {
        Queue::fake();
        $this->conCupo(3);
        $this->aceptado(500.0);
        $this->invitado(500.0);

        $alertas = Seguimiento::alertas($this->campana());
        $cupo = collect($alertas)->first(fn (array $a): bool => str_contains($a['titulo'], 'faltan'));

        $this->assertStringContainsString('1 invitacion(es) sin contestar', $cupo['detalle']);
    }

    public function test_alerta_de_invitacion_a_punto_de_caducar(): void
    {
        Queue::fake();
        $id = $this->invitado(500.0);
        DB::table('invitations')->where('campaign_creator_id', $id)
            ->update(['expires_at' => now()->addHours(2)]);

        $alertas = Seguimiento::alertas($this->campana());
        $urgente = collect($alertas)->first(fn (array $a): bool => str_contains($a['titulo'], 'caducan'));

        $this->assertNotNull($urgente);
        // Dice A QUIEN hay que llamar: una alerta que obliga a ir a buscarlo
        // deja de leerse.
        $this->assertStringContainsString(
            (string) DB::table('creators')->orderByDesc('id')->value('display_name'),
            $urgente['detalle'],
        );
    }

    public function test_una_invitacion_con_margen_no_alerta(): void
    {
        Queue::fake();
        $this->invitado(500.0);

        // Nace con 72 h por delante: no es urgente.
        $this->assertSame([], Seguimiento::alertas($this->campana()));
    }

    public function test_alerta_de_pregunta_sin_atender(): void
    {
        Queue::fake();
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), (int) $this->usuarioCon('admin')->id);
        Invitaciones::preguntar($token, 'Cuando llega el producto?', '203.0.113.9');

        $alertas = Seguimiento::alertas($this->campana());

        $this->assertCount(1, $alertas);
        $this->assertStringContainsString('1 pregunta(s)', $alertas[0]['titulo']);
    }

    public function test_una_pregunta_atendida_deja_de_alertar(): void
    {
        Queue::fake();
        $gestor = $this->usuarioCon('admin');
        $id = $this->participacion(500.0);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), (int) $gestor->id);
        Invitaciones::preguntar($token, 'Una duda', '203.0.113.9');

        Invitaciones::marcarVista((int) Invitaciones::preguntas($id)->first()->id, (int) $gestor->id);

        $this->assertSame([], Seguimiento::alertas($this->campana()));
    }

    public function test_alerta_de_campana_que_empieza_pronto_sin_confirmar(): void
    {
        DB::table('campaigns')->where('id', $this->campanaId)->update([
            'status' => 'draft', 'confirmed_at' => null,
            'starts_on' => now()->addDays(5)->toDateString(),
        ]);

        $alertas = Seguimiento::alertas($this->campana());

        $this->assertCount(1, $alertas);
        $this->assertStringContainsString('Empieza en 5 dia(s)', $alertas[0]['titulo']);
        $this->assertSame('ambar', $alertas[0]['nivel']);
    }

    /** Y si ya debería haber empezado, en rojo y con otro texto. */
    public function test_una_campana_que_ya_deberia_haber_empezado_sale_en_rojo(): void
    {
        DB::table('campaigns')->where('id', $this->campanaId)->update([
            'status' => 'draft', 'confirmed_at' => null,
            'starts_on' => now()->subDays(2)->toDateString(),
        ]);

        $alertas = Seguimiento::alertas($this->campana());

        $this->assertSame('rojo', $alertas[0]['nivel']);
        $this->assertStringContainsString('ya deberia haber empezado', $alertas[0]['titulo']);
    }

    public function test_una_campana_confirmada_no_alerta_por_la_fecha(): void
    {
        DB::table('campaigns')->where('id', $this->campanaId)
            ->update(['starts_on' => now()->addDays(2)->toDateString()]);

        $this->assertSame([], Seguimiento::alertas($this->campana()));
    }

    /** El cupo corto con el arranque encima sube a rojo. */
    public function test_el_cupo_corto_con_la_fecha_encima_sube_a_rojo(): void
    {
        Queue::fake();
        $this->conCupo(3);
        DB::table('campaigns')->where('id', $this->campanaId)
            ->update(['starts_on' => now()->addDays(3)->toDateString()]);
        $this->aceptado(500.0);

        $alertas = Seguimiento::alertas($this->campana());
        $cupo = collect($alertas)->first(fn (array $a): bool => str_contains($a['titulo'], 'faltan'));

        $this->assertSame('rojo', $cupo['nivel']);
    }

    // ---------------------------------------------------------- la pantalla

    public function test_la_pantalla_se_pinta(): void
    {
        Queue::fake();
        $this->aceptado(1200.0);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('campanas.seguimiento', $this->uuid))
            ->assertOk()
            ->assertSee('Por dónde va cada uno', false)
            ->assertSee('1,200.00')
            ->assertSee('3,800.00');
    }

    /** **`BR-SEC-001`.** El margen no llega a quien no puede verlo. */
    public function test_sin_permiso_de_margen_el_margen_no_llega_a_la_vista(): void
    {
        Queue::fake();
        $this->aceptado(1200.0);

        // `content_reviewer` tiene `campaign.view` y NO `campaign.view_margin`.
        $respuesta = $this->actingAs($this->usuarioCon('content_reviewer'))
            ->get(route('campanas.seguimiento', $this->uuid))
            ->assertOk();

        $this->assertNull($respuesta->viewData('margen'), 'el dato ni siquiera se calcula');
        // 15.000 de ingreso y 13.800 de margen: ninguno de los dos aparece.
        $respuesta->assertDontSee('15,000.00')->assertDontSee('13,800.00');
        // Pero el presupuesto SI: sin el no se decide a quien invitar.
        $respuesta->assertSee('5,000.00');
    }

    /**
     * `finance` y ya **no** `campaign_manager` (`DEC-181`, 9.10a).
     *
     * Quien lleva la campaña carga sus gastos y ve cuánto lleva gastado; el
     * margen —lo que se gana— es una cifra de dirección. Esta prueba llevaba
     * desde 7.7 usando `campaign_manager` porque entonces lo tenía.
     */
    public function test_con_permiso_el_margen_si_sale(): void
    {
        Queue::fake();
        $this->aceptado(1200.0);

        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('campanas.seguimiento', $this->uuid))
            ->assertOk()
            ->assertSee('13,800.00')
            // 9.10a: y dice de qué está hecho. Este margen no resta el producto
            // ni los envíos, así que sale más alto de lo que es.
            ->assertSee('No resta los gastos', false);
    }

    /**
     * **`DEC-181`.** Quien lleva la campaña ya no ve el margen.
     *
     * Es el mismo caso de arriba con el rol que lo tenía hasta 9.10a: la prueba
     * existe para que quitar el permiso no se deshaga por descuido.
     */
    public function test_quien_lleva_la_campana_ya_no_ve_el_margen(): void
    {
        Queue::fake();
        $this->aceptado(1200.0);

        $respuesta = $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('campanas.seguimiento', $this->uuid))
            ->assertOk();

        $this->assertNull($respuesta->viewData('margen'), 'el dato ni siquiera se calcula');
        $respuesta->assertDontSee('13,800.00');
    }

    public function test_la_pantalla_exige_poder_ver_campanas(): void
    {
        $this->actingAs($this->usuarioCon(null))
            ->get(route('campanas.seguimiento', $this->uuid))
            ->assertForbidden();
    }

    public function test_una_campana_que_no_existe_da_404(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('campanas.seguimiento', (string) Str::uuid()))
            ->assertNotFound();
    }

    public function test_sin_nadie_dentro_la_pantalla_lo_dice_y_no_revienta(): void
    {

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('campanas.seguimiento', $this->uuid))
            ->assertOk()
            ->assertSee('Todavía no hay nadie', false);
    }

    // ------------------------------------------------------------------ apoyo

    private function conCupo(int $cuantos): void
    {
        DB::table('campaign_markets')->where('id', $this->mercadoId)
            ->update(['target_creators' => $cuantos]);
    }

    private function campana(): object
    {
        return DB::table('campaigns')->where('id', $this->campanaId)->first();
    }

    private function fila(int $id): object
    {
        return DB::table('campaign_creators')->where('id', $id)->first();
    }

    private function participacion(float $importe): int
    {
        $creadorId = $this->creadorActivo();
        ListaCorta::anadir($this->campana(), $creadorId);

        $id = (int) DB::table('campaign_creators')
            ->where('campaign_id', $this->campanaId)->where('creator_id', $creadorId)->value('id');

        DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => $importe]);

        return $id;
    }

    /** Invitado **de verdad**, con su invitación: las alertas la miran. */
    private function invitado(float $importe): int
    {
        $id = $this->participacion($importe);
        Invitaciones::invitar($this->campana(), $this->fila($id), (int) $this->usuarioCon('admin')->id);

        return $id;
    }

    private function aceptado(float $importe): int
    {
        $id = $this->participacion($importe);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), (int) $this->usuarioCon('admin')->id);
        Invitaciones::aceptar($token, '203.0.113.9');

        return $id;
    }
}
