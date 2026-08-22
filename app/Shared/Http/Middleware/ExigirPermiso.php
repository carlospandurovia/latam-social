<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Auth\Permisos;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un permiso para llegar a la ruta: `->middleware('permiso:creator.view')`.
 *
 * Con varios códigos la semántica es O: `permiso:a,b` deja pasar a quien tenga
 * cualquiera de los dos. Es lo que hace falta para pantallas que sirven a más de
 * un rol; para exigir todos, se encadenan dos middleware.
 *
 * Devuelve 403, no 404. Ocultar la existencia del recurso sería defendible en
 * una API pública, pero esto es un back-office donde todos los usuarios son
 * internos y conocidos: un 404 aquí solo consigue que quien no tiene permiso
 * crea que la pantalla está rota y abra una incidencia.
 */
final class ExigirPermiso
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        $usuario = $request->user();

        // Sin sesión no es cosa de este middleware: `auth` va antes y redirige.
        // Si alguien lo coloca sin `auth` delante, mejor 403 que un error feo.
        if ($usuario === null) {
            abort(403, 'Se requiere iniciar sesión.');
        }

        $usuarioId = (int) $usuario->getAuthIdentifier();

        if ($permisos === [] || !Permisos::tieneAlguno($usuarioId, $permisos)) {
            abort(403, 'No tiene permiso para esta sección.');
        }

        return $next($request);
    }
}
