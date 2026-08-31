<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Campaign\Services\EstadosDeCampana as E;
use App\Modules\Campaign\Services\Mercados;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Los mercados de la campaña (iteración 7.3).
 *
 * Tres cosas distintas, y la del medio es la que justifica la iteración.
 *
 * ### 1. Sin país, no hay campaña que aprobar
 *
 * Se suma a `BR-CAMPAIGN-004`: una campaña que no dice dónde se ejecuta no
 * puede salir de borrador. De ahí sale a quién se puede invitar (7.4), y una
 * campaña aprobada sin mercados es una campaña que nadie puede empezar.
 *
 * ### 2. `N-03`: el brief de mercado REEMPLAZA al general
 *
 * La regla está escrita desde la Fase 2 y **nada la implementaba**. Dice:
 *
 * > Para el mercado M, el brief efectivo son los requisitos de M **si existe al
 * > menos uno**; si no, los generales.
 *
 * Reemplaza, no mezcla. La alternativa —fusionar— obliga a decidir si «3
 * stories generales + 2 de México» son 2, 3 o 5, y cualquier respuesta es
 * arbitraria y se descubre en producción.
 *
 * ### 3. Añadir sí, quitar no
 *
 * Con la campaña confirmada, ampliar a un país nuevo es comercial y no rompe
 * nada de lo prometido. Quitar puede dejar fuera a creadores ya invitados, y eso
 * exige una enmienda (`BR-CAMPAIGN-003`). Lo impide un disparador, no el
 * controlador: de eso depende que un creador aceptado siga teniendo un país.
 */
final class MercadosTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $paisPE;

    private int $paisCO;

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
        $this->paisCO = (int) DB::table('countries')->where('iso2', 'CO')->value('id');

        $this->actingAs($this->usuarioCon('campaign_manager'))->post('/backoffice/clientes', [
            'commercial_name' => 'ACME', 'client_code' => 'ACME-01',
            'country_id' => $this->paisPE, 'status' => 'prospect',
        ]);

        $this->clienteId = (int) DB::table('client_organizations')->where('client_code', 'ACME-01')->value('id');
        $this->marcaId = (int) DB::table('client_brands')->where('client_organization_id', $this->clienteId)->value('id');
        $this->formatoId = (int) DB::table('content_formats')->where('is_active', 1)->value('id');

        // Las premisas. DOS paises distintos y sembrados: media prueba de esta
        // clase compara «Peru» contra «Colombia», y con un solo pais en el
        // catalogo saldrian verdes sin comparar nada.
        $this->assertGreaterThan(0, $this->paisPE);
        $this->assertGreaterThan(0, $this->paisCO);
        $this->assertNotSame($this->paisPE, $this->paisCO);
        $this->assertGreaterThan(0, $this->formatoId);
    }

    // ------------------------------------------- el veto de BR-CAMPAIGN-004

    public function test_una_campana_sin_mercados_no_sale_de_borrador(): void
    {
        $uuid = $this->campanaEnAprobacion();
        $this->requisitoDe($this->idDe($uuid));

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/backoffice/campanas/{$uuid}/estado", ['estado' => E::APROBADA])
            ->assertSessionHas('aviso');

        $this->assertSame('pending_approval', DB::table('campaigns')->where('uuid', $uuid)->value('status'));
        $this->assertStringContainsString('en que paises se ejecuta', (string) session('aviso'));
    }

    /** La afirmación que descubre si la de arriba miente. */
    public function test_con_un_mercado_si_sale_de_borrador(): void
    {
        $uuid = $this->campanaEnAprobacion();
        $this->requisitoDe($this->idDe($uuid));
        $this->mercadoDe($this->idDe($uuid), $this->paisPE);

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/backoffice/campanas/{$uuid}/estado", ['estado' => E::APROBADA])
            ->assertSessionHas('exito');

        $this->assertSame('approved', DB::table('campaigns')->where('uuid', $uuid)->value('status'));
    }

    // --------------------------------------------------- N-03: reemplaza

    /**
     * **La prueba que justifica la iteración.**
     *
     * Perú tiene brief propio, Colombia no. Perú ve **sólo** lo suyo —no lo
     * suyo más lo general— y Colombia ve el general. Si la regla fuera fusionar,
     * Perú vería dos formatos.
     */
    public function test_el_brief_de_mercado_reemplaza_al_general_y_no_se_mezcla(): void
    {
        $campanaId = $this->idDe($this->campanaEnBorrador());
        $peru = $this->mercadoDe($campanaId, $this->paisPE);
        $colombia = $this->mercadoDe($campanaId, $this->paisCO);

        $general = $this->requisitoDe($campanaId, ['content_format_id' => $this->formatoId, 'quantity' => 3]);
        $soloPeru = $this->requisitoDe($campanaId, [
            'campaign_market_id' => $peru,
            'content_format_id' => $this->otroFormato(),
            'quantity' => 1,
        ]);

        $enPeru = Mercados::briefEfectivo($campanaId, $peru);
        $enColombia = Mercados::briefEfectivo($campanaId, $colombia);

        $this->assertSame([$soloPeru], $enPeru->pluck('id')->all(),
            'Peru ve SOLO lo suyo: reemplaza, no suma');
        $this->assertSame([$general], $enColombia->pluck('id')->all(),
            'Colombia no escribio nada suyo, asi que hereda el general');
    }

    /** Quitar el requisito propio devuelve el mercado al general. */
    public function test_sin_requisitos_propios_el_mercado_vuelve_a_heredar(): void
    {
        $campanaId = $this->idDe($this->campanaEnBorrador());
        $peru = $this->mercadoDe($campanaId, $this->paisPE);
        $general = $this->requisitoDe($campanaId);
        $propio = $this->requisitoDe($campanaId, [
            'campaign_market_id' => $peru, 'content_format_id' => $this->otroFormato(),
        ]);

        $this->assertSame([$propio], Mercados::briefEfectivo($campanaId, $peru)->pluck('id')->all());

        DB::table('campaign_requirements')->where('id', $propio)->delete();

        $this->assertSame([$general], Mercados::briefEfectivo($campanaId, $peru)->pluck('id')->all(),
            'quitar lo propio no deja al mercado sin brief: lo devuelve al general');
    }

    /** Y la pantalla del mercado lo DICE, que es lo que evita la lectura de que se suman. */
    public function test_la_pantalla_del_mercado_dice_si_hereda_o_no(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->campanaEnBorrador();
        $campanaId = $this->idDe($uuid);
        $peru = $this->mercadoDe($campanaId, $this->paisPE);
        $this->requisitoDe($campanaId);

        $this->actingAs($gestor)->get("/backoffice/campanas/{$uuid}/mercados/{$peru}")->assertOk()
            ->assertSee('sigue el brief general', false);

        $this->requisitoDe($campanaId, [
            'campaign_market_id' => $peru, 'content_format_id' => $this->otroFormato(),
        ]);

        $this->actingAs($gestor)->get("/backoffice/campanas/{$uuid}/mercados/{$peru}")->assertOk()
            ->assertSee('tiene brief propio', false)
            ->assertSee('no se suman', false);
    }

    // ------------------------------------------- el mercado es DE la campana

    /**
     * **La prueba de la foránea compuesta.**
     *
     * Una foránea simple sólo comprueba que el mercado exista. Nada impedía un
     * requisito de la campaña A colgado del mercado de la campaña B — y con él,
     * un brief que se resolvía contra el país equivocado.
     */
    public function test_un_requisito_no_puede_apuntar_al_mercado_de_otra_campana(): void
    {
        $mia = $this->idDe($this->campanaEnBorrador());
        $ajena = $this->campanaDe($this->clienteId, $this->marcaId);
        $mercadoAjeno = $this->mercadoDe($ajena, $this->paisCO);

        $this->expectException(QueryException::class);

        DB::table('campaign_requirements')->insert([
            'campaign_id' => $mia,
            'campaign_market_id' => $mercadoAjeno,
            'content_format_id' => $this->formatoId,
            'quantity' => 1,
            'deadline_offset_days' => 7,
            'permanence_days' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Y el `NULL` con significado sigue pasando.
     *
     * Sin esta afirmación, la de arriba saldría verde aunque la foránea
     * compuesta hubiera roto «todos los mercados» — que es la excepción
     * consciente de 2.3 §9 y el caso más común de todos.
     */
    public function test_un_requisito_general_sigue_entrando_sin_mercado(): void
    {
        $campanaId = $this->idDe($this->campanaEnBorrador());

        $id = $this->requisitoDe($campanaId);

        $this->assertNull(DB::table('campaign_requirements')->where('id', $id)->value('campaign_market_id'));
    }

    /** Por pantalla, el mismo intento se explica en vez de dar un 500. */
    public function test_por_pantalla_el_mercado_ajeno_se_explica(): void
    {
        $uuid = $this->campanaEnBorrador();
        $ajena = $this->campanaDe($this->clienteId, $this->marcaId);
        $mercadoAjeno = $this->mercadoDe($ajena, $this->paisCO);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/backoffice/campanas/{$uuid}/requisitos", $this->requisito(['campaign_market_id' => $mercadoAjeno]))
            ->assertSessionHasErrors('campaign_market_id');

        $this->assertSame(0, DB::table('campaign_requirements')->count());
    }

    // ------------------------------------------------ anadir si, quitar no

    public function test_el_mismo_pais_dos_veces_se_explica(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->campanaEnBorrador();

        $this->actingAs($gestor)->post("/backoffice/campanas/{$uuid}/mercados", ['country_id' => $this->paisPE])
            ->assertSessionHas('exito');
        $this->actingAs($gestor)->post("/backoffice/campanas/{$uuid}/mercados", ['country_id' => $this->paisPE])
            ->assertSessionHas('aviso');

        $this->assertSame(1, DB::table('campaign_markets')->where('campaign_id', $this->idDe($uuid))->count());
        $this->assertStringContainsString('ya es un mercado', (string) session('aviso'));
    }

    /** Cero creadores no es un objetivo: `ck_cm_target`. */
    public function test_cero_creadores_se_rechaza_pero_dejarlo_en_blanco_no(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->campanaEnBorrador();

        $this->actingAs($gestor)
            ->post("/backoffice/campanas/{$uuid}/mercados", ['country_id' => $this->paisPE, 'target_creators' => 0])
            ->assertSessionHasErrors('target_creators');

        $this->actingAs($gestor)
            ->post("/backoffice/campanas/{$uuid}/mercados", ['country_id' => $this->paisPE, 'target_creators' => ''])
            ->assertSessionHas('exito');

        $this->assertNull(DB::table('campaign_markets')
            ->where('campaign_id', $this->idDe($uuid))->value('target_creators'),
            'en blanco es «sin cupo fijado», y eso si es una respuesta');
    }

    /** El cero tampoco entra por SQL: la regla vive en la base. */
    public function test_cero_creadores_no_entra_ni_por_sql(): void
    {
        $campanaId = $this->idDe($this->campanaEnBorrador());

        $this->expectException(QueryException::class);

        $this->mercadoDe($campanaId, $this->paisPE, ['target_creators' => 0]);
    }

    public function test_a_una_campana_confirmada_si_se_le_anade_un_mercado(): void
    {
        $uuid = $this->campanaAprobada();

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/backoffice/campanas/{$uuid}/mercados", ['country_id' => $this->paisCO])
            ->assertSessionHas('exito');

        $this->assertSame(2, DB::table('campaign_markets')->where('campaign_id', $this->idDe($uuid))->count());
    }

    public function test_pero_no_se_le_quita_ninguno(): void
    {
        $uuid = $this->campanaAprobada();
        $mercado = (int) DB::table('campaign_markets')->where('campaign_id', $this->idDe($uuid))->value('id');

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->delete("/backoffice/campanas/{$uuid}/mercados/{$mercado}")
            ->assertSessionHas('aviso');

        $this->assertSame(1, DB::table('campaign_markets')->where('id', $mercado)->count());
        $this->assertStringContainsString('BR-CAMPAIGN-003', (string) session('aviso'));
    }

    /**
     * **La prueba que justifica que sea un disparador.**
     *
     * Ni con un `DELETE` directo. Si esto sólo viviera en el controlador,
     * cualquier mantenimiento dejaría creadores aceptados apuntando a un país
     * que ya no está en la campaña.
     */
    public function test_quitar_un_mercado_confirmado_no_se_hace_ni_por_sql(): void
    {
        $uuid = $this->campanaAprobada();
        $mercado = (int) DB::table('campaign_markets')->where('campaign_id', $this->idDe($uuid))->value('id');

        $this->expectException(QueryException::class);

        DB::table('campaign_markets')->where('id', $mercado)->delete();
    }

    /** Y en borrador sí se quita: es el margen para un dedazo. */
    public function test_en_borrador_un_mercado_vacio_si_se_quita(): void
    {
        $uuid = $this->campanaEnBorrador();
        $mercado = $this->mercadoDe($this->idDe($uuid), $this->paisCO);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->delete("/backoffice/campanas/{$uuid}/mercados/{$mercado}")
            ->assertSessionHas('exito');

        $this->assertSame(0, DB::table('campaign_markets')->where('id', $mercado)->count());
    }

    /** Con requisitos colgando, se dice qué hay dentro en vez de dar un 1451. */
    public function test_un_mercado_con_requisitos_no_se_quita_sin_avisar_de_que_tiene(): void
    {
        $uuid = $this->campanaEnBorrador();
        $campanaId = $this->idDe($uuid);
        $mercado = $this->mercadoDe($campanaId, $this->paisCO);
        $this->requisitoDe($campanaId, ['campaign_market_id' => $mercado]);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->delete("/backoffice/campanas/{$uuid}/mercados/{$mercado}")
            ->assertSessionHas('aviso');

        $this->assertSame(1, DB::table('campaign_markets')->where('id', $mercado)->count());
        $this->assertStringContainsString('requisito', (string) session('aviso'));
    }

    /** El mercado de OTRA campaña no se quita por la URL. */
    public function test_no_se_quita_el_mercado_de_otra_campana(): void
    {
        $uuid = $this->campanaEnBorrador();
        $ajena = $this->campanaDe($this->clienteId, $this->marcaId);
        $mercadoAjeno = $this->mercadoDe($ajena, $this->paisCO);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->delete("/backoffice/campanas/{$uuid}/mercados/{$mercadoAjeno}")
            ->assertNotFound();

        $this->assertSame(1, DB::table('campaign_markets')->where('id', $mercadoAjeno)->count());
    }

    // ------------------------------------------------------------- permisos

    public function test_ver_el_brief_de_un_mercado_no_exige_gestionar(): void
    {
        $uuid = $this->campanaEnBorrador();
        $mercado = $this->mercadoDe($this->idDe($uuid), $this->paisPE);
        $revisor = $this->usuarioCon('content_reviewer');

        $this->actingAs($revisor)->get("/backoffice/campanas/{$uuid}/mercados/{$mercado}")->assertOk();
        $this->actingAs($revisor)->post("/backoffice/campanas/{$uuid}/mercados", ['country_id' => $this->paisCO])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * El formulario de alta. Delega en el apoyo: estaba copiado en cuatro clases
     * y `creator_budget_amount` (7.5) las rompio a todas a la vez.
     *
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private function datos(array $cambios = []): array
    {
        return $this->datosDeCampana($this->clienteId, $this->marcaId, $cambios);
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

    private function campanaEnBorrador(?User $quien = null): string
    {
        $this->actingAs($quien ?? $this->usuarioCon('campaign_manager'))
            ->post('/backoffice/campanas', $this->datos());

        return (string) DB::table('campaigns')->where('name', 'Lanzamiento verano')->value('uuid');
    }

    private function campanaEnAprobacion(): string
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->campanaEnBorrador($gestor);

        $this->actingAs($gestor)->post("/backoffice/campanas/{$uuid}/estado", ['estado' => E::EN_APROBACION]);

        return $uuid;
    }

    /** Una campaña aprobada, o sea confirmada, con UN mercado y su brief. */
    private function campanaAprobada(): string
    {
        $uuid = $this->campanaEnAprobacion();
        $this->requisitoDe($this->idDe($uuid));
        $this->mercadoDe($this->idDe($uuid), $this->paisPE);

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/backoffice/campanas/{$uuid}/estado", ['estado' => E::APROBADA]);

        $this->assertNotNull(DB::table('campaigns')->where('uuid', $uuid)->value('confirmed_at'),
            'la premisa de estas pruebas es que la campana esta CONFIRMADA');

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
