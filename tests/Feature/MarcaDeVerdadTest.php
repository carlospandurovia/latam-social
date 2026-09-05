<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Marca;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * La marca de verdad (iteración L-1).
 *
 * ### El hallazgo
 *
 * Existía un sistema de diseño aprobado —`design/tokens.css` y `docs/14`— y
 * **lo publicado no era ése**: la instalación arrancaba con `#7C3AED` y
 * `#22D3EE`, que son violeta 600 y cian 400 **de Tailwind**. El héroe que veía
 * un visitante era el degradado por defecto del framework.
 *
 * Y el logotipo llevaba en `public/img/brand/` desde el 22 de agosto **sin que
 * lo referenciara nadie**: en su sitio se dibujaba un cuadrado de color.
 */
final class MarcaDeVerdadTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
    }

    protected function tearDown(): void
    {
        Marca::olvidar();
        parent::tearDown();
    }

    // ------------------------------------------------------------ el degradado

    /** **La que da nombre a la iteración.** Los colores son los aprobados. */
    public function test_la_instalacion_arranca_con_los_colores_de_la_marca(): void
    {
        $marca = Marca::datos();

        self::assertSame('#6635D8', $marca['color'], 'el morado, el unico color plano de marca');
        self::assertSame('#D73382', $marca['color2'], 'el magenta');
        self::assertSame('#FF7447', $marca['degradadoDesde'], 'el naranja');
        self::assertSame('#070A2B', $marca['barra']);

        self::assertNotSame('#7C3AED', $marca['color'], 'violeta 600 de Tailwind no es un color de esta marca');
        self::assertNotSame('#22D3EE', $marca['color2'], 'ni el cian 400');
    }

    /**
     * El degradado tiene **tres paradas** y el ángulo canónico.
     *
     * Con dos se salta el magenta, que es el tercio central. Y el ángulo estaba
     * escrito `135deg` en la plantilla cuando el manual dice 45.
     */
    public function test_el_degradado_tiene_tres_paradas_y_el_angulo_canonico(): void
    {
        self::assertSame(
            'linear-gradient(45deg, #FF7447, #D73382, #6635D8)',
            Marca::datos()['degradado'],
        );
    }

    /** Sin primera parada, degradado de dos colores: sigue siendo legítimo. */
    public function test_sin_primera_parada_el_degradado_es_de_dos_colores(): void
    {
        DB::table('platform_brands')->update(['gradient_from' => null]);
        Marca::olvidar();

        self::assertSame('linear-gradient(45deg, #D73382, #6635D8)', Marca::datos()['degradado']);
    }

    /**
     * El degradado se arma **en el servicio**, y lo que llega a la hoja de
     * estilo ya pasó por `color()`.
     *
     * Es la misma razón que el enlace de WhatsApp en `L-2a`: componer CSS con
     * valores de la base es código. Aquí además es una defensa: un valor que no
     * sea `#RRGGBB` no puede llegar a un `<style>`, donde una comilla o un `;`
     * escribirían CSS ajeno en todas las pantallas.
     */
    public function test_un_color_corrupto_no_llega_a_la_hoja_de_estilo(): void
    {
        // Se salta la base a proposito --el CHECK lo impediria-- para probar la
        // SEGUNDA defensa: la del servicio. Las dos tienen que sostenerse solas.
        DB::statement("UPDATE platform_brands SET primary_color = '#6635D8'");
        Marca::olvidar();

        $degradado = Marca::datos()['degradado'];

        self::assertStringNotContainsString(';', $degradado);
        self::assertStringNotContainsString("'", $degradado);
        self::assertMatchesRegularExpression(
            '/^linear-gradient\(\d{1,3}deg(, #[0-9A-Fa-f]{6})+\)$/', $degradado,
        );
    }

    /** Un ángulo fuera de rango no entra en la base. */
    public function test_un_angulo_imposible_no_entra(): void
    {
        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `ck_pb_angulo` lo rechaza. Un campo que
        // admite 3600 admite tambien que alguien tecleo el año.
        DB::table('platform_brands')->update(['gradient_angle' => 400]);
    }

    /** Y una tipografía con comillas tampoco: sería una inyección de CSS. */
    public function test_una_tipografia_con_comillas_no_entra(): void
    {
        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `ck_pb_tipografia_titulos` la rechaza.
        DB::table('platform_brands')->update(['display_font_family' => "Sora'; content:'"]);
    }

    // ------------------------------------------------------------ el logotipo

    /**
     * **El logotipo sale a la calle.** Llevaba en el repositorio desde agosto.
     */
    public function test_sin_archivo_subido_se_sirve_el_logotipo_del_repositorio(): void
    {
        $marca = Marca::datos();

        self::assertStringEndsWith('img/brand/logo-horizontal.svg', $marca['logo']);
        self::assertStringEndsWith('img/brand/isotipo.svg', $marca['isotipo']);
        self::assertFalse($marca['logoPropio']);
    }

    /**
     * Y son **dos** variantes, no una.
     *
     * `docs/14 §7`: el horizontal en las landings, el isotipo en el back-office.
     * El horizontal mide 1122×530: en un hueco cuadrado queda del alto de un
     * sello.
     */
    public function test_la_portada_usa_el_horizontal_y_el_panel_el_isotipo(): void
    {
        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('img/brand/logo-horizontal.svg', false)
            ->assertDontSee('img/brand/isotipo.svg', false);

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('panel'))
            ->assertOk()
            ->assertSee('img/brand/isotipo.svg', false);
    }

    /** Ya no queda ningún cuadrado de degradado haciendo de logotipo. */
    public function test_ya_no_se_dibuja_un_cuadrado_en_lugar_del_logotipo(): void
    {
        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertDontSee('<div class="w-8 h-8 rounded-lg degradado-marca"></div>', false);
    }

    // ------------------------------------------------------------ lo que lee un buscador

    /**
     * `og:image` existía y **no la referenciaba nadie**.
     *
     * Compartir el enlace por WhatsApp o LinkedIn producía una tarjeta sin
     * imagen, y este sitio va a recibir su tráfico justamente de ahí.
     */
    public function test_la_portada_lleva_la_tarjeta_para_compartir(): void
    {
        $respuesta = $this->get(route('portada.marcas'));

        $respuesta->assertOk();
        $respuesta->assertSee('property="og:image"', false);
        $respuesta->assertSee('img/brand/og-image.png', false);
        $respuesta->assertSee('name="twitter:card" content="summary_large_image"', false);
        $respuesta->assertSee('rel="canonical"', false);
        $respuesta->assertSee('property="og:url"', false);
        $respuesta->assertSee('rel="manifest"', false);
    }

    /**
     * El idioma se declara desde la configuración, no escrito a mano.
     *
     * `§26` pide que traducir mañana no obligue a tocar plantillas.
     */
    public function test_el_idioma_sale_de_la_configuracion(): void
    {
        config(['app.locale' => 'pt_BR']);

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('<html lang="pt-BR"', false);
    }

    /** Y si la aplicación no está en español, se avisa: nadie lo ve mirando. */
    public function test_un_idioma_que_no_es_espanol_avisa(): void
    {
        config(['app.locale' => 'en']);
        Marca::olvidar();

        $textos = implode(' ', array_column(Marca::avisos(), 'texto'));

        self::assertStringContainsString('APP_LOCALE', $textos);
    }

    /** `robots.txt` y `sitemap.xml` los sirve la aplicación, no un archivo. */
    public function test_el_mapa_del_sitio_lista_las_portadas_publicadas(): void
    {
        $respuesta = $this->get('/sitemap.xml');

        $respuesta->assertOk();
        $respuesta->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $respuesta->assertSee(route('portada.creadores'), false);
    }

    /** Una portada apagada desaparece del mapa: no se manda a un buscador a una puerta cerrada. */
    public function test_una_portada_apagada_sale_del_mapa(): void
    {
        DB::table('landing_pages')->where('code', 'creadores')->update(['is_published' => false]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee(route('portada.creadores'), false);
    }

    /**
     * **Una instalación que no es producción se prohíbe a sí misma.**
     *
     * Un servidor de pruebas indexado compite en Google con el de verdad y le
     * roba las visitas, y eso se descubre meses después. Es la idea de `9.22a`
     * —lo que sale de aquí no puede confundirse con lo real— aplicada a los
     * buscadores.
     */
    public function test_una_instalacion_que_no_es_produccion_no_se_deja_indexar(): void
    {
        config(['instalacion.entorno' => 'staging']);

        $respuesta = $this->get('/robots.txt');

        $respuesta->assertOk();
        $respuesta->assertSee('Disallow: /', false);
        $respuesta->assertDontSee('Allow: /', false);
    }

    /** Y la de verdad sí, con el mapa apuntado. */
    public function test_en_produccion_se_deja_indexar_y_apunta_al_mapa(): void
    {
        config(['instalacion.entorno' => 'production']);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Allow: /', false)
            ->assertSee('Disallow: /backoffice/', false)
            ->assertSee('Sitemap: '.route('sitemap'), false);
    }
}
