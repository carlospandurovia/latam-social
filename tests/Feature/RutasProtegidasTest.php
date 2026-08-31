<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Route as RutaDeLaravel;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Prueba estructural: ninguna ruta de negocio se queda sin permiso.
 *
 * No comprueba una funcionalidad, comprueba una **propiedad del sistema**. Añadir
 * una pantalla y olvidar su middleware es de las cosas más fáciles que hay, y el
 * olvido no falla: la pantalla funciona. Se nota cuando alguien ve algo que no
 * debía, y entonces ya es tarde.
 *
 * Al no consultar la base de datos, cuesta milisegundos y cubre todas las rutas
 * presentes y futuras a la vez.
 */
final class RutasProtegidasTest extends TestCase
{
    /**
     * Rutas tras `auth` que a propósito NO exigen permiso, con su motivo.
     * La lista es corta y tiene que seguir siéndolo: cada entrada nueva es una
     * excepción que alguien debe justificar aquí.
     *
     * @var array<string, string>
     */
    private const SIN_PERMISO = [
        'panel' => 'Portada de cualquier usuario interno; su contenido ya se filtra por permiso.',
        'salir' => 'Cerrar la propia sesión no puede depender de un permiso.',
        // `T-23`. Mismo motivo que `salir`, y uno mas: si dependiera de un
        // permiso, un usuario al que se le han revocado los permisos no podria
        // cambiar su contrasena, y ese es justo al que mas urge.
        'contrasena' => 'Cambiar la propia contrasena no puede depender de un permiso.',
        'contrasena.cambiar' => 'Idem: es la accion de la anterior.',
        // `9.19`. Es la pantalla a la que lleva el muro de los terminos y la
        // unica que puede abrir un creador bloqueado por no aceptarlos: un
        // permiso aqui dejaria sin salida justo a quien la necesita. La
        // autorizacion es tener una ficha de creador atada a la sesion, y la
        // comprueba el controlador --sin ficha, 404--.
        'terminos.mios' => 'La unica salida de un creador bloqueado por no aceptar los terminos.',
        'terminos.aceptar' => 'Idem: es la accion de la anterior, con throttle:10,1.',
    ];

    public function test_toda_ruta_autenticada_declara_su_permiso(): void
    {
        $desprotegidas = [];

        foreach (Route::getRoutes() as $ruta) {
            /** @var RutaDeLaravel $ruta */
            $middleware = $ruta->gatherMiddleware();

            if (!in_array('auth', $middleware, true)) {
                continue;  // ruta pública: no es asunto de esta prueba
            }

            $nombre = $ruta->getName() ?? $ruta->uri();

            if (array_key_exists($nombre, self::SIN_PERMISO)) {
                continue;
            }

            $declara = false;
            foreach ($middleware as $capa) {
                if (is_string($capa) && str_starts_with($capa, 'permiso:')) {
                    $declara = true;
                    break;
                }
            }

            if (!$declara) {
                $desprotegidas[] = $ruta->methods()[0].' '.$ruta->uri()." ({$nombre})";
            }
        }

        $this->assertSame(
            [],
            $desprotegidas,
            "Rutas autenticadas sin permiso declarado:\n  ".implode("\n  ", $desprotegidas)
                ."\n\nAñade ->middleware('permiso:<codigo>') o justifica la excepción en SIN_PERMISO.",
        );
    }

    /**
     * Las excepciones se revisan a mano; que no crezcan sin que nadie mire.
     *
     * El número **se sube a mano y con motivo**, que es todo el sentido de este
     * trinquete. Pasó de cuatro a seis en `9.19`: las dos rutas del muro de los
     * términos son la única salida de un creador bloqueado, y ponerles un
     * permiso dejaría sin salida justo a quien la necesita.
     */
    public function test_las_excepciones_siguen_siendo_pocas(): void
    {
        $this->assertLessThanOrEqual(
            6,
            count(self::SIN_PERMISO),
            'Hay demasiadas rutas exentas de permiso. Revísalas una por una.',
        );
    }
}
