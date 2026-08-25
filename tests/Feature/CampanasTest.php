<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Campaign\Services\EstadosDeCampana as E;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Campañas (iteración 7.1).
 *
 * Lo que esta pantalla existe para impedir son dos cosas, y las dos son de
 * dinero:
 *
 * 1. Que una campaña salga de borrador **sin saber quién la factura**
 *    (`BR-LE-004`).
 * 2. Que alguien **salte estados**: `ck_camp_status` admite ocho valores y no
 *    dice nada de cómo se pasa de uno a otro.
 *
 * Y una tercera, que es la que sólo se paga años después: que la sociedad
 * emisora **se pueda cambiar** después de comprometerla (`BR-LE-002`). De eso
 * depende que una campaña de 2026 siga sabiendo quién la facturó cuando la
 * cobertura ya sea otra.
 */
final class CampanasTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $paisPE;

    private int $clienteId;

    private int $marcaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');

        $gestor = $this->usuarioCon('campaign_manager');
        $this->actingAs($gestor)->post('/clientes', [
            'commercial_name' => 'ACME', 'client_code' => 'ACME-01',
            'country_id' => $this->paisPE, 'status' => 'prospect',
        ]);

        $this->clienteId = (int) DB::table('client_organizations')->where('client_code', 'ACME-01')->value('id');
        $this->marcaId = (int) DB::table('client_brands')->where('client_organization_id', $this->clienteId)->value('id');

        // La premisa: Peru esta cubierto por el seeder. Si no lo estuviera,
        // media prueba saldria verde por el motivo equivocado.
        $this->assertGreaterThan(0, DB::table('legal_entity_countries')
            ->where('country_id', $this->paisPE)->whereNull('valid_to')->count());
    }

    public function test_alta_de_una_campana_resuelve_quien_la_factura(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post('/campanas', $this->campana())
            ->assertSessionHas('exito');

        $fila = DB::table('campaigns')->where('name', 'Lanzamiento verano')->first();

        $this->assertSame('draft', $fila->status);
        $this->assertNotNull($fila->billing_legal_entity_id, 'BR-LE-001: se guarda, no se deduce');
        $this->assertNotNull($fila->code, 'el codigo lo pone el sistema');
        $this->assertNull($fila->confirmed_at);
    }

    /**
     * **La prueba de `BR-LE-003`.**
     *
     * La sociedad se resuelve a `starts_on`, no a hoy. Se cierra la cobertura
     * de Perú antes de la fecha de inicio: la campaña tiene que quedarse sin
     * sociedad aunque HOY el país sí esté cubierto.
     */
    public function test_la_sociedad_se_resuelve_a_la_fecha_de_inicio_y_no_a_hoy(): void
    {
        DB::table('legal_entity_countries')
            ->where('country_id', $this->paisPE)->whereNull('valid_to')
            ->update(['valid_to' => '2026-12-31']);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post('/campanas', $this->campana(['starts_on' => '2027-03-01', 'ends_on' => '2027-03-31']))
            ->assertSessionHas('aviso');

        $fila = DB::table('campaigns')->where('name', 'Lanzamiento verano')->first();

        $this->assertNull($fila->billing_legal_entity_id, 'en 2027 ya no hay cobertura');
        $this->assertStringContainsString('Ninguna sociedad', (string) session('aviso'));
    }

    /** `BR-LE-004`: sin cobertura no se sale de borrador, y se dice por qué. */
    public function test_sin_sociedad_no_sale_de_borrador(): void
    {
        // Se cierra el MISMO dia en que empezo, no en 2020: `ck_lec_dates` exige
        // `valid_to >= valid_from`, y poner una fecha anterior al inicio del
        // periodo es justo lo que esa regla existe para impedir. `valid_to` es
        // inclusivo, asi que esto deja el pais cubierto exactamente un dia.
        DB::table('legal_entity_countries')
            ->where('country_id', $this->paisPE)->whereNull('valid_to')
            ->update(['valid_to' => DB::raw('valid_from')]);

        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crear($gestor);

        // `pending_approval` SI se puede: `ck_camp_billing_entity` deja pasar
        // los tres estados iniciales sin sociedad, porque un borrador todavia
        // se esta escribiendo. El limite real es `approved`, y esta prueba lo
        // descubrio al ponerse roja afirmando el limite equivocado.
        $this->actingAs($gestor)
            ->post("/campanas/{$uuid}/estado", ['estado' => E::EN_APROBACION])
            ->assertSessionHas('exito');

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/campanas/{$uuid}/estado", ['estado' => E::APROBADA])
            ->assertSessionHas('aviso');

        $this->assertSame('pending_approval', DB::table('campaigns')->where('uuid', $uuid)->value('status'));
        $this->assertStringContainsString('Entidades legales', (string) session('aviso'),
            'el mensaje tiene que decir DONDE se arregla');
    }

    // ----------------------------------------------------------- transiciones

    public function test_no_se_salta_de_borrador_a_terminada(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crear($gestor);

        $this->actingAs($gestor)
            ->post("/campanas/{$uuid}/estado", ['estado' => E::TERMINADA])
            ->assertSessionHas('aviso');

        $this->assertSame('draft', DB::table('campaigns')->where('uuid', $uuid)->value('status'));
        $this->assertStringContainsString('En aprobación', (string) session('aviso'));
    }

    /** Aprobar es de finanzas. Quien monta la campaña no la aprueba. */
    public function test_el_gestor_no_puede_aprobar(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crear($gestor);

        $this->actingAs($gestor)->post("/campanas/{$uuid}/estado", ['estado' => E::EN_APROBACION]);

        $this->actingAs($gestor)
            ->post("/campanas/{$uuid}/estado", ['estado' => E::APROBADA])
            ->assertForbidden();

        $this->assertSame('pending_approval', DB::table('campaigns')->where('uuid', $uuid)->value('status'));
    }

    public function test_finanzas_aprueba_y_eso_confirma_la_campana(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crear($gestor);
        $this->actingAs($gestor)->post("/campanas/{$uuid}/estado", ['estado' => E::EN_APROBACION]);

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/campanas/{$uuid}/estado", ['estado' => E::APROBADA])
            ->assertSessionHas('exito');

        $fila = DB::table('campaigns')->where('uuid', $uuid)->first();

        $this->assertSame('approved', $fila->status);
        $this->assertNotNull($fila->confirmed_at, 'aprobar confirma');
        $this->assertStringContainsString('ya no se puede cambiar', (string) session('exito'));
    }

    /**
     * **La prueba que justifica la iteración.**
     *
     * Una vez confirmada, la sociedad que factura no se cambia ni con un
     * `UPDATE` directo. Lo impide un disparador y no el controlador, porque de
     * este dato depende que una factura de dentro de dos años siga sabiendo
     * quién la emitió, y eso tiene que sobrevivir a un mantenimiento y a la
     * próxima pantalla que alguien escriba.
     */
    public function test_la_sociedad_de_una_campana_confirmada_no_se_cambia_ni_por_sql(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crear($gestor);
        $this->actingAs($gestor)->post("/campanas/{$uuid}/estado", ['estado' => E::EN_APROBACION]);
        $this->actingAs($this->usuarioCon('finance'))->post("/campanas/{$uuid}/estado", ['estado' => E::APROBADA]);

        $otra = $this->entidadLegal(['code' => 'OTRA-71']);

        $this->expectException(QueryException::class);

        DB::table('campaigns')->where('uuid', $uuid)->update(['billing_legal_entity_id' => $otra]);
    }

    /** Y mientras es borrador, sí se corrige: es el margen para un dedazo. */
    public function test_en_borrador_la_sociedad_todavia_se_recalcula(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crear($gestor);

        $this->assertNotNull(DB::table('campaigns')->where('uuid', $uuid)->value('billing_legal_entity_id'));

        // Se mueve la fecha a un ano sin cobertura: la sociedad se recalcula.
        DB::table('legal_entity_countries')
            ->where('country_id', $this->paisPE)->whereNull('valid_to')
            ->update(['valid_to' => '2026-12-31']);

        $this->actingAs($gestor)
            ->put("/campanas/{$uuid}", $this->campana(['starts_on' => '2027-03-01', 'ends_on' => '2027-03-31']))
            ->assertSessionHas('exito');

        $this->assertNull(DB::table('campaigns')->where('uuid', $uuid)->value('billing_legal_entity_id'),
            'mover la fecha mueve quien factura, mientras se pueda');
    }

    public function test_una_campana_confirmada_no_se_edita(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crear($gestor);
        $this->actingAs($gestor)->post("/campanas/{$uuid}/estado", ['estado' => E::EN_APROBACION]);
        $this->actingAs($this->usuarioCon('finance'))->post("/campanas/{$uuid}/estado", ['estado' => E::APROBADA]);

        $this->actingAs($gestor)->get("/campanas/{$uuid}/editar")->assertStatus(409);
    }

    // ------------------------------------------------------------ validacion

    /**
     * La marca tiene que ser DEL cliente. Nada en el esquema lo obliga: una
     * foránea sólo comprueba que la marca exista.
     */
    public function test_no_se_hace_una_campana_con_la_marca_de_otro_cliente(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');

        $this->actingAs($gestor)->post('/clientes', [
            'commercial_name' => 'OTRO', 'client_code' => 'OTRO-01',
            'country_id' => $this->paisPE, 'status' => 'prospect',
        ]);
        $otroCliente = (int) DB::table('client_organizations')->where('client_code', 'OTRO-01')->value('id');

        $this->actingAs($gestor)
            ->post('/campanas', $this->campana(['client_organization_id' => $otroCliente]))
            ->assertSessionHasErrors('client_brand_id');

        $this->assertSame(0, DB::table('campaigns')->count());
    }

    /** Una fecha sin ceros no se cuela: de ella depende qué sociedad factura. */
    public function test_una_fecha_sin_ceros_se_rechaza(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post('/campanas', $this->campana(['starts_on' => '2026-2-1']))
            ->assertSessionHasErrors('starts_on');

        $this->assertSame(0, DB::table('campaigns')->count());
    }

    public function test_no_termina_antes_de_empezar(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post('/campanas', $this->campana(['starts_on' => '2026-06-01', 'ends_on' => '2026-05-01']))
            ->assertSessionHasErrors('ends_on');
    }

    // ------------------------------------------------------------- permisos

    public function test_ver_no_es_gestionar(): void
    {
        $uuid = $this->crear($this->usuarioCon('campaign_manager'));
        $revisor = $this->usuarioCon('content_reviewer');

        $this->actingAs($revisor)->get('/campanas')->assertOk();
        $this->actingAs($revisor)->get("/campanas/{$uuid}")->assertOk();
        $this->actingAs($revisor)->get('/campanas/nueva')->assertForbidden();
    }

    /** Sin permiso de ver, ni la lista. */
    public function test_sin_permiso_no_se_ven_las_campanas(): void
    {
        $this->actingAs($this->usuarioCon(null))->get('/campanas')->assertForbidden();
    }

    // ------------------------------------------------------------------ apoyo

    /** @param array<string, mixed> $cambios */
    private function campana(array $cambios = []): array
    {
        return array_merge([
            'name' => 'Lanzamiento verano',
            'client_organization_id' => $this->clienteId,
            'client_brand_id' => $this->marcaId,
            'objective' => 'awareness',
            'currency_code' => (string) DB::table('currencies')->value('code'),
            'revenue_amount' => '15000.00',
            'included_revision_rounds' => 2,
            'min_creator_age' => 18,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
        ], $cambios);
    }

    private function crear(User $quien): string
    {
        $this->actingAs($quien)->post('/campanas', $this->campana());

        return (string) DB::table('campaigns')->where('name', 'Lanzamiento verano')->value('uuid');
    }
}
