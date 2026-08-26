<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mientras `must_change_password` esté puesto, no se llega a ningún otro sitio
 * (`T-23`).
 *
 * ### La columna existía desde 3.1 y no la leía nadie
 *
 * `usuarios:crear` la escribía y no había middleware que la comprobara ni
 * pantalla donde cambiar la contraseña. Un dato que nadie lee es un requisito
 * que no se cumple: el administrador que creaba la cuenta de finanzas conocía su
 * contraseña indefinidamente, y la segregación de funciones que la base exige
 * para el dinero (`ck_ctp_segregation`, `ck_cpm_segregation`) se apoyaba en
 * cuentas cuya credencial conocía un tercero.
 *
 * ### Las tres excepciones, y por qué cada una
 *
 * Sin ellas esto es un bucle de redirecciones, que es la forma más rápida de
 * dejar a alguien fuera de su propia cuenta:
 *
 * | Ruta | Por qué se deja pasar |
 * |---|---|
 * | `contrasena` (GET) | es el sitio al que redirige; redirigirla sería un bucle |
 * | `contrasena.cambiar` (PUT) | si no pasa, no hay forma de quitar la marca |
 * | `salir` | nadie debe quedar atrapado en una sesión que no puede cerrar |
 * | `recuperar*` (`4.1`) | un enlace válido tiene que valer aunque haya sesión abierta |
 *
 * Se comparan **nombres de ruta**, no URLs: una URL escrita a mano aquí se
 * desincroniza en cuanto alguien renombra la ruta, y el síntoma sería el bucle.
 */
final class ExigirCambioDePassword
{
    /** @var list<string> */
    private const LIBRES = [
        'contrasena', 'contrasena.cambiar', 'salir',
        // `4.1`: quien llega con un enlace valido puede usarlo aunque tenga la
        // marca puesta. Es la misma logica --sin estas, la unica salida seria
        // saber la contrasena que precisamente no sabe--.
        'recuperar', 'recuperar.enviar', 'recuperar.usar',
        'recuperar.formulario', 'recuperar.fijar',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::user();

        if ($usuario === null || !($usuario->must_change_password ?? false)) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::LIBRES, true)) {
            return $next($request);
        }

        // A una petición que espera JSON se le contesta con un código, no con
        // una redirección a una pantalla que no va a poder pintar.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Hay que cambiar la contrasena antes de seguir.',
            ], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('contrasena')->with(
            'aviso',
            'Tienes que cambiar tu contrasena antes de seguir. La que te dieron la conoce '
            .'quien te creo la cuenta, y de eso depende que la separacion de funciones '
            .'signifique algo.',
        );
    }
}
