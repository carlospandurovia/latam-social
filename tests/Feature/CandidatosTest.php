<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Campaign\Services\BuscadorDeCreadores;
use App\Modules\Campaign\Services\ListaCorta;
use App\Modules\Creator\Services\CompletitudOperativa;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Buscador de creadores y lista corta (iteración 7.4).
 *
 * ### Lo que estas pruebas vigilan
 *
 * El buscador es **lectura**, y una lectura mal escrita no revienta: devuelve
 * de más o de menos y nadie se entera. Por eso casi todas las pruebas de esta
 * clase vienen en pareja:
 *
 * > *«a este NO le sale»* junto a *«y a este sí»*.
 *
 * Sin la segunda mitad, un filtro que excluyera a todo el mundo pasaría entera
 * la primera. Es la lección que este proyecto ya ha pagado siete veces.
 *
 * ### El reparto entre buscador y lista corta
 *
 * Decisión de negocio (2026-08-25): el buscador enseña a todos los `active` y
 * **no** revalida `BR-CREATOR-006`; el veto real salta al añadir. Así que hay
 * una prueba que afirma justo eso — un creador al que le falta el medio de pago
 * **sale** en la búsqueda y **no** entra en la lista.
 */
final class CandidatosTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $paisPE;

    private int $paisCO;

    private int $clienteId;

    private int $marcaId;

    private int $campanaId;

    private string $uuid;

    private int $formatoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');
        $this->paisCO = (int) DB::table('countries')->where('iso2', 'CO')->value('id');
        $this->formatoId = (int) DB::table('content_formats')->where('is_active', 1)->value('id');

        $this->clienteId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'commercial_name' => 'ACME', 'client_code' => 'ACME-01',
            'country_id' => $this->paisPE, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->marcaId = (int) DB::table('client_brands')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_organization_id' => $this->clienteId,
            'name' => 'Marca ACME', 'slug' => 'marca-acme', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // La campana: Peru como unico mercado, un reel en el brief.
        $this->campanaId = $this->campanaDe($this->clienteId, $this->marcaId, [
            'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30',
        ]);
        $this->uuid = (string) DB::table('campaigns')->where('id', $this->campanaId)->value('uuid');
        $this->mercadoDe($this->campanaId, $this->paisPE);
        $this->requisitoDe($this->campanaId, ['content_format_id' => $this->formatoId, 'quantity' => 2]);

        $this->assertGreaterThan(0, $this->formatoId, 'sin catalogo de formatos no hay nada que buscar');
        $this->assertNotSame($this->paisPE, $this->paisCO);
    }

    // ------------------------------------------------------- los filtros duros

    /** **La prueba que descubre si las demás mienten.** Alguien tiene que salir. */
    public function test_un_creador_que_encaja_sale_en_la_busqueda(): void
    {
        $id = $this->candidato();

        $salen = BuscadorDeCreadores::buscar($this->campana())->pluck('id')->all();

        $this->assertSame([$id], $salen);
    }

    public function test_el_de_otro_pais_no_sale_pero_se_puede_ver_por_que(): void
    {
        $mio = $this->candidato();
        $ajeno = $this->candidato(['country_id' => $this->paisCO]);

        $this->assertSame([$mio], BuscadorDeCreadores::buscar($this->campana())->pluck('id')->all());

        $conTodos = BuscadorDeCreadores::conDescartados($this->campana())->keyBy('id');

        $this->assertArrayHasKey($ajeno, $conTodos->all(), 'el descartado tiene que poder verse');
        $this->assertSame(1, (int) $conTodos[$ajeno]->descarte_mercado);
        $this->assertSame(0, (int) $conTodos[$mio]->descarte_mercado);
    }

    public function test_el_que_no_ofrece_ningun_formato_del_brief_no_sale(): void
    {
        $sirve = $this->candidato();
        $noSirve = $this->candidato(conFormato: false);

        $ids = BuscadorDeCreadores::buscar($this->campana())->pluck('id')->all();

        $this->assertContains($sirve, $ids);
        $this->assertNotContains($noSirve, $ids);
    }

    /**
     * `BR-CAMPAIGN-007`, la mitad que ya se puede comprobar: el creador declaró
     * por escrito que no trabaja esa categoría.
     */
    public function test_el_que_declaro_no_trabajar_la_categoria_de_la_marca_no_sale(): void
    {
        $categoria = (int) DB::table('categories')->where('is_active', 1)->value('id');
        DB::table('client_brand_categories')->insert([
            'client_brand_id' => $this->marcaId, 'category_id' => $categoria, 'created_at' => now(),
        ]);

        $abierto = $this->candidato();
        $cerrado = $this->candidato();
        DB::table('creator_restrictions')->insert([
            'creator_id' => $cerrado, 'category_id' => $categoria,
            'reason' => 'no trabajo con esta categoria', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ids = BuscadorDeCreadores::buscar($this->campana())->pluck('id')->all();

        $this->assertContains($abierto, $ids);
        $this->assertNotContains($cerrado, $ids, 'ya dijo que no: invitarlo es hacerle perder el tiempo');
    }

    public function test_el_que_tiene_la_agenda_bloqueada_esos_dias_no_sale(): void
    {
        $libre = $this->candidato();
        $ocupado = $this->candidato();
        DB::table('creator_blackouts')->insert([
            'creator_id' => $ocupado, 'starts_on' => '2026-09-10', 'ends_on' => '2026-09-20',
            'reason' => 'viaje', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Y los DOS bordes. La campana va del 1 al 30 de septiembre:
        //
        //   ...31 ago]  [1 sep .......... 30 sep]  [1 oct...
        //     justoAntes                            justoDespues
        //
        // Ninguno de los dos solapa, y hay que afirmarlo por SEPARADO. La
        // primera version de esta prueba solo tenia el borde de la izquierda, y
        // **una mutacion que desplazaba un dia el borde de la derecha pasaba
        // entera**: la prueba se creia completa y solo miraba media regla.
        // Es el error de un dia --once apariciones en este proyecto-- entrando
        // esta vez por el lado que nadie miraba.
        $justoAntes = $this->candidato();
        DB::table('creator_blackouts')->insert([
            'creator_id' => $justoAntes, 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31',
            'reason' => 'vacaciones', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $justoDespues = $this->candidato();
        DB::table('creator_blackouts')->insert([
            'creator_id' => $justoDespues, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-15',
            'reason' => 'otra campana', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Y los dos que TOCAN un solo dia de la campana: esos si solapan. Hacen
        // falta los dos: una mutacion que desplazaba un dia el borde izquierdo
        // --dejando pasar un bloqueo que termina el 1 de septiembre-- sobrevivia
        // a la version que solo tenia el derecho.
        $ultimoDia = $this->candidato();
        DB::table('creator_blackouts')->insert([
            'creator_id' => $ultimoDia, 'starts_on' => '2026-09-30', 'ends_on' => '2026-10-15',
            'reason' => 'viaje', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $primerDia = $this->candidato();
        DB::table('creator_blackouts')->insert([
            'creator_id' => $primerDia, 'starts_on' => '2026-08-20', 'ends_on' => '2026-09-01',
            'reason' => 'vuelve el mismo dia', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ids = BuscadorDeCreadores::buscar($this->campana())->pluck('id')->all();

        $this->assertContains($libre, $ids);
        $this->assertContains($justoAntes, $ids, 'un bloqueo que termina el dia ANTES no solapa');
        $this->assertContains($justoDespues, $ids, 'ni uno que empieza el dia DESPUES');
        $this->assertNotContains($ocupado, $ids);
        $this->assertNotContains($ultimoDia, $ids, 'pero uno que empieza el ULTIMO dia si solapa');
        $this->assertNotContains($primerDia, $ids, 'y uno que termina el PRIMERO, tambien');
    }

    public function test_el_que_ya_esta_en_la_campana_no_vuelve_a_salir(): void
    {
        $id = $this->candidato();
        $this->assertContains($id, BuscadorDeCreadores::buscar($this->campana())->pluck('id')->all());

        ListaCorta::anadir($this->campana(), $id);

        $this->assertNotContains($id, BuscadorDeCreadores::buscar($this->campana())->pluck('id')->all());
    }

    // ------------------------------------------------------------- la edad

    public function test_la_edad_minima_es_la_mayor_entre_la_campana_y_las_categorias(): void
    {
        DB::table('campaigns')->where('id', $this->campanaId)->update(['min_creator_age' => 18]);

        $categoria = (int) DB::table('categories')->where('is_active', 1)->value('id');
        DB::table('categories')->where('id', $categoria)->update(['min_age' => 21]);
        DB::table('client_brand_categories')->insert([
            'client_brand_id' => $this->marcaId, 'category_id' => $categoria, 'created_at' => now(),
        ]);

        $this->assertSame(21, BuscadorDeCreadores::edadMinima($this->campana()),
            'una campana de 18 con una categoria de 21 exige 21, no 18');
    }

    public function test_el_que_no_llega_a_la_edad_minima_no_sale(): void
    {
        DB::table('campaigns')->where('id', $this->campanaId)->update(['min_creator_age' => 25]);

        $mayor = $this->candidato(['birth_date' => '1990-01-01']);
        $joven = $this->candidato(['birth_date' => '2005-01-01']);

        $ids = BuscadorDeCreadores::buscar($this->campana())->pluck('id')->all();

        $this->assertContains($mayor, $ids);
        $this->assertNotContains($joven, $ids);
    }

    // ------------------------------------------------------ el coste estimado

    public function test_el_coste_multiplica_la_tarifa_por_la_cantidad_del_brief(): void
    {
        $id = $this->candidato();
        $this->tarifa($id, 500.00, (string) $this->campana()->currency_code);

        $coste = BuscadorDeCreadores::costeEstimado($this->campana(), [$id]);

        // 500 x 2 piezas del brief.
        $this->assertSame(1000.0, $coste[$id]['importe']);
        $this->assertNull($coste[$id]['aviso']);
    }

    /**
     * Un importe ausente **no es cero**. Es que no se puede calcular, y hay que
     * decir por qué: enseñar «0» sería enseñar un creador gratis que no lo es.
     */
    public function test_sin_tarifa_el_coste_es_nulo_con_su_motivo(): void
    {
        $id = $this->candidato();

        $coste = BuscadorDeCreadores::costeEstimado($this->campana(), [$id]);

        $this->assertNull($coste[$id]['importe']);
        $this->assertStringContainsString('faltan', (string) $coste[$id]['aviso']);
    }

    /** Y en otra moneda tampoco se inventa una conversión: no hay tipos de cambio. */
    public function test_una_tarifa_en_otra_moneda_no_se_convierte(): void
    {
        $id = $this->candidato();
        $otra = (string) DB::table('currencies')
            ->where('code', '!=', $this->campana()->currency_code)->value('code');
        $this->tarifa($id, 500.00, $otra);

        $coste = BuscadorDeCreadores::costeEstimado($this->campana(), [$id]);

        $this->assertNull($coste[$id]['importe']);
        $this->assertStringContainsString('otra moneda', (string) $coste[$id]['aviso']);
    }

    // ------------------------------------------------------- la lista corta

    /**
     * **La prueba del reparto entre buscador y lista corta.**
     *
     * Al creador le falta un requisito de `BR-CREATOR-006`. Sale en la búsqueda
     * —eso es lo decidido— y **no** entra en la lista.
     */
    public function test_sale_en_la_busqueda_pero_el_veto_lo_para_al_anadirlo(): void
    {
        $id = $this->candidato();

        $this->assertContains($id, BuscadorDeCreadores::buscar($this->campana())->pluck('id')->all(),
            'el buscador NO revalida BR-CREATOR-006: eso es lo decidido');

        $motivos = ListaCorta::vetoParaAnadir($this->campana(), $id);

        $this->assertNotSame([], $motivos, 'y el veto si lo revalida');
        $this->assertSame(0, DB::table('campaign_creators')->count());
    }

    public function test_un_creador_completo_si_entra_y_con_su_mercado(): void
    {
        $id = $this->candidatoCompleto();

        $this->assertSame([], ListaCorta::vetoParaAnadir($this->campana(), $id));

        ListaCorta::anadir($this->campana(), $id);
        $fila = DB::table('campaign_creators')->where('creator_id', $id)->first();

        $this->assertSame('shortlisted', $fila->status);
        $this->assertNotNull($fila->campaign_market_id, 'el mercado se deriva del pais, no se pide');
        $this->assertSame(0.0, (float) $fila->agreed_amount, 'el compromiso se congela al aceptar, no aqui');
        $this->assertSame(
            (int) DB::table('creators')->where('id', $id)->value('payment_term_days'),
            (int) $fila->payment_term_days_snapshot,
            'el plazo se copia al entrar: dentro de un ano lo responde la fila, no la ficha',
        );
    }

    public function test_el_veto_dice_todos_los_motivos_de_una_vez(): void
    {
        $id = $this->candidato(['country_id' => $this->paisCO, 'birth_date' => '2010-01-01']);
        DB::table('campaigns')->where('id', $this->campanaId)->update(['min_creator_age' => 25]);

        $motivos = ListaCorta::vetoParaAnadir($this->campana(), $id);
        $texto = implode(' | ', $motivos);

        $this->assertStringContainsString('mercado', $texto);
        $this->assertStringContainsString('anos', $texto);
        $this->assertGreaterThan(2, count($motivos), 'los seis requisitos ademas de los dos vetos');
    }

    /** Una campaña cerrada no admite a nadie más, y lo dice antes de mirar nada. */
    public function test_a_una_campana_cerrada_no_se_le_anaden_creadores(): void
    {
        $id = $this->candidatoCompleto();
        $this->cerrarLaCampana();

        $motivos = ListaCorta::vetoParaAnadir($this->campana(), $id);

        $this->assertCount(1, $motivos, 'con la campana cerrada no hay nada mas que mirar');
        $this->assertStringContainsString('cerrada', $motivos[0]);
    }

    /** **Y no se le añaden ni por SQL.** De eso depende que el reporte cuadre. */
    public function test_a_una_campana_cerrada_no_se_le_anaden_ni_por_sql(): void
    {
        $id = $this->candidatoCompleto();
        $this->cerrarLaCampana();

        $this->expectException(QueryException::class);

        DB::table('campaign_creators')->insert([
            'uuid' => (string) Str::uuid(), 'campaign_id' => $this->campanaId, 'creator_id' => $id,
            'status' => 'shortlisted', 'agreed_amount' => 0, 'currency_code' => 'PEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Un candidato se quita; uno invitado, no: hubo una conversación. */
    public function test_un_invitado_ya_no_se_borra_de_la_lista(): void
    {
        $id = $this->candidatoCompleto();
        ListaCorta::anadir($this->campana(), $id);
        $fila = DB::table('campaign_creators')->where('creator_id', $id)->first();

        $this->assertNull(ListaCorta::vetoParaQuitar($fila));

        // El importe va ANTES de invitar: desde 7.5 `tg_ccr_compromiso` impide
        // invitar a alguien sin decirle cuanto se le paga (BR-CREATOR-008).
        DB::table('campaign_creators')->where('id', $fila->id)
            ->update(['agreed_amount' => 500, 'status' => 'invited', 'invited_at' => now()]);
        $fila = DB::table('campaign_creators')->where('id', $fila->id)->first();

        $this->assertNotNull(ListaCorta::vetoParaQuitar($fila));
        $this->assertStringContainsString('cancela', (string) ListaCorta::vetoParaQuitar($fila));
    }

    // ------------------------------------------------------------- pantalla

    public function test_la_pantalla_dice_por_que_esta_filtrando(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get("/backoffice/campanas/{$this->uuid}/candidatos")
            ->assertOk()
            ->assertSee('La campaña ya está filtrando por ti', false)
            ->assertSee('Edad mínima efectiva', false);
    }

    public function test_anadir_por_pantalla_veta_con_el_motivo(): void
    {
        $id = $this->candidato();

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/backoffice/campanas/{$this->uuid}/candidatos", ['creator_id' => $id])
            ->assertSessionHas('aviso');

        $this->assertSame(0, DB::table('campaign_creators')->count());
    }

    public function test_buscar_es_ver_pero_anadir_es_gestionar(): void
    {
        $revisor = $this->usuarioCon('content_reviewer');

        $this->actingAs($revisor)->get("/backoffice/campanas/{$this->uuid}/candidatos")->assertOk();
        $this->actingAs($revisor)
            ->post("/backoffice/campanas/{$this->uuid}/candidatos", ['creator_id' => $this->candidatoCompleto()])
            ->assertForbidden();
    }

    public function test_no_se_quita_el_candidato_de_otra_campana(): void
    {
        $otra = $this->campanaDe($this->clienteId, $this->marcaId);
        $this->mercadoDe($otra, $this->paisPE);
        $id = $this->candidatoCompleto();
        ListaCorta::anadir((object) ['id' => $otra, 'currency_code' => 'PEN'], $id);
        $ajena = (int) DB::table('campaign_creators')->where('campaign_id', $otra)->value('id');

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->delete("/backoffice/campanas/{$this->uuid}/candidatos/{$ajena}")
            ->assertNotFound();

        $this->assertSame(1, DB::table('campaign_creators')->where('id', $ajena)->count());
    }

    // ------------------------------------------------------------------ apoyo

    private function campana(): object
    {
        return DB::table('campaigns')->where('id', $this->campanaId)->first();
    }

    /**
     * Cierra la campaña de la prueba, con todo lo que salir de borrador exige.
     *
     * No es un `update` de dos columnas: `ck_camp_billing_entity` pide sociedad
     * y `ck_camp_revenue_declarado` pide precio declarado (7.1 y 7.2). Poner
     * sólo `closed_at` daba un `3819` que hablaba de la sociedad emisora en una
     * prueba que no va de eso — la tercera vez que una restricción vieja alcanza
     * a un fixture nuevo, y por eso vive en un método y no repetida.
     */
    private function cerrarLaCampana(): void
    {
        DB::table('campaigns')->where('id', $this->campanaId)->update([
            'billing_legal_entity_id' => $this->entidadLegal(),
            'revenue_amount' => 5000,
            'is_gratis' => 0,
            'status' => 'completed',
            'confirmed_at' => now(),
            'closed_at' => now(),
        ]);
    }

    /**
     * Un creador `active` que encaja con la campaña, **sin** completitud operativa.
     *
     * @param array<string, mixed> $cambios
     */
    private function candidato(array $cambios = [], bool $conFormato = true): int
    {
        $id = $this->creadorActivo(array_merge(['country_id' => $this->paisPE], $cambios));

        if ($conFormato) {
            DB::table('creator_formats')->insert([
                'creator_id' => $id, 'content_format_id' => $this->formatoId,
                'experience_level' => 'intermediate', 'is_offered' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /**
     * Uno que además pasa `BR-CREATOR-006` entera.
     *
     * No se monta a mano: se comprueba con la MISMA clase que decide la
     * activación y se falla en voz alta si no cumple. Un apoyo que se cree
     * completo sin serlo convierte «el veto no salta» en una prueba verde que no
     * prueba nada.
     */
    private function candidatoCompleto(): int
    {
        $id = $this->candidato();
        $this->completar($id);

        $faltan = CompletitudOperativa::pendientes(CompletitudOperativa::revisar($id));

        $this->assertSame([], $faltan, 'el apoyo tiene que dejar al creador COMPLETO: falta '
            .implode(', ', $faltan));

        return $id;
    }

    private function tarifa(int $creadorId, float $importe, string $moneda): void
    {
        DB::table('creator_rates')->insert([
            'creator_id' => $creadorId, 'content_format_id' => $this->formatoId,
            'currency_code' => $moneda, 'amount' => $importe, 'is_gratis' => 0,
            'source' => 'self_declared', 'created_by_user_id' => (int) $this->usuarioCon('campaign_manager')->id,
            'valid_from' => '2026-01-01', 'valid_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
