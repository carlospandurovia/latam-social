<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Landing;
use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Sitio;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * La cabecera que acompaña y las franjas que son datos (iteración L-3).
 *
 * ### Lo que fija
 *
 * Que **el encabezado de una franja y el orden de las franjas salen de la
 * base**. Hasta `L-3` «Cómo funciona» y «Preguntas» estaban escritos en el
 * `.blade.php`, y el orden de las tres franjas lo decidía la plantilla. Esta es
 * la prueba que se pone roja el día que alguien vuelva a escribirlos ahí.
 *
 * Y que **el menú de la cabecera es el de la página que se está mirando**. Las
 * anclas de la otra portada no existen aquí, y un ancla que no existe no da
 * ningún error: simplemente no pasa nada al pulsarla, que es la clase de defecto
 * que nadie reporta y todo el mundo sufre.
 */
final class CabeceraYFranjasTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        // `Marca` y `Sitio` recuerdan lo leido durante toda la peticion, y en
        // una prueba «la peticion» dura lo que dura el proceso. Sin esto, la
        // memoria de la prueba anterior contesta a esta --y la prueba del
        // WhatsApp paso sola y fallo en suite, que es la peor forma de fallar
        // porque parece un problema de orden y es un problema de memoria
        // (`T-90`)--.
        Marca::olvidar();
        Sitio::olvidar();
    }

    // ------------------------------------------------- la franja es un dato

    /** **La que más importa.** El encabezado de la franja sale de la base. */
    public function test_el_encabezado_de_una_franja_sale_de_la_base(): void
    {
        DB::table('landing_sections')->where('id', $this->seccion(Landing::MARCAS, 'como-funciona'))
            ->update(['title' => 'Un encabezado que sólo puede venir de la base']);

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('Un encabezado que sólo puede venir de la base')
            ->assertDontSee('>Cómo funciona<', escape: false);
    }

    /** Y el orden de las franjas también: se cambia sin desplegar. */
    public function test_el_orden_de_las_franjas_es_un_dato(): void
    {
        $recibes = $this->seccion(Landing::MARCAS, 'que-recibes');
        $pasos = $this->seccion(Landing::MARCAS, 'como-funciona');

        DB::table('landing_sections')->where('id', $recibes)->update(['sort_order' => 5]);
        DB::table('landing_sections')->where('id', $pasos)->update(['sort_order' => 900]);

        $html = $this->get(route('portada.marcas'))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertLessThan(
            (int) strpos($html, 'id="como-funciona"'),
            (int) strpos($html, 'id="que-recibes"'),
            'Las franjas se pintan por `sort_order`, no en el orden que decida la plantilla.',
        );
    }

    /** Una franja apagada no se pinta, y su ancla tampoco existe. */
    public function test_una_franja_apagada_no_se_pinta(): void
    {
        DB::table('landing_sections')->where('id', $this->seccion(Landing::MARCAS, 'como-funciona'))
            ->update(['is_visible' => 0]);

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertDontSee('id="como-funciona"', escape: false);
    }

    /** Cada forma tiene su parcial, y `steps` numera sola. */
    public function test_los_pasos_los_numera_la_plantilla_y_no_el_titulo(): void
    {
        $html = (string) $this->get(route('portada.marcas'))->assertOk()->getContent();

        // El numero lo pone la plantilla a partir del orden. Que NO este dentro
        // del titulo es lo que permite meter un paso en medio sin renumerar
        // cuatro titulos a mano --que era lo que hacia la semilla vieja--.
        $this->assertStringContainsString('numero-paso', $html);
        $this->assertStringNotContainsString('1. Nos cuentas la campaña', $html);
    }

    // -------------------------------------------------------------- el menú

    /** **La otra que más importa.** El menú es el de ESTA página. */
    public function test_el_menu_no_ofrece_anclas_de_la_otra_portada(): void
    {
        // «que-recibes» solo esta en marcas y «por-que» solo en creadores. Si
        // la cabecera usara una sola portada como menu de las dos --que es lo
        // que hace el valor de reserva del compositor-- el ancla de una saldria
        // en la otra, y llevaria a una seccion que ahi no existe.
        //
        // Se comprueban las DOS direcciones a proposito: con una sola, un menu
        // que siempre usara la portada de creadores tambien pasaria.
        $this->get(route('portada.marcas'))->assertOk()
            ->assertSee('#que-recibes', escape: false)
            ->assertDontSee('#por-que"', escape: false);

        $this->get(route('portada.creadores'))->assertOk()
            ->assertSee('#preguntas', escape: false)
            ->assertDontSee('#que-recibes', escape: false);
    }

    /** Y una franja que se quita del menú deja de ofrecerse. */
    public function test_una_franja_fuera_del_menu_no_sale_en_la_cabecera(): void
    {
        DB::table('landing_sections')->where('id', $this->seccion(Landing::CREADORES, 'preguntas'))
            ->update(['show_in_nav' => 0]);

        $this->get(route('portada.creadores'))->assertOk()->assertDontSee('#preguntas', escape: false);
    }

    /**
     * En una página legal el menú es el de la portada de marcas, con su URL.
     *
     * Es el valor de reserva del compositor, y existe para que quien acaba de
     * leer la política de privacidad pueda volver a la acción sin buscarla. Las
     * anclas van con el dominio delante: `#como-funciona` a secas, en `/legal`,
     * no lleva a ninguna parte.
     */
    public function test_en_una_pagina_legal_las_anclas_llevan_a_la_portada(): void
    {
        $slug = DB::table('content_pages')->value('slug');
        $this->assertNotNull($slug);

        $this->publicar((string) $slug);

        $this->get(route('pagina', ['slug' => $slug]))
            ->assertOk()
            ->assertSee(route('portada.marcas').'#como-funciona', escape: false);
    }

    // ------------------------------------------------ el botón de la calle

    /** La cabecera lleva el CTA comercial, que es el defecto `C-1`. */
    public function test_la_cabecera_lleva_el_boton_comercial(): void
    {
        DB::table('landing_pages')->where('code', Landing::MARCAS)
            ->update(['cta_label' => 'Botón de la cabecera']);

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('Botón de la cabecera')
            ->assertSee('data-evento="cta_cabecera"', escape: false);
    }

    /** En `/creadores` el botón comercial apunta a la portada de marcas. */
    public function test_en_creadores_el_boton_comercial_manda_a_marcas(): void
    {
        $this->get(route('portada.creadores'))
            ->assertOk()
            ->assertSee(route('portada.marcas').'#empezar', escape: false);
    }

    /** El WhatsApp del héroe sale de «Sitio público», nunca escrito a mano. */
    public function test_el_whatsapp_del_heroe_sale_de_la_configuracion(): void
    {
        DB::table('site_settings')->update(['whatsapp_phone' => '+51987654321']);
        Sitio::olvidar();

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('https://wa.me/51987654321', escape: false)
            ->assertSee('data-evento="whatsapp_heroe"', escape: false);
    }

    /** Y sin número configurado NO se pinta un enlace roto: no se pinta nada. */
    public function test_sin_whatsapp_no_hay_enlace_roto(): void
    {
        DB::table('site_settings')->update(['whatsapp_phone' => null]);
        Sitio::olvidar();

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertDontSee('wa.me', escape: false)
            ->assertDontSee('Escríbenos por WhatsApp');
    }

    // ------------------------------------------------------------ el editor

    public function test_quien_administra_crea_una_franja_y_sale_en_la_calle(): void
    {
        $pagina = $this->pagina(Landing::MARCAS);

        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('landing.seccion', $pagina->id), [
                'code' => 'Con quién hablas', 'layout' => 'plain',
                'title' => 'Con quién hablas de verdad', 'sort_order' => 25,
                'is_visible' => 1, 'show_in_nav' => 1,
            ])
            ->assertRedirect(route('landing.index'));

        // El ancla se normaliza: quien escribe «Con quien hablas» obtiene
        // `con-quien-hablas` sin tener que saber la regla.
        $this->assertDatabaseHas('landing_sections', [
            'landing_page_id' => $pagina->id, 'code' => 'con-quien-hablas',
        ]);

        $this->get(route('portada.marcas'))->assertOk()->assertSee('Con quién hablas de verdad');
    }

    /** Marcar una franja para el menú sin encabezado se rechaza con palabras. */
    public function test_una_franja_en_el_menu_sin_encabezado_se_rechaza_con_palabras(): void
    {
        $pagina = $this->pagina(Landing::MARCAS);

        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('landing.seccion', $pagina->id), [
                'code' => 'sin-titulo', 'layout' => 'plain',
                'is_visible' => 1, 'show_in_nav' => 1,
            ])
            ->assertSessionHas('aviso');

        $this->assertDatabaseMissing('landing_sections', ['code' => 'sin-titulo']);
    }

    /** Un bloque no se puede colar en una franja de otra portada. */
    public function test_un_bloque_no_entra_en_una_franja_de_otra_portada(): void
    {
        $ajena = $this->seccion(Landing::CREADORES, 'preguntas');

        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('landing.bloque', [$this->pagina(Landing::MARCAS)->id, $ajena]), [
                'heading' => 'Bloque colado', 'is_visible' => 1,
            ])
            ->assertSessionHas('aviso');

        $this->assertDatabaseMissing('landing_blocks', ['heading' => 'Bloque colado']);
    }

    /** Quitar una franja se lleva sus bloques: no quedan filas huérfanas. */
    public function test_quitar_una_franja_se_lleva_sus_bloques(): void
    {
        $pagina = $this->pagina(Landing::CREADORES);
        $seccion = $this->seccion(Landing::CREADORES, 'preguntas');

        $this->assertGreaterThan(0, DB::table('landing_blocks')
            ->where('landing_section_id', $seccion)->count());

        $this->actingAs($this->usuarioCon('admin'))
            ->delete(route('landing.seccion.borrar', [$pagina->id, $seccion]))
            ->assertRedirect(route('landing.index'));

        $this->assertDatabaseMissing('landing_sections', ['id' => $seccion]);
        $this->assertSame(0, DB::table('landing_blocks')
            ->where('landing_section_id', $seccion)->count());
    }

    // ---------------------------------------------------------------- avisos

    /** Una franja encendida y vacía es un encabezado sobre un hueco. */
    public function test_una_franja_sin_bloques_sale_en_ambar(): void
    {
        $seccion = $this->seccion(Landing::MARCAS, 'como-funciona');
        DB::table('landing_blocks')->where('landing_section_id', $seccion)->delete();

        $this->assertStringContainsString('sin ningún bloque visible', $this->ambares());
    }

    /** Y una portada sin nada en el menú también: es el defecto `C-1`. */
    public function test_una_portada_sin_menu_sale_en_ambar(): void
    {
        DB::table('landing_sections')->update(['show_in_nav' => 0]);

        $this->assertStringContainsString('menú de la cabecera', $this->ambares());
    }

    // ------------------------------------------------------------ auxiliares

    private function ambares(): string
    {
        return implode(' ', array_map(
            static fn ($a): string => $a->texto,
            array_filter(Landing::avisos(), static fn ($a): bool => $a->nivel === 'ambar'),
        ));
    }

    private function publicar(string $slug): void
    {
        $paginaId = DB::table('content_pages')->where('slug', $slug)->value('id');

        DB::table('content_page_versions')
            ->where('content_page_id', $paginaId)
            ->update([
                'published_at' => now(),
                'published_by_user_id' => $this->usuarioCon('admin')->id,
            ]);
    }

    private function seccion(string $code, string $ancla): int
    {
        $id = DB::table('landing_sections')
            ->where('landing_page_id', $this->pagina($code)->id)
            ->where('code', $ancla)
            ->value('id');

        $this->assertNotNull($id, "La semilla tiene que dejar la franja «{$ancla}» en «{$code}».");

        return (int) $id;
    }

    private function pagina(string $code): object
    {
        $pagina = DB::table('landing_pages')->where('code', $code)->first();

        $this->assertNotNull($pagina, "La semilla tiene que dejar la portada «{$code}».");

        return $pagina;
    }
}
