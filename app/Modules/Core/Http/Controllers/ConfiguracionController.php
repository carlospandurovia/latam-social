<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Shared\Auth\Permisos;
use App\Shared\Config\Preparacion;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Qué falta por configurar, en una sola pantalla (9.17b).
 *
 * ### Lo que resuelve
 *
 * `9.16` y `9.17` dejaron cada una su lista de avisos con prioridad, y cada una
 * **sólo se veía entrando en su pantalla**. Para saber qué falta había que
 * recorrer seis pantallas acordándose de las seis. Ésta las junta.
 *
 * ### No hay ni un botón
 *
 * A propósito. Esta pantalla **contesta una pregunta** —«¿qué me falta?»— y
 * lleva al sitio donde se arregla; no arregla nada. Una pantalla que hace las dos
 * cosas termina teniendo la mitad de los formularios de las otras seis, y
 * entonces hay dos sitios donde cambiar lo mismo.
 *
 * ### Y no bloquea nada, que es todo el asunto
 *
 * `DEC-190`: *«no me digas que algo es un stopper, eso no debe ser así; ponme
 * prioridades y alguna nota en un badge en rojo o amarillo, según la
 * importancia»*. Aquí no hay estado «no se puede operar»: hay una lista
 * ordenada por lo que más duele, y quien opera decide en qué orden la atiende.
 */
final class ConfiguracionController
{
    public function __invoke(): View
    {
        $usuarioId = (int) Auth::id();

        // El permiso se pregunta con un cierre y no consultando aqui dentro:
        // `Preparacion` vive en `Shared`, que en `deptrac.yaml` no puede
        // depender de nada, y es este controlador --que si esta en Core-- quien
        // sabe como se contesta esa pregunta.
        $revision = Preparacion::revision(
            static fn (string $permiso): bool => Permisos::tiene($usuarioId, $permiso),
        );

        return view('configuracion.index', [
            'revision' => $revision,
            // 9.20: la pantalla se pinta por grupos. El reparto y su orden salen
            // del registro, no de la plantilla.
            'grupos' => Preparacion::porGrupos($revision),
            'recuento' => Preparacion::recuento($revision),
            // Cuantas areas existen en total, para poder decir «hay N que no ves
            // porque no son tuyas» en vez de dejar que alguien crea que el
            // sistema entero son las dos que le salen.
            'totalAreas' => count(Preparacion::areasRegistradas()),
        ]);
    }
}
