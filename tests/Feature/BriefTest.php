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
 * El brief y el ingreso declarado (iteración 7.2).
 *
 * `BR-CAMPAIGN-004` estaba escrita desde el principio —*«una campaña no puede
 * pasar a `approved` sin presupuesto, cliente, marca y brief definidos»*— y
 * **no la impedía nada**. En 7.1 se dejó aprobar una campaña que sólo tenía
 * sociedad emisora: sin decir qué había que entregar y sin que nadie hubiera
 * puesto un precio.
 *
 * Es el tercer caso del mismo patrón en el proyecto (`BR-LE-001` en 7.1,
 * `must_change_password` antes de `T-23`): una regla escrita en
 * `docs/06-BUSINESS-RULES.md`, con su identificador y su color, que ningún
 * `CHECK` y ninguna pantalla comprobaban. Lo que estas pruebas verifican no es
 * que el código haga algo nuevo, sino que la regla **por fin exista**.
 *
 * ### Las dos mitades
 *
 * | Mitad | Dónde vive |
 * |---|---|
 * | Hay algo que entregar | `campaign_requirements`, comprobado en `Campanas::loQueFaltaParaSalirDeBorrador()` |
 * | El ingreso está declarado | `ck_camp_revenue_declarado`, en la base |
 *
 * La segunda está en la base **a propósito**: la pregunta que hay que poder
 * responder dentro de un año es *«¿esta campaña de cero se regaló o se nos
 * olvidó cobrarla?»*, y esa respuesta tiene que sobrevivir a un `UPDATE` de
 * mantenimiento y a la próxima pantalla que alguien escriba.
 */
final class BriefTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $paisPE;

    private int $clienteId;

    private int $marcaId;

    private int $formatoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');

        $this->actingAs($this->usuarioCon('campaign_manager'))->post('/clientes', [
            'commercial_name' => 'ACME', 'client_code' => 'ACME-01',
            'country_id' => $this->paisPE, 'status' => 'prospect',
        ]);

        $this->clienteId = (int) DB::table('client_organizations')->where('client_code', 'ACME-01')->value('id');
        $this->marcaId = (int) DB::table('client_brands')->where('client_organization_id', $this->clienteId)->value('id');
        $this->formatoId = (int) DB::table('content_formats')->where('is_active', 1)->value('id');

        // Las dos premisas. Sin catalogo de formatos la mitad de estas pruebas
        // saldria verde por no poder llegar a lo que quieren probar, que es la
        // leccion de las tres puertas que informaban verde sin mirar nada.
        $this->assertGreaterThan(0, $this->formatoId, 'sin formatos en el catalogo no hay brief que probar');
        $this->assertGreaterThan(0, DB::table('legal_entity_countries')
            ->where('country_id', $this->paisPE)->whereNull('valid_to')->count());
    }

    // ------------------------------------------------- el veto del brief

    /** `BR-CAMPAIGN-004`: sin decir qué hay que entregar no se aprueba. */
    public function test_una_campana_sin_requisitos_no_sale_de_borrador(): void
    {
        $uuid = $this->campanaEnAprobacion();

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/campanas/{$uuid}/estado", ['estado' => E::APROBADA])
            ->assertSessionHas('aviso');

        $this->assertSame('pending_approval', DB::table('campaigns')->where('uuid', $uuid)->value('status'));
        $this->assertStringContainsString('BR-CAMPAIGN-004', (string) session('aviso'));
        $this->assertStringContainsString('al menos un formato', (string) session('aviso'),
            'el aviso tiene que decir QUE falta, no nombrar una restriccion');
    }

    /**
     * **La prueba que descubre si las demás mienten.**
     *
     * Con un requisito y un precio, la campaña **sí** se aprueba. Sin esta
     * afirmación, las cuatro rechazos de arriba podrían estar saliendo verdes
     * porque nada se aprueba nunca —por un permiso mal puesto, por una ruta que
     * no existe— y no porque el veto funcione. Es la lección de 4.9, y ya ha
     * cazado dos vetos que rechazaban por el motivo equivocado.
     */
    public function test_con_un_requisito_y_un_precio_si_se_aprueba(): void
    {
        $uuid = $this->campanaEnAprobacion();
        $this->requisitoDe($this->idDe($uuid));

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/campanas/{$uuid}/estado", ['estado' => E::APROBADA])
            ->assertSessionHas('exito');

        $this->assertSame('approved', DB::table('campaigns')->where('uuid', $uuid)->value('status'));
    }

    /** Cero sin declarar no es un precio: es un formulario sin rellenar. */
    public function test_un_ingreso_cero_sin_declarar_no_sale_de_borrador(): void
    {
        $uuid = $this->campanaEnAprobacion(['revenue_amount' => '0']);
        $this->requisitoDe($this->idDe($uuid));

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/campanas/{$uuid}/estado", ['estado' => E::APROBADA])
            ->assertSessionHas('aviso');

        $this->assertSame('pending_approval', DB::table('campaigns')->where('uuid', $uuid)->value('status'));
        $this->assertStringContainsString('gratuita', (string) session('aviso'));
    }

    /** Y declarado, sí: una campaña regalada es una decisión de negocio legítima. */
    public function test_un_ingreso_cero_declarado_gratuito_si_sale_de_borrador(): void
    {
        $uuid = $this->campanaEnAprobacion(['revenue_amount' => '0', 'is_gratis' => '1']);
        $this->requisitoDe($this->idDe($uuid));

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/campanas/{$uuid}/estado", ['estado' => E::APROBADA])
            ->assertSessionHas('exito');

        $fila = DB::table('campaigns')->where('uuid', $uuid)->first();

        $this->assertSame('approved', $fila->status);
        $this->assertSame(1, (int) $fila->is_gratis, 'la decision se guarda, no se deduce del cero');
    }

    /** Todos los motivos de una vez: enterarse de uno por visita es una visita por motivo. */
    public function test_el_aviso_dice_todo_lo_que_falta_de_una_vez(): void
    {
        $uuid = $this->campanaEnAprobacion(['revenue_amount' => '0']);

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/campanas/{$uuid}/estado", ['estado' => E::APROBADA]);

        $aviso = (string) session('aviso');

        $this->assertStringContainsString('al menos un formato', $aviso);
        $this->assertStringContainsString('gratuita', $aviso);
    }

    // ------------------------------------------------- coherencia del precio

    /** Gratuita con importe es una contradicción, y se dice sobre el importe. */
    public function test_gratuita_con_importe_se_rechaza(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post('/campanas', $this->datos(['revenue_amount' => '5000.00', 'is_gratis' => '1']))
            ->assertSessionHasErrors('revenue_amount');

        $this->assertSame(0, DB::table('campaigns')->count());
    }

    /**
     * Una casilla sin marcar **es una respuesta**, no un silencio.
     *
     * Sin el `prepareForValidation`, `is_gratis` llegaría `null` y la regla
     * `required` reventaría sobre una casilla que el operador sí contestó.
     */
    public function test_la_casilla_sin_marcar_se_guarda_como_no_gratuita(): void
    {
        $datos = $this->datos();
        unset($datos['is_gratis']);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post('/campanas', $datos)
            ->assertSessionHas('exito');

        $this->assertSame(0, (int) DB::table('campaigns')->where('name', 'Lanzamiento verano')->value('is_gratis'));
    }

    /**
     * **La prueba que justifica que sea un `CHECK`.**
     *
     * `ck_camp_revenue_declarado` no se salta ni con un `UPDATE` directo. Si
     * esto sólo viviera en el controlador, cualquier importación dejaría
     * campañas aprobadas de cero sin saber si se regalaron.
     */
    public function test_el_ingreso_declarado_no_se_salta_ni_por_sql(): void
    {
        $id = $this->idDe($this->campanaEnAprobacion(['revenue_amount' => '0']));

        $this->expectException(QueryException::class);

        DB::table('campaigns')->where('id', $id)->update([
            'status' => E::APROBADA,
            'confirmed_at' => now(),
        ]);
    }

    // ------------------------------------------------------- la pantalla

    public function test_el_mismo_formato_dos_veces_se_explica_en_vez_de_reventar(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->campanaEnBorrador();

        $this->actingAs($gestor)->post("/campanas/{$uuid}/requisitos", $this->requisito())
            ->assertSessionHas('exito');

        $this->actingAs($gestor)->post("/campanas/{$uuid}/requisitos", $this->requisito())
            ->assertSessionHas('aviso');

        $this->assertSame(1, DB::table('campaign_requirements')->where('campaign_id', $this->idDe($uuid))->count(),
            'dos filas del mismo formato serian dos cantidades para la misma cosa');
        $this->assertStringContainsString('ya esta en el brief', (string) session('aviso'));
    }

    /** Pedir cero de un formato no es un requisito. */
    public function test_cantidad_cero_se_rechaza(): void
    {
        $uuid = $this->campanaEnBorrador();

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/campanas/{$uuid}/requisitos", $this->requisito(['quantity' => 0]))
            ->assertSessionHasErrors('quantity');

        $this->assertSame(0, DB::table('campaign_requirements')->count());
    }

    /** El requisito de OTRA campaña no se quita por la URL. */
    public function test_no_se_quita_el_requisito_de_otra_campana(): void
    {
        $mia = $this->campanaEnBorrador();
        $ajena = $this->campanaDe($this->clienteId, $this->marcaId);
        $requisitoAjeno = $this->requisitoDe($ajena);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->delete("/campanas/{$mia}/requisitos/{$requisitoAjeno}")
            ->assertNotFound();

        $this->assertSame(1, DB::table('campaign_requirements')->where('id', $requisitoAjeno)->count());
    }

    public function test_quitar_un_requisito_propio_si(): void
    {
        $uuid = $this->campanaEnBorrador();
        $requisito = $this->requisitoDe($this->idDe($uuid));

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->delete("/campanas/{$uuid}/requisitos/{$requisito}")
            ->assertSessionHas('exito');

        $this->assertSame(0, DB::table('campaign_requirements')->where('id', $requisito)->count());
    }

    /** `BR-CAMPAIGN-003`: el brief de una campaña confirmada exige una enmienda. */
    public function test_el_brief_de_una_campana_confirmada_no_se_toca(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->campanaEnAprobacion();
        $requisito = $this->requisitoDe($this->idDe($uuid));

        $this->actingAs($this->usuarioCon('finance'))->post("/campanas/{$uuid}/estado", ['estado' => E::APROBADA]);
        $this->assertSame('approved', DB::table('campaigns')->where('uuid', $uuid)->value('status'));

        $this->actingAs($gestor)
            ->post("/campanas/{$uuid}/requisitos", $this->requisito(['content_format_id' => $this->otroFormato()]))
            ->assertSessionHas('aviso');

        $this->actingAs($gestor)
            ->delete("/campanas/{$uuid}/requisitos/{$requisito}")
            ->assertSessionHas('aviso');

        $this->assertSame(1, DB::table('campaign_requirements')->where('campaign_id', $this->idDe($uuid))->count(),
            'ni se anade ni se quita');
    }

    /** Ver la ficha no es tocar el brief. */
    public function test_sin_permiso_de_gestion_no_se_anade_un_requisito(): void
    {
        $uuid = $this->campanaEnBorrador();

        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->post("/campanas/{$uuid}/requisitos", $this->requisito())
            ->assertForbidden();

        $this->assertSame(0, DB::table('campaign_requirements')->count());
    }

    /**
     * La ficha enseña lo que falta ANTES de que nadie pulse el botón.
     *
     * La primera version afirmaba `assertSee('al menos un formato')` y **pasaba
     * con el veto desactivado**: esa frase tambien esta en el texto fijo del
     * bloque «Que hay que entregar». Lo destapo la mutacion, no la ejecucion.
     * Se afirma sobre el rotulo del panel, que solo existe si hay motivos, y
     * ademas que con el brief completo el panel **desaparece** --que es lo que
     * distingue «lo enseña» de «lo enseña siempre»--.
     */
    public function test_la_ficha_enseña_lo_que_falta(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->campanaEnBorrador(['revenue_amount' => '0']);

        $this->actingAs($gestor)->get("/campanas/{$uuid}")->assertOk()
            ->assertSee('Todavía no puede salir de borrador', false)
            ->assertSee('BR-CAMPAIGN-004', false);

        $this->requisitoDe($this->idDe($uuid));
        DB::table('campaigns')->where('uuid', $uuid)->update(['is_gratis' => 1]);

        $this->actingAs($gestor)->get("/campanas/{$uuid}")->assertOk()
            ->assertDontSee('Todavía no puede salir de borrador', false);
    }

    // ------------------------------------------------------------------ apoyo

    /** @param array<string, mixed> $cambios */
    private function datos(array $cambios = []): array
    {
        return array_merge([
            'name' => 'Lanzamiento verano',
            'client_organization_id' => $this->clienteId,
            'client_brand_id' => $this->marcaId,
            'objective' => 'awareness',
            'currency_code' => (string) DB::table('currencies')->value('code'),
            'revenue_amount' => '15000.00',
            'is_gratis' => '0',
            'included_revision_rounds' => 2,
            'min_creator_age' => 18,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
        ], $cambios);
    }

    /** @param array<string, mixed> $cambios */
    private function requisito(array $cambios = []): array
    {
        return array_merge([
            'content_format_id' => $this->formatoId,
            'quantity' => 2,
            'deadline_offset_days' => 7,
            'permanence_days' => 30,
        ], $cambios);
    }

    /**
     * Un borrador **con mercado**.
     *
     * El mercado va aqui porque desde 7.3 sin el la campana no sale de borrador,
     * y estas pruebas hablan del brief y del precio: si les faltara el mercado
     * estarian probando el veto de 7.3 creyendo que prueban el suyo. Es
     * exactamente lo que le paso a la suite de 7.1 al llegar 7.2.
     *
     * @param array<string, mixed> $cambios
     */
    private function campanaEnBorrador(array $cambios = [], ?User $quien = null): string
    {
        $this->actingAs($quien ?? $this->usuarioCon('campaign_manager'))
            ->post('/campanas', $this->datos($cambios));

        $fila = DB::table('campaigns')->where('name', 'Lanzamiento verano')->first(['id', 'uuid']);
        $this->mercadoDe((int) $fila->id, $this->paisPE);

        return (string) $fila->uuid;
    }

    /** @param array<string, mixed> $cambios */
    private function campanaEnAprobacion(array $cambios = []): string
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->campanaEnBorrador($cambios, $gestor);

        $this->actingAs($gestor)->post("/campanas/{$uuid}/estado", ['estado' => E::EN_APROBACION]);

        return $uuid;
    }

    private function idDe(string $uuid): int
    {
        return (int) DB::table('campaigns')->where('uuid', $uuid)->value('id');
    }

    private function otroFormato(): int
    {
        return (int) DB::table('content_formats')
            ->where('is_active', 1)->where('id', '!=', $this->formatoId)->value('id');
    }
}
