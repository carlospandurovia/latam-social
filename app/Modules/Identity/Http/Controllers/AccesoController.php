<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class AccesoController
{
    public function formulario(): View
    {
        return view('auth.login');
    }

    public function entrar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // El mensaje es el mismo tanto si el correo no existe como si la
        // contraseña falla: distinguirlos permite averiguar qué correos
        // están dados de alta.
        if (!Auth::attempt($datos, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas.',
            ]);
        }

        $usuario = Auth::user();

        // Un usuario desactivado o suspendido no entra, aunque su contraseña
        // sea válida (BR-SEC / BR-CREATOR-001 aplicado a usuarios internos).
        if (($usuario->status ?? 'active') !== 'active') {
            Auth::logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages([
                'email' => 'Esta cuenta no está activa. Contacta con administración.',
            ]);
        }

        $request->session()->regenerate();
        $usuario->forceFill(['last_login_at' => now()])->save();

        return redirect()->to(self::aDondeVa($request));
    }

    /**
     * A dónde se manda a alguien que acaba de entrar.
     *
     * ### Por qué no es `redirect()->intended()` a secas
     *
     * `intended()` obedece a lo que haya guardado en la sesión **aunque esa
     * dirección ya no exista**. Y desde `9.21a` hay muchas que no existen: la
     * mudanza a `/backoffice` movió 149 rutas, así que toda sesión abierta antes
     * de desplegar tiene guardado un `/panel`, un `/creadores` o un `/clientes`
     * que hoy son un 404. El síntoma es exactamente el que se reportó: entrar
     * con la contraseña correcta y aterrizar en «404 NOT FOUND».
     *
     * No es un caso raro ni pasado: **le pasa a todo el mundo el día del
     * despliegue**, y a cada usuario una sola vez, que es la peor forma de un
     * fallo —se arregla solo antes de que nadie lo pueda reproducir—.
     *
     * Así que la dirección guardada tiene que pasar dos filtros antes de usarse:
     *
     * 1. **Es de esta casa.** Un `url.intended` con dominio ajeno sería un
     *    redirect abierto. Hoy sólo lo escribe el framework con la URL de la
     *    petición, pero eso es una propiedad de hoy y esto es la puerta de
     *    entrada del sistema.
     * 2. **Todavía resuelve, Y no por el comodín.** Si el enrutador no la
     *    reconoce, no se usa: se va al panel, que es donde iría alguien que
     *    entra sin más.
     *
     * ### El comodín, y por qué hay que descontarlo (`L-2b`)
     *
     * `L-2b` añadió `/{slug}` en la raíz para las páginas del sitio, y eso
     * **resucitó todas las direcciones muertas de un solo segmento**: `/panel`
     * volvió a «resolver» —contra el comodín— y esta puerta lo dio por bueno.
     * El síntoma habría vuelto a ser el mismo que en `9.21a`: entrar con la
     * contraseña correcta y aterrizar en un 404.
     *
     * Lo encontró `AterrizajeTest`, que existe justamente por aquel fallo. Es la
     * clase de daño que hace una ruta comodín: no rompe nada de lo suyo, revive
     * lo ajeno.
     */
    private static function aDondeVa(Request $peticion): string
    {
        $guardada = (string) $peticion->session()->pull('url.intended', '');
        $panel = route('panel');

        if ($guardada === '') {
            return $panel;
        }

        $propia = str_starts_with($guardada, '/')
            || str_starts_with($guardada, $peticion->getSchemeAndHttpHost().'/');

        return $propia && self::sigueExistiendo($guardada) ? $guardada : $panel;
    }

    /** ¿El enrutador todavía reconoce esa dirección? */
    private static function sigueExistiendo(string $url): bool
    {
        $camino = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        try {
            $ruta = Route::getRoutes()->match(Request::create($camino, 'GET'));

            // El comodin de `L-2b` acepta CUALQUIER segmento, asi que resolver
            // contra el no prueba que la direccion exista. Y una pagina publica
            // no es sitio al que mandar a quien acaba de entrar al panel.
            return $ruta->getName() !== 'pagina';
        } catch (Throwable) {
            // `match()` lanza para «no existe» y para «existe con otro verbo».
            // Las dos son «no la uses»: un GET a una ruta que solo acepta POST
            // es otro 405 en la cara de quien acaba de teclear su contrasena.
            return false;
        }
    }

    public function salir(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('acceso');
    }
}
