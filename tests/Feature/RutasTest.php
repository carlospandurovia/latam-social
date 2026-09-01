<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Comportamiento de las rutas del back-office.
 *
 * Sustituye a la prueba de ejemplo de Laravel, que afirmaba que `/` devuelve
 * 200. Aquí no hay portada pública: `/` redirige al panel y el panel exige
 * sesión. La prueba de ejemplo no fallaba por un error de la aplicación, sino
 * porque describía otra aplicación.
 *
 * Ninguna de estas toca la base de datos: comprueban enrutado y middleware.
 * Las que sí la necesitan —entrar con credenciales, la lista blanca de
 * catálogos, los permisos— llegan con la iteración 3.1, que es donde se
 * construye el middleware de permisos y donde esas pruebas son el entregable.
 */
final class RutasTest extends TestCase
{
    /**
     * Las vistas llevan `@vite`, que exige el manifiesto de `npm run build`.
     * Estas pruebas comprueban ENRUTADO, no compilación de assets: hacerlas
     * depender de que alguien haya construido el CSS las volvería frágiles y
     * lentas por un motivo que no tiene nada que ver con lo que afirman.
     * Que el CSS compile es una puerta aparte del CI.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * La raíz es la calle, no la trastienda (9.21b).
     *
     * `9.21a` mudó el back-office a `/backoffice` y `9.21b` puso aquí la portada
     * de las marcas. Sin portada sembrada —que es el caso de esta prueba, que no
     * siembra nada— lleva al acceso, **no a un 404**: una instalación sin
     * contenido que enseñar tiene que dejar entrar igual.
     */
    public function test_la_raiz_lleva_a_la_portada_o_al_acceso(): void
    {
        $this->get('/')->assertRedirect(route('acceso'));
    }

    /** Sin sesión no se ve el panel. */
    public function test_el_panel_exige_sesion(): void
    {
        $this->get('/backoffice/panel')->assertRedirect(route('acceso'));
    }

    public function test_la_pantalla_de_acceso_es_publica(): void
    {
        $this->get(route('acceso'))->assertOk();
    }

    /** Las rutas de negocio están detrás de `auth`, no solo ocultas del menú. */
    public function test_las_rutas_de_negocio_exigen_sesion(): void
    {
        $this->get('/backoffice/creadores')->assertRedirect(route('acceso'));
        $this->get('/backoffice/catalogos/currencies')->assertRedirect(route('acceso'));
    }
}
