<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Auth\Permisos;
use App\Shared\Config\Preparacion;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Cada cosa en su sitio (iteración 9.20).
 *
 * ### Lo que fija
 *
 * Que **la configuración tiene una sola puerta**. Hasta `9.20` nueve pantallas
 * estaban dos veces —sueltas en el menú lateral y dentro de `/configuracion`— y
 * entrar desde el panel dejaba al usuario en una pantalla que no decía de dónde
 * venía, con el menú marcando otra entrada. El desorden no era el orden: era la
 * falta de jerarquía.
 *
 * Las tres cosas que no pueden volver a romperse:
 *
 * 1. El menú lateral **no lleva atajos** a pantallas de configuración.
 * 2. Toda pantalla de configuración dice **de dónde viene**.
 * 3. Un área registrada en `Preparacion` **tiene grupo**, así que aparece en la
 *    portada: un área sin grupo se quedaría en «Otros» y se vería igual, pero
 *    una que nadie registra no se ve en ninguna parte.
 */
final class NavegacionTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    /** Las rutas que son configuración y que por tanto NO van en el menú. */
    /**
     * Las pantallas de configuración que se pintan solas.
     *
     * `series.index` y `certificados.index` NO están: desde `9.17f` redirigen a
     * la pestaña de facturación electrónica de Integraciones, que sí está. La
     * miga la pone ella; ellas ya no pintan nada.
     */
    private const CONFIGURACION = [
        'marca.index', 'terminos.index', 'politica.index', 'integraciones.index',
        'entidades.index', 'cambio.index', 'catalogos.index',
        'landing.index', 'impuestos.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
    }

    // ------------------------------------------------------------- el menú

    /**
     * **La que más importa.** El menú no lleva atajos a la configuración.
     *
     * Se mira el panel como administrador, que es quien más permisos tiene y por
     * tanto quien más entradas ve: si a alguien le sale un atajo, le sale a él.
     */
    public function test_el_menu_no_lleva_atajos_a_la_configuracion(): void
    {
        $html = $this->actingAs($this->usuarioCon('admin'))->get(route('panel'))
            ->assertOk()->getContent();

        $menu = $this->soloElMenu((string) $html);

        foreach (self::CONFIGURACION as $ruta) {
            $this->assertStringNotContainsString(
                'href="'.route($ruta).'"',
                $menu,
                "El menú lateral lleva un atajo a `{$ruta}`, y eso es la duplicidad que 9.20 quitó.",
            );
        }

        $this->assertStringContainsString('href="'.route('configuracion').'"', $menu);
    }

    /** Y sí lleva lo que se usa para trabajar, agrupado. */
    public function test_el_menu_lleva_el_trabajo_del_dia_en_grupos(): void
    {
        $menu = $this->soloElMenu(
            (string) $this->actingAs($this->usuarioCon('admin'))->get(route('panel'))->getContent(),
        );

        foreach (['Operación', 'Finanzas', 'Registros', 'Ajustes'] as $titulo) {
            $this->assertStringContainsString($titulo, $menu);
        }

        foreach (['campanas.index', 'lotes.index', 'bitacora'] as $ruta) {
            $this->assertStringContainsString('href="'.route($ruta).'"', $menu);
        }
    }

    /**
     * Un grupo entero desaparece si no se puede ver nada suyo.
     *
     * Un título sin nada debajo es peor que no tenerlo: hace pensar que falta
     * algo o que se rompió la pantalla.
     */
    public function test_un_grupo_sin_nada_visible_no_sale(): void
    {
        $menu = $this->soloElMenu(
            (string) $this->actingAs($this->usuarioCon('campaign_manager'))->get(route('panel'))->getContent(),
        );

        $this->assertStringContainsString('Operación', $menu);
        $this->assertStringNotContainsString('Finanzas', $menu);
    }

    // -------------------------------------------------------- la miga de pan

    /**
     * Toda pantalla de configuración dice de dónde viene.
     *
     * Ésta es la que habría cazado el problema tal cual lo describió quien lo
     * sufrió: «me voy a configuración, hago clic en alguna y me manda a uno de
     * los menús».
     */
    public function test_cada_pantalla_de_configuracion_vuelve_a_configuracion(): void
    {
        $admin = $this->usuarioCon('admin');

        foreach (self::CONFIGURACION as $ruta) {
            $html = (string) $this->actingAs($admin)->get(route($ruta))->assertOk()->getContent();

            $this->assertStringContainsString(
                'aria-label="Dónde estoy"',
                $html,
                "`{$ruta}` no dice de dónde viene: sin la miga, entrar ahí es perder el sitio.",
            );
            $this->assertStringContainsString('href="'.route('configuracion').'"', $html);
        }
    }

    /** Y estando dentro, «Configuración» se queda encendida en el menú. */
    public function test_dentro_de_una_pantalla_de_configuracion_el_menu_la_marca(): void
    {
        $menu = $this->soloElMenu(
            (string) $this->actingAs($this->usuarioCon('admin'))
                ->get(route('integraciones.index', ['p' => 'fel']))->getContent(),
        );

        // La clase que marca la entrada activa, en la línea de Configuración.
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('configuracion'), '/').'"[^>]*bg-marca-500/s',
            $menu,
        );
    }

    // ----------------------------------------------------------- los grupos

    /** Todas las áreas registradas tienen un grupo conocido. */
    public function test_toda_area_registrada_cae_en_un_grupo_conocido(): void
    {
        $revision = Preparacion::revision(static fn (string $p): bool => true);

        $conocidos = [
            Preparacion::IDENTIDAD, Preparacion::FISCAL,
            Preparacion::CONEXIONES, Preparacion::CATALOGOS, Preparacion::OTROS,
        ];

        foreach ($revision as $area) {
            $this->assertContains($area['grupo'], $conocidos, "«{$area['area']}» no tiene grupo.");
        }

        // Y ninguna se quedó en «Otros», que es el cajón de sastre: estar ahí es
        // no haber decidido dónde va.
        $this->assertSame([], array_values(array_filter(
            $revision,
            static fn (array $a): bool => $a['grupo'] === Preparacion::OTROS,
        )));
    }

    /** Los grupos salen en su orden, y no en el de la urgencia del día. */
    public function test_los_grupos_salen_siempre_en_el_mismo_orden(): void
    {
        $grupos = Preparacion::porGrupos(
            Preparacion::revision(static fn (string $p): bool => true),
        );

        $this->assertSame(
            [Preparacion::IDENTIDAD, Preparacion::FISCAL,
                Preparacion::CONEXIONES, Preparacion::CATALOGOS],
            array_column($grupos, 'grupo'),
        );
    }

    /** La portada los pinta agrupados. */
    public function test_la_portada_de_configuracion_sale_por_grupos(): void
    {
        $this->actingAs($this->usuarioCon('admin'))->get(route('configuracion'))
            ->assertOk()
            ->assertSee(Preparacion::IDENTIDAD)
            ->assertSee(Preparacion::FISCAL)
            ->assertSee(Preparacion::CATALOGOS);
    }

    // ------------------------------------------------- la calle y la trastienda

    /**
     * **Todo lo que exige sesión vive bajo `/backoffice`** (9.21a).
     *
     * `/creadores` era la lista del admin, y tiene que ser la puerta pública de
     * los creadores. Esta prueba es lo que impide que una ruta nueva vuelva a
     * plantarse en la raíz sin querer: se recorren **todas** las rutas con
     * nombre y se comprueba una por una.
     *
     * La lista de excepciones se escribe a mano, y ése es el punto: dejar algo
     * fuera de `/backoffice` tiene que ser una decisión, no un descuido.
     */
    public function test_todo_lo_que_exige_sesion_vive_bajo_backoffice(): void
    {
        // Lo que un desconocido tiene que poder abrir. Cada una con su motivo:
        // el acceso y la recuperación son la puerta; la invitación y la
        // aprobación llegan por un enlace con token a alguien que no tiene
        // cuenta; el logotipo lo pinta la propia pantalla de acceso.
        $publicas = [
            'acceso', 'entrar', 'recuperar', 'recuperar.enviar', 'recuperar.usar',
            'recuperar.formulario', 'recuperar.fijar',
            'invitacion.ver', 'invitacion.oferta', 'invitacion.aceptar', 'invitacion.rechazar',
            'invitacion.preguntar', 'invitacion.gracias', 'invitacion.caducada',
            'aprobacion.ver', 'aprobacion.pieza', 'aprobacion.responder',
            'aprobacion.gracias', 'aprobacion.caducada',
            'marca.logo', 'marca.favicon',
            // 9.21b: las dos portadas y la postulación. Son la calle: si
            // pidieran sesión no servirían para nada.
            'portada.marcas', 'portada.creadores', 'portada.gracias', 'postular',
            'contacto', 'contacto.gracias',
        ];

        $fuera = [];

        foreach (app('router')->getRoutes() as $ruta) {
            $nombre = $ruta->getName();

            if ($nombre === null || in_array($nombre, $publicas, true)) {
                continue;
            }

            if (!str_starts_with($ruta->uri(), 'backoffice/')) {
                $fuera[] = $nombre.' → /'.$ruta->uri();
            }
        }

        $this->assertSame([], $fuera, "Estas rutas exigen sesión y no están bajo `/backoffice`:\n"
            .implode("\n", $fuera)."\nSi alguna debe ser pública, escríbela en la lista de arriba con su motivo.");
    }

    /** Y las públicas siguen fuera, que es la otra mitad. */
    public function test_la_puerta_sigue_abierta_para_quien_no_tiene_cuenta(): void
    {
        foreach (['acceso', 'recuperar'] as $nombre) {
            $this->assertStringNotContainsString('backoffice', route($nombre));
        }

        $this->get(route('acceso'))->assertOk();
    }

    // -------------------------------------------------------- los catálogos

    public function test_los_catalogos_tienen_portada_y_siguen_donde_estaban(): void
    {
        $this->actingAs($this->usuarioCon('admin'))->get(route('catalogos.index'))
            ->assertOk()
            ->assertSee('Países')
            ->assertSee('Monedas')
            ->assertSee(route('catalogos.show', 'countries'), escape: false);

        // La pantalla de cada catálogo no se ha movido: los enlaces guardados
        // siguen valiendo.
        $this->actingAs($this->usuarioCon('admin'))->get(route('catalogos.show', 'countries'))
            ->assertOk();
    }

    public function test_la_portada_de_catalogos_pide_permiso(): void
    {
        $this->actingAs($this->usuarioCon('creator'))
            ->get(route('catalogos.index'))->assertStatus(403);
    }

    /**
     * Sólo el menú, no la página entera.
     *
     * Sin recortar, la prueba de «no hay atajos» se pondría verde o roja según
     * lo que hubiera en el CONTENIDO —la portada de configuración enlaza a las
     * nueve pantallas, y debe hacerlo—. Lo que se afirma es lo que hay en la
     * barra lateral.
     */
    private function soloElMenu(string $html): string
    {
        $desde = strpos($html, '<nav');
        $hasta = strpos($html, '</nav>');

        $this->assertNotFalse($desde, 'La página no tiene menú.');
        $this->assertNotFalse($hasta);

        return substr($html, $desde, $hasta - $desde);
    }
}
