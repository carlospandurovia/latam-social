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
 * La historia que cuenta la portada de marcas (iteración L-4).
 *
 * ### Lo que fija
 *
 * Que **la razón social de la empresa NO está escrita dentro del texto de la
 * portada**. La franja «por qué confiar» la nombra, y escribirla a mano sería
 * `DEC-190` roto en el peor sitio: el día que la empresa cambie de nombre habría
 * que buscarla por toda la portada. Es la prueba que se pone roja si alguien
 * sustituye el marcador por el nombre.
 *
 * Y que **el cierre dejó de repetir el botón** (`C-3`). Hasta la `L-4` el título
 * de la sección del formulario ERA `cta_label`, así que la misma frase salía
 * tres veces y la página leía como una plantilla rellenada.
 */
final class LaHistoriaDeLaPortadaTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        // `T-90`: la memoria de estos dos dura lo que dura el proceso.
        Marca::olvidar();
        Sitio::olvidar();
    }

    // ------------------------------------------------------- los marcadores

    /** **La que más importa.** La razón social no está escrita en el texto. */
    public function test_la_razon_social_sale_de_la_configuracion_y_no_del_texto(): void
    {
        $razon = DB::table('legal_entities')->where('code', 'CTS_PE')->value('legal_name');
        $this->assertNotNull($razon);

        DB::table('landing_blocks')
            ->where('landing_section_id', $this->seccion(Landing::MARCAS, 'por-que-confiar'))
            ->where('icon', 'escudo')
            ->update(['body' => 'Somos {{ empresa.razon_social }}, y punto.']);

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('Somos '.$razon.', y punto.')
            ->assertDontSee('{{ empresa.razon_social }}', escape: false);
    }

    /** Y si cambia la razón social, cambia la portada. Sin tocar el texto. */
    public function test_cambiar_la_razon_social_cambia_la_portada(): void
    {
        DB::table('landing_blocks')
            ->where('landing_section_id', $this->seccion(Landing::MARCAS, 'por-que-confiar'))
            ->where('icon', 'escudo')
            ->update(['body' => 'La empresa es {{ empresa.razon_social }}.']);

        DB::table('legal_entities')->where('code', 'CTS_PE')
            ->update(['legal_name' => 'Otra Sociedad Distinta S.A.C.']);

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('La empresa es Otra Sociedad Distinta S.A.C.');
    }

    /**
     * Un marcador que no se puede resolver sale en ROJO en el panel.
     *
     * En la calle sale como una raya, y eso lo ve quien está decidiendo si te
     * escribe: pesa más que en un documento legal, que lo lee quien ya es
     * cliente.
     */
    public function test_un_marcador_sin_valor_sale_en_rojo(): void
    {
        DB::table('landing_pages')->where('code', Landing::MARCAS)
            ->update(['subheadline' => 'Escríbenos al {{ sitio.telefono }}.']);

        // Sin telefono configurado, el marcador no se puede resolver.
        DB::table('site_settings')->update(['contact_phone' => null]);
        Sitio::olvidar();

        $rojos = implode(' ', array_map(
            static fn ($a): string => $a->texto,
            array_filter(Landing::avisos(), static fn ($a): bool => $a->nivel === 'rojo'),
        ));

        $this->assertStringContainsString('sitio.telefono', $rojos);
    }

    // ------------------------------------------------ el cierre y el héroe

    /** `C-3`: el cierre ya no repite el texto del botón. */
    public function test_el_cierre_no_repite_el_boton(): void
    {
        DB::table('landing_pages')->where('code', Landing::MARCAS)->update([
            'cta_label' => 'Botón que se pulsa',
            'form_heading' => 'Encabezado que se lee',
            'form_intro' => 'Y la frase que lo acompaña.',
        ]);

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('Encabezado que se lee')
            ->assertSee('Y la frase que lo acompaña.');
    }

    /** Y sin encabezado propio se sigue usando el botón: nada bloquea (`DEC-190`). */
    public function test_sin_encabezado_propio_el_cierre_usa_el_boton(): void
    {
        DB::table('landing_pages')->where('code', Landing::MARCAS)->update([
            'cta_label' => 'Etiqueta de reserva',
            'form_heading' => null,
            'form_intro' => null,
        ]);

        $this->get(route('portada.marcas'))->assertOk()->assertSee('Etiqueta de reserva');
    }

    /** `V-2`: el héroe ya no deja la mitad derecha vacía, y no finge una foto. */
    public function test_el_heroe_lleva_la_composicion_y_no_una_foto_inventada(): void
    {
        $html = (string) $this->get(route('portada.marcas'))->assertOk()->getContent();

        $this->assertStringContainsString('class="voces', $html);
        // Ni una imagen de archivo: no tenemos ninguna que sea nuestra, y una
        // cara de banco de imagenes junto a «creadores reales» se lee tan falso
        // como una metrica inventada (§12).
        $this->assertStringNotContainsString('unsplash', $html);
        $this->assertStringNotContainsString('<img src="https://', $html);
    }

    // -------------------------------------------------------- la narrativa

    /** Las preguntas van ANTES del formulario, también en la portada de marcas. */
    public function test_las_preguntas_van_antes_del_formulario(): void
    {
        $html = (string) $this->get(route('portada.marcas'))->assertOk()->getContent();

        $this->assertLessThan(
            (int) strpos($html, 'id="empezar"'),
            (int) strpos($html, 'id="preguntas"'),
            'La sección que quita objeciones no puede estar detrás del punto de conversión.',
        );
    }

    /** La portada de marcas ya tiene preguntas: era el defecto `C-4`. */
    public function test_la_portada_de_marcas_responde_objeciones(): void
    {
        $this->assertGreaterThanOrEqual(6, DB::table('landing_blocks')
            ->where('landing_section_id', $this->seccion(Landing::MARCAS, 'preguntas'))
            ->count());
    }

    /**
     * Y no promete ninguna métrica que no exista (§12).
     *
     * No es una prueba de estilo: es la única forma de que una cifra inventada
     * no entre por descuido en una revisión de copy dentro de seis meses.
     */
    public function test_la_portada_no_presume_de_numeros_que_no_existen(): void
    {
        $textos = DB::table('landing_blocks')->pluck('body')
            ->merge(DB::table('landing_blocks')->pluck('heading'))
            ->merge(DB::table('landing_sections')->pluck('title'))
            ->merge(DB::table('landing_sections')->pluck('subtitle'))
            ->merge(DB::table('landing_pages')->pluck('headline'))
            ->merge(DB::table('landing_pages')->pluck('subheadline'))
            ->filter()->implode(' ');

        foreach (['creadores activos', 'campañas realizadas', 'clientes satisfechos',
            'años de experiencia', 'de ROI', 'millones de'] as $presuncion) {
            $this->assertStringNotContainsStringIgnoringCase($presuncion, $textos);
        }
    }

    /** La franja del claim usa el degradado, y es la ÚNICA que lo usa. */
    public function test_el_degradado_se_reserva_para_el_claim(): void
    {
        $conDegradado = DB::table('landing_sections')
            ->join('landing_pages', 'landing_pages.id', '=', 'landing_sections.landing_page_id')
            ->where('landing_pages.code', Landing::MARCAS)
            ->where('landing_sections.layout', 'claim')
            ->count();

        // `docs/14 §6` lo reserva para momentos: en tres franjas dejaria de
        // significar nada.
        $this->assertSame(1, $conDegradado);
    }

    // ------------------------------------------------------------ el editor

    public function test_quien_administra_escribe_el_cierre_desde_el_panel(): void
    {
        $pagina = DB::table('landing_pages')->where('code', Landing::MARCAS)->first();
        $this->assertNotNull($pagina);

        $this->actingAs($this->usuarioCon('admin'))
            ->put(route('landing.update', $pagina->id), [
                'headline' => 'Un titular suficientemente largo',
                'cta_label' => 'Hablemos',
                'form_heading' => 'Escrito desde el panel',
                'form_intro' => 'Con su frase.',
                // Sin esto la portada se APAGA --el formulario del panel manda
                // siempre la casilla-- y `/` redirige al acceso. Lo descubrio
                // esta misma prueba con un 302 donde esperaba un 200.
                'is_published' => 1,
            ])
            ->assertRedirect(route('landing.index'));

        $this->get(route('portada.marcas'))->assertOk()->assertSee('Escrito desde el panel');
    }

    // ------------------------------------------------------------ auxiliares

    private function seccion(string $code, string $ancla): int
    {
        $paginaId = DB::table('landing_pages')->where('code', $code)->value('id');

        $id = DB::table('landing_sections')
            ->where('landing_page_id', $paginaId)->where('code', $ancla)->value('id');

        $this->assertNotNull($id, "La semilla tiene que dejar la franja «{$ancla}» en «{$code}».");

        return (int) $id;
    }
}
