<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Paginas;
use App\Modules\Core\Services\Reemplazos;
use App\Modules\Core\Services\Sitio;
use App\Shared\Auth\Permisos;
use App\Shared\Config\Aviso;
use App\Shared\Texto\Marcado;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Las páginas públicas del sitio (iteración L-2b).
 *
 * ### Lo que fija
 *
 * Que el texto legal **no lleva escrita la razón social**: lleva marcadores, y
 * los valores salen de donde ya viven. Que una versión publicada **no se
 * reescribe**. Que una página **no puede tapar una ruta**. Y que el HTML escrito
 * en el editor **se enseña, no se ejecuta**.
 */
final class PaginasDelSitioTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $autorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
        Sitio::olvidar();

        $this->autorId = (int) $this->usuarioCon('admin')->id;
    }

    protected function tearDown(): void
    {
        Sitio::olvidar();
        parent::tearDown();
    }

    // ------------------------------------------------------ los dos documentos

    /**
     * **La que da nombre a la iteración.** Las dos páginas legales se siembran
     * con su texto, y **como borrador**.
     *
     * Publicar es un acto con responsable (`ck_cpv_publicada`), y al sembrar no
     * hay ninguno. Atribuírselo al usuario de id más bajo sería poner el nombre
     * de una persona al pie de un documento legal que no ha leído.
     */
    public function test_las_dos_paginas_legales_se_siembran_sin_publicar(): void
    {
        $paginas = Paginas::todas()->keyBy('slug');

        self::assertTrue($paginas->has('politica-de-privacidad'));
        self::assertTrue($paginas->has('terminos-y-condiciones'));

        foreach ($paginas as $p) {
            self::assertNull($p->published_at, 'se siembran sin publicar, a proposito');
            self::assertTrue((bool) $p->is_system);
        }

        // Y el texto SI esta escrito: lo que falta es el clic, no el documento.
        $texto = (string) DB::table('content_page_versions')->value('body_markdown');
        self::assertGreaterThan(3000, mb_strlen($texto));
        self::assertStringContainsString('{{empresa.razon_social}}', $texto,
            'el documento NO lleva escrita la razon social: lleva el marcador');
    }

    /** Y el sitio no las enseña hasta que se publican: el pie no pinta un 404. */
    public function test_sin_publicar_no_salen_en_el_pie_ni_se_pueden_abrir(): void
    {
        self::assertCount(0, Paginas::delPie());

        $this->get('/politica-de-privacidad')->assertNotFound();
        $this->get(route('portada.marcas'))->assertOk()->assertDontSee('Política de privacidad', false);
    }

    /** **Publicada, se ve, y los marcadores están sustituidos.** */
    public function test_publicada_se_ve_con_los_datos_de_la_empresa(): void
    {
        $this->publicar('politica-de-privacidad');

        $respuesta = $this->get('/politica-de-privacidad');

        $respuesta->assertOk();
        $respuesta->assertSee('Soluciones Tecnológicas a Medida S.A.C.', false);
        $respuesta->assertSee('20603203896', false);
        // Lo que NO puede verse jamas en una pagina publica.
        $respuesta->assertDontSee('{{empresa.razon_social}}', false);
        $respuesta->assertDontSee('{{', false);

        // Y sale en el pie de la portada.
        $this->get(route('portada.marcas'))->assertOk()->assertSee('Política de privacidad', false);
    }

    // ------------------------------------------------------ los marcadores

    /** Un marcador sin valor se pinta como una raya, **nunca entre llaves**. */
    public function test_un_marcador_sin_valor_no_sale_entre_llaves(): void
    {
        DB::table('site_settings')->update(['operator_legal_entity_id' => null]);
        Sitio::olvidar();

        $salida = Reemplazos::aplicar('El responsable es {{empresa.razon_social}}.');

        self::assertSame('El responsable es '.Reemplazos::SIN_VALOR.'.', $salida);
        self::assertStringNotContainsString('{{', $salida);
    }

    /** Y el sistema lo dice en rojo: nadie lee su propia política de privacidad. */
    public function test_un_marcador_sin_resolver_avisa_en_rojo(): void
    {
        $this->publicar('politica-de-privacidad');
        DB::table('site_settings')->update(['operator_legal_entity_id' => null]);
        Sitio::olvidar();

        $rojos = array_filter(Paginas::avisos(), static fn (Aviso $a): bool => $a->nivel === Aviso::ROJO);
        $textos = implode(' ', array_map(static fn (Aviso $a): string => $a->texto, $rojos));

        self::assertStringContainsString('empresa.razon_social', $textos);
        self::assertStringContainsString('se pintan como una raya', $textos);
    }

    /**
     * El domicilio se arma sin comas huérfanas ni repeticiones.
     *
     * En Lima, `city` y `region` valen las dos «LIMA», y «LIMA, LIMA» en una
     * política de privacidad se lee como un error.
     */
    public function test_el_domicilio_no_repite_ni_deja_comas_huerfanas(): void
    {
        $domicilio = Reemplazos::valores()['empresa.domicilio'];

        self::assertStringNotContainsString(', ,', $domicilio);
        self::assertSame(
            count(array_unique(explode(', ', $domicilio))),
            count(explode(', ', $domicilio)),
            'ninguna parte del domicilio se repite',
        );
    }

    /**
     * El catálogo del editor y lo que se sustituye son **la misma lista**.
     *
     * Se configura todo primero, a propósito: la pregunta no es «¿tiene valor
     * hoy?» —eso ya lo contesta el aviso rojo— sino «¿sabe el motor resolver
     * esto?». Dos listas separadas es cómo se llega a un editor que ofrece un
     * marcador que nadie sustituye, y quien lo use se encuentra una raya en su
     * documento legal sin entender por qué.
     */
    public function test_el_catalogo_no_ofrece_marcadores_que_nadie_sustituye(): void
    {
        DB::table('platform_brands')->update([
            'website' => 'https://latamsocial.com', 'support_email' => 'soporte@latamsocial.test',
        ]);
        DB::table('site_settings')->update([
            'contact_email' => 'hola@latamsocial.test', 'contact_phone' => '+51 1 000 0000',
            'whatsapp_phone' => '+51987654321', 'public_address' => 'Av. Demo 100, Lima',
        ]);
        Marca::olvidar();
        Sitio::olvidar();

        $resolubles = array_merge(
            array_keys(Reemplazos::valores()),
            ['pagina.titulo', 'pagina.vigente_desde'],
        );

        foreach (array_keys(Reemplazos::CATALOGO) as $marcador) {
            self::assertContains($marcador, $resolubles,
                "el editor ofrece «{$marcador}» y nada lo sustituye");
        }
    }

    // ------------------------------------------------------ la seguridad

    /**
     * **El HTML escrito en el editor se enseña, no se ejecuta.**
     *
     * Es XSS almacenado en la página más pública del sitio: basta con que
     * alguien le robe la sesión a quien edita.
     */
    public function test_el_html_del_editor_no_se_ejecuta(): void
    {
        $html = Marcado::aHtml('Hola <script>alert(1)</script> y <img src=x onerror=alert(1)>');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * **Las tablas se pintan como tablas.**
     *
     * CommonMark **no lleva tablas**: son una extensión de GitHub, no del
     * estándar. Se vio mirando la política publicada: la tabla de «para qué
     * usamos los datos y con qué legitimación» salía como un párrafo lleno de
     * barras verticales. Un documento legal con una tabla rota se lee como un
     * documento a medio hacer, y esa tabla es justo la que alguien consulta.
     */
    public function test_las_tablas_de_un_documento_legal_se_pintan(): void
    {
        $html = Marcado::aHtml("| Para qué | Base |\n|---|---|\n| Pagar | Contrato |");

        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<th>Para qué</th>', $html);
        self::assertStringNotContainsString('|---|', $html);
    }

    /**
     * Un valor **de fábrica** que se cuela en un documento público se avisa.
     *
     * Un marcador que se resuelve no es lo mismo que un marcador que se resuelve
     * bien: el domicilio sembrado dice «Por completar», y eso lo lee un tercero
     * en la política de privacidad.
     */
    public function test_un_valor_de_fabrica_en_un_documento_publico_avisa(): void
    {
        $this->publicar('politica-de-privacidad');

        $textos = implode(' ', array_map(
            static fn (Aviso $a): string => $a->texto,
            array_filter(Paginas::avisos(), static fn (Aviso $a): bool => $a->nivel === Aviso::AMBAR),
        ));

        self::assertStringContainsString('empresa.domicilio', $textos);
        self::assertStringContainsString('valor de partida', $textos);
    }

    /** Ni un enlace `javascript:`. */
    public function test_un_enlace_javascript_no_pasa(): void
    {
        self::assertStringNotContainsString(
            'javascript:', Marcado::aHtml('[pulsa](javascript:alert(1))'),
        );
    }

    /** Y un marcador tampoco puede ejecutar nada: sólo se sustituye texto. */
    public function test_un_marcador_inventado_no_ejecuta_nada(): void
    {
        $salida = Reemplazos::aplicar('{{ php.exec }} {{ marca.nombre }}');

        self::assertSame(Reemplazos::SIN_VALOR.' LATAM Social', $salida);
    }

    // ------------------------------------------------------ el versionado

    /** **Una versión publicada no se reescribe**: se publica la siguiente. */
    public function test_una_version_publicada_no_se_reescribe(): void
    {
        $this->publicar('politica-de-privacidad');
        $id = (int) DB::table('content_page_versions')->whereNotNull('published_at')->value('id');

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_cpv_inmutable` lo rechaza. Es el
        // texto que alguien pudo haber leido el dia que nos dio sus datos.
        DB::table('content_page_versions')->where('id', $id)
            ->update(['body_markdown' => 'Otra cosa completamente distinta.']);
    }

    /** Publicar la siguiente cierra la anterior el día antes, y sólo hay una vigente. */
    public function test_publicar_la_siguiente_cierra_la_anterior(): void
    {
        $uuid = $this->publicar('politica-de-privacidad');

        Paginas::guardarBorrador($uuid, str_repeat('Texto nuevo de la segunda versión. ', 5));
        Paginas::publicar($uuid, now()->addDay()->toDateString(), $this->autorId);

        $paginaId = (int) Paginas::porUuid($uuid)->id;
        $vigentes = DB::table('content_page_versions')->where('content_page_id', $paginaId)
            ->whereNotNull('published_at')->whereNull('effective_to')->count();

        self::assertSame(1, $vigentes, 'una sola vigente: `uq_cpv_vigente` lo garantiza');
        self::assertSame('1.1', (string) Paginas::conVigente('politica-de-privacidad')->version);
        self::assertCount(2, Paginas::historial($paginaId)->where('published_at', '!=', null));
    }

    /** La revisión jurídica **sí** se puede anotar sobre una publicada. */
    public function test_la_revision_juridica_se_anota_sobre_la_publicada(): void
    {
        $uuid = $this->publicar('politica-de-privacidad');

        Paginas::marcarRevision($uuid, ['review_status' => 'revisado', 'review_note' => 'Estudio X, 2026']);

        self::assertSame('revisado',
            (string) Paginas::conVigente('politica-de-privacidad')->review_status,
            'el disparador protege el TEXTO, no el estado de la revision');
    }

    /**
     * §56: mientras nadie la revise, se dice — **y no se bloquea nada**.
     */
    public function test_un_texto_sin_revisar_avisa_en_ambar(): void
    {
        $this->publicar('politica-de-privacidad');
        $this->publicar('terminos-y-condiciones');

        $textos = implode(' ', array_map(
            static fn (Aviso $a): string => $a->texto,
            array_filter(Paginas::avisos(), static fn (Aviso $a): bool => $a->nivel === Aviso::AMBAR),
        ));

        self::assertStringContainsString('NO ha revisado ningún abogado', $textos);
        self::assertStringContainsString('no un dictamen', $textos);
    }

    // ------------------------------------------------------ no tapar rutas

    /**
     * **Una página no puede ocupar la dirección de una pantalla que existe.**
     *
     * Y la lista no está escrita a mano: se le pregunta al enrutador. Una lista
     * escrita se queda vieja el día que se añade una ruta, y el fallo aparece
     * meses después con la forma de «una portada dejó de funcionar».
     */
    public function test_una_pagina_no_puede_tapar_una_ruta(): void
    {
        $reservadas = Paginas::reservadas();

        self::assertContains('creadores', $reservadas);
        self::assertContains('entrar', $reservadas);
        self::assertContains('contacto', $reservadas);
        self::assertContains('backoffice', $reservadas);

        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('paginas.guardar'), [
                'title' => 'Creadores', 'slug' => 'creadores',
            ])
            ->assertSessionHasErrors('slug');
    }

    /** Y las rutas que ya existían siguen resolviéndose: el comodín va el último. */
    public function test_el_comodin_no_se_traga_las_rutas_que_ya_estaban(): void
    {
        $this->get(route('portada.creadores'))->assertOk();
        $this->get(route('acceso'))->assertOk();
        $this->get('/robots.txt')->assertOk();
    }

    // ------------------------------------------------------ el admin

    public function test_la_pantalla_exige_permiso(): void
    {
        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('paginas.index'))->assertForbidden();
    }

    /** Una página del sistema no se borra; su texto se cambia entero. */
    public function test_una_pagina_del_sistema_no_se_borra(): void
    {
        $uuid = (string) DB::table('content_pages')->where('slug', 'politica-de-privacidad')->value('uuid');

        $this->actingAs($this->usuarioCon('admin'))
            ->delete(route('paginas.borrar', ['uuid' => $uuid]))
            ->assertRedirect();

        self::assertSame(1, DB::table('content_pages')->where('uuid', $uuid)->count());
    }

    /** Una página normal sí. */
    public function test_una_pagina_normal_se_crea_y_se_borra(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('paginas.guardar'), ['title' => 'Sobre nosotros', 'slug' => 'sobre-nosotros'])
            ->assertRedirect();

        $uuid = (string) DB::table('content_pages')->where('slug', 'sobre-nosotros')->value('uuid');
        self::assertNotSame('', $uuid);

        $this->actingAs($this->usuarioCon('admin'))
            ->delete(route('paginas.borrar', ['uuid' => $uuid]));

        self::assertSame(0, DB::table('content_pages')->where('slug', 'sobre-nosotros')->count());
    }

    /** El editor enseña la vista previa **con los marcadores ya sustituidos**. */
    public function test_el_editor_ensena_la_vista_previa_resuelta(): void
    {
        $uuid = (string) DB::table('content_pages')->where('slug', 'politica-de-privacidad')->value('uuid');

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('paginas.editar', ['uuid' => $uuid]))
            ->assertOk()
            ->assertSee('Soluciones Tecnológicas a Medida S.A.C.', false)
            ->assertSee('Cómo se va a ver', false);
    }

    // ------------------------------------------------------ utilería

    private function publicar(string $slug): string
    {
        $uuid = (string) DB::table('content_pages')->where('slug', $slug)->value('uuid');
        Paginas::publicar($uuid, now()->toDateString(), $this->autorId);

        return $uuid;
    }
}
