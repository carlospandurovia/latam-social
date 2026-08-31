<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Middleware;

use App\Modules\Core\Services\Terminos;
use App\Modules\Creator\Services\Reaceptacion;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mientras un creador no acepte los términos vigentes (9.19).
 *
 * ### Vive en Creator y no en Shared, y eso no es un detalle
 *
 * El primer sitio donde se escribió fue `App\Shared\Http\Middleware`, al lado de
 * `ExigirCambioDePassword`. No puede: `deptrac.yaml` dice `Shared: [Framework]`,
 * y esto necesita `Core\Services\Terminos`. Además pregunta por `creators`, que
 * es una tabla de Creator — y una consulta a una tabla ajena es una frontera
 * rota que `deptrac` **no ve**, que es la peor clase.
 *
 * La regla es sobre creadores y sus términos, así que su casa es Creator, que sí
 * puede conocer a Core.
 *
 * ### Los tres escalones de `Q-46`
 *
 * | Estado | Qué puede hacer |
 * |---|---|
 * | dentro del plazo | **todo**, con un aviso arriba diciendo cuántos días le quedan |
 * | pasado el plazo | **mirar**: los `GET` pasan, lo que cambia algo no |
 * | pasada la ventana | **nada**, salvo la pantalla de aceptar |
 *
 * El segundo escalón es el que hace falta escribir con cuidado: «sólo lectura»
 * no es «una pantalla de sólo lectura», es **que no pueda escribir en ninguna**.
 * Filtrar por método HTTP —`GET` y `HEAD` pasan, el resto no— cubre todas las
 * pantallas que existen y todas las que se añadan, que es la misma razón por la
 * que `ExigirCambioDePassword` va en el grupo `web` y no ruta por ruta.
 *
 * ### Sólo afecta a quien tiene un portal
 *
 * Se comprueba `user_type`, no un permiso. Un usuario interno no acepta términos
 * de creador, y un creador sin ficha —una cuenta a medio crear— tampoco tiene
 * nada que aceptar: en los dos casos el middleware se calla. Un muro que se
 * equivoca de persona deja al equipo fuera de su propio sistema.
 *
 * ### Las rutas libres, y por qué cada una
 *
 * Sin ellas esto es un bucle de redirecciones. Mismo criterio y mismo formato
 * que `ExigirCambioDePassword`: **nombres de ruta**, no URLs.
 */
final class ExigirTerminos
{
    /** @var list<string> */
    private const LIBRES = [
        // El sitio al que se redirige y su envio: redirigirlos seria un bucle.
        'terminos.mios', 'terminos.aceptar',
        // Nadie debe quedar atrapado en una sesion que no puede cerrar.
        'salir',
        // La contrasena y su recuperacion mandan sobre esto: `T-23` primero.
        'contrasena', 'contrasena.cambiar',
        'recuperar', 'recuperar.enviar', 'recuperar.usar',
        'recuperar.formulario', 'recuperar.fijar',
        // El logotipo de la marca (9.17): sin el, la pantalla del muro sale sin
        // marca, que es justo donde peor se ve.
        'marca.logo', 'marca.favicon',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::user();

        if ($usuario === null || ($usuario->user_type ?? 'internal') === 'internal') {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::LIBRES, true)) {
            return $next($request);
        }

        $creadorId = DB::table('creators')
            ->where('user_id', $usuario->getAuthIdentifier())->value('id');

        if ($creadorId === null) {
            return $next($request);
        }

        $estado = Reaceptacion::de((int) $creadorId);

        if (in_array($estado['estado'], [Terminos::AL_DIA, Terminos::PENDIENTE], true)) {
            // Dentro del plazo se pasa. El aviso lo pinta la plantilla: un muro
            // que se levanta el primer dia no da los quince dias que se
            // prometieron.
            return $next($request);
        }

        // Sólo lectura: lo que MIRA pasa, lo que CAMBIA no.
        if ($estado['estado'] === Terminos::SOLO_LECTURA && $request->isMethodSafe()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Hay que aceptar los terminos vigentes antes de seguir.',
            ], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('terminos.mios');
    }
}
