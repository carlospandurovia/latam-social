<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Paginas;
use App\Modules\Core\Services\Sitio;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Los rótulos fuera de la plantilla y lo que leen los buscadores (iteración L-6).
 *
 * ### Lo que fija
 *
 * `R-2`: que las palabras de la calle **salgan de `lang/`** y no de un
 * `.blade.php`. Sacarlas una vez es fácil; que sigan fuera dentro de seis
 * iteraciones no lo es, porque la próxima persona que añada un campo escribirá
 * «Correo» donde le toque y **nadie lo notará** —el sitio sigue en español y se
 * ve igual—. De eso se encarga `tools/verificar-rotulos.py`; esto comprueba que
 * el mecanismo funciona de verdad.
 *
 * Y `§20`: que el `Organization` que leen los buscadores diga quién es la
 * empresa **sin una palabra escrita a mano**. Un dato mal ahí no se nota
 * mirando la página: se nota seis meses después, en un buscador.
 */
final class RotulosYBuscadoresTest extends TestCase
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
        Sitio::olvidar();
    }

    // ---------------------------------------------------------- los rótulos

    /** **La que más importa.** El rótulo sale del archivo de idioma. */
    public function test_los_rotulos_de_la_calle_salen_del_archivo_de_idioma(): void
    {
        // Se afirma la PALABRA, no `__('publico.entrar')`.
        //
        // Compararla con `__()` era una tautologia: cuando falta la traduccion,
        // `__()` devuelve la clave y la pagina pinta la clave, asi que la
        // asercion pasaba en verde **con el sitio entero en clave**. Es
        // exactamente lo que paso --lo encontro el barrido de la `L-7`-- y es
        // la sexta vez en este proyecto que una asercion pasa por el motivo
        // equivocado.
        $this->get(route('portada.marcas'))->assertOk()->assertSee('Entrar');

        // Y no por casualidad: cambiando la traduccion en memoria, cambia la
        // pagina. Si «Entrar» estuviera escrito en el Blade, esto no la movería.
        //
        // Las lineas se anaden al idioma EN VIGOR y no a `es` a secas: la
        // bateria corre en otro idioma y el archivo de espanol entra por
        // respaldo, asi que una linea puesta en `es` no la habria visto nadie
        // --y la prueba habria fallado por el motivo equivocado--.
        app('translator')->addLines(['publico.entrar' => 'Rótulo cambiado'], app()->getLocale());
        Marca::olvidar();

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('Rótulo cambiado')
            ->assertDontSee('>Entrar<', escape: false);
    }

    /** El idioma del documento sale de la aplicación, no escrito a mano. */
    public function test_el_idioma_del_documento_sale_de_la_aplicacion(): void
    {
        config(['app.locale' => 'es_PE']);
        $this->get(route('portada.marcas'))->assertOk()->assertSee('<html lang="es-PE"', escape: false);
    }

    /** El archivo de idioma existe y no tiene claves vacías. */
    public function test_ningun_rotulo_esta_vacio(): void
    {
        $ruta = lang_path('es/publico.php');
        $this->assertTrue(File::exists($ruta), 'Tiene que haber `lang/es/publico.php`.');

        $plano = [];
        $aplanar = static function (array $arbol, string $prefijo = '') use (&$aplanar, &$plano): void {
            foreach ($arbol as $clave => $valor) {
                $nombre = $prefijo === '' ? (string) $clave : $prefijo.'.'.$clave;
                is_array($valor) ? $aplanar($valor, $nombre) : $plano[$nombre] = $valor;
            }
        };
        $aplanar(require $ruta);

        $this->assertNotEmpty($plano);

        foreach ($plano as $clave => $valor) {
            $this->assertIsString($valor, "«{$clave}» tiene que ser texto.");
            $this->assertNotSame('', trim($valor), "«{$clave}» está vacío: en la pantalla sería un hueco.");
        }
    }

    /**
     * **La que más importa de la `L-7`.** Con un idioma regional no se pinta la clave.
     *
     * `APP_LOCALE=es_PE` es exactamente lo que la `L-6` le pide poner al
     * operador. Laravel busca `lang/es_PE/`, no lo encuentra, cae en el respaldo
     * `en` —que este proyecto no tiene— y **pinta la clave**: `publico.entrar`
     * en la cabecera y `publico.pie.para_marcas` en el pie, en todas las páginas
     * públicas, con un 200 y sin un solo error.
     */
    public function test_con_un_idioma_regional_no_se_pinta_la_clave(): void
    {
        foreach (['es_PE', 'es_CO', 'es_MX'] as $idioma) {
            app()->setLocale($idioma);

            $html = (string) $this->get(route('portada.marcas'))->assertOk()->getContent();

            $this->assertStringNotContainsString(
                'publico.', $html, "Con «{$idioma}» se está pintando la clave en vez del rótulo.",
            );
            $this->assertStringContainsString('Entrar', $html);
        }
    }

    /**
     * Y los mensajes de Laravel tampoco: un correo mal escrito dice una frase.
     *
     * Hasta la `L-6` no había carpeta `lang/`, así que Laravel usaba sus
     * traducciones internas y el mensaje salía en inglés. Al crearla, el
     * traductor pasó a buscar en `lang/` y empezó a devolver `validation.email`
     * **en la cara de quien intenta escribirnos**. Lo encontró el barrido
     * enviando el formulario, que es una cosa que ninguna prueba hacía.
     */
    public function test_un_correo_mal_escrito_se_explica_con_palabras(): void
    {
        $peru = DB::table('countries')->where('iso2', 'PE')->value('id');

        $respuesta = $this->post(route('contacto'), [
            'company_name' => 'Marca', 'contact_name' => 'Quien', 'country_id' => $peru,
            'email' => 'esto-no-es-un-correo',
        ]);

        $errores = session('errors');
        $this->assertNotNull($errores);
        $mensaje = (string) $errores->first('email');

        $this->assertStringNotContainsString('validation.', $mensaje);
        $this->assertStringContainsString('correo', mb_strtolower($mensaje));
    }

    /** Y falta un campo obligatorio: también con palabras, y con el nombre de la pantalla. */
    public function test_un_campo_que_falta_se_nombra_como_en_la_pantalla(): void
    {
        $this->post(route('contacto'), ['email' => 'quien@ejemplo.test']);

        $mensaje = (string) session('errors')?->first('company_name');

        $this->assertStringNotContainsString('validation.', $mensaje);
        // «company_name» es el nombre de una columna; «la empresa o marca» es el
        // del hueco que la persona esta mirando.
        $this->assertStringNotContainsString('company_name', $mensaje);
    }

    // ------------------------------------------------------- los buscadores

    /** El `Organization` sale, y es JSON válido. */
    public function test_el_organization_es_json_valido_y_dice_quien_somos(): void
    {
        $html = (string) $this->get(route('portada.marcas'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<script type="application/ld\+json">(.+?)</script>#s', $html,
        );

        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $coincidencias);
        $datos = json_decode($coincidencias[1], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Organization', $datos['@type']);
        $this->assertSame(Marca::datos()['nombre'], $datos['name']);
    }

    /** Y la razón social sale de la configuración, no escrita en la plantilla. */
    public function test_la_razon_social_del_organization_sale_de_la_configuracion(): void
    {
        DB::table('legal_entities')->where('code', 'CTS_PE')
            ->update(['legal_name' => 'Sociedad Que Cambia S.A.C.']);

        $this->get(route('portada.marcas'))->assertOk()->assertSee('Sociedad Que Cambia S.A.C.');
    }

    /**
     * Sin redes publicadas, `sameAs` **no se pinta**.
     *
     * Declararle a un buscador un array vacío es afirmar «no tenemos redes»,
     * que no es lo mismo que «todavía no están configuradas».
     */
    public function test_sin_redes_no_se_declara_un_sameas_vacio(): void
    {
        DB::table('social_links')->delete();
        Sitio::olvidar();

        $this->get(route('portada.marcas'))->assertOk()->assertDontSee('sameAs', escape: false);
    }

    /** Con redes, salen todas. */
    public function test_con_redes_el_organization_las_declara(): void
    {
        DB::table('social_links')->insert([
            'platform_brand_id' => Marca::idActual(), 'network' => 'instagram',
            'label' => 'Instagram', 'url' => 'https://instagram.com/ejemplo',
            'sort_order' => 10, 'is_visible' => 1, 'created_at' => now(),
        ]);
        Sitio::olvidar();

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('https://instagram.com/ejemplo', escape: false);
    }

    /**
     * Un domicilio que todavía dice «Por completar» **no se declara**.
     *
     * Salió mirando el JSON. En un documento legal ese texto al menos le grita
     * al operador que lo complete; aquí se lo estábamos declarando a un
     * buscador, que lo guarda y lo enseña. Mejor no decir la dirección que decir
     * una que no es.
     */
    public function test_un_domicilio_de_fabrica_no_se_declara(): void
    {
        $html = (string) $this->get(route('portada.marcas'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Por completar', $html);
        $this->assertStringNotContainsString('streetAddress', $html);
    }

    /** Y uno completo sí. */
    public function test_un_domicilio_completo_si_se_declara(): void
    {
        DB::table('legal_entities')->where('code', 'CTS_PE')
            ->update(['address_line1' => 'Av. Ejemplo 123', 'city' => 'Lima']);

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('Av. Ejemplo 123', escape: false)
            ->assertSee('streetAddress', escape: false);
    }

    /**
     * El mapa del sitio lista también las páginas publicadas.
     *
     * Faltaban: la política de privacidad y los términos son páginas públicas,
     * con su URL y su contenido, y no estaban. Si sale en el pie, un buscador
     * tiene que poder encontrarla.
     */
    public function test_el_mapa_del_sitio_lista_las_paginas_publicadas(): void
    {
        $slug = (string) DB::table('content_pages')->value('slug');
        $usuario = $this->usuarioCon('admin');

        foreach (DB::table('content_pages')->pluck('uuid') as $uuid) {
            Paginas::publicar(
                (string) $uuid, date('Y-m-d'), (int) $usuario->id,
            );
        }

        $this->get(route('sitemap'))
            ->assertOk()
            ->assertSee(route('portada.marcas'), escape: false)
            ->assertSee(route('pagina', ['slug' => $slug]), escape: false);
    }

    /** Una página sin publicar no se le ofrece a un buscador. */
    public function test_una_pagina_sin_publicar_no_entra_en_el_mapa(): void
    {
        $slug = (string) DB::table('content_pages')->value('slug');

        // Las dos se siembran como BORRADOR (`L-2b`): publicar es un acto con
        // un responsable, y el sembrador no puede ser el responsable de nadie.
        $this->get(route('sitemap'))
            ->assertOk()
            ->assertDontSee(route('pagina', ['slug' => $slug]), escape: false);
    }

    // ------------------------------------------------------- el rendimiento

    /**
     * La tipografía de titulares pide **un solo peso**.
     *
     * Se usa siempre en negrita —las seis apariciones de `fuente-titulos` van
     * con `font-bold`— así que pedir cuatro pesos eran tres archivos que el
     * navegador descargaba para no usarlos nunca.
     */
    public function test_la_tipografia_de_titulares_pide_un_solo_peso(): void
    {
        $html = (string) $this->get(route('portada.marcas'))->assertOk()->getContent();

        $this->assertStringContainsString('sora:700&amp;display=swap', $html);
        $this->assertStringNotContainsString('sora:400,500,600,700', $html);
    }

    /** Y todas piden `display=swap`: sin él, el titular sale en blanco un instante. */
    public function test_todas_las_tipografias_piden_display_swap(): void
    {
        $html = (string) $this->get(route('portada.marcas'))->assertOk()->getContent();

        preg_match_all('#fonts\.bunny\.net/css\?family=[^"]+#', $html, $enlaces);

        $this->assertNotEmpty($enlaces[0]);

        foreach ($enlaces[0] as $enlace) {
            $this->assertStringContainsString('display=swap', $enlace);
        }
    }

    /** El foco se ve: quien navega con teclado tiene que saber dónde está. */
    public function test_el_foco_es_visible(): void
    {
        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee(':focus-visible', escape: false);
    }
}
