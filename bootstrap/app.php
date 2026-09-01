<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use App\Modules\Creator\Http\Middleware\ExigirTerminos;
use App\Shared\Http\Middleware\ExigirCambioDePassword;
use App\Shared\Http\Middleware\ExigirPermiso;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // A dónde va quien no ha iniciado sesión, y a dónde quien ya la inició
        // e intenta volver al formulario.
        //
        // ### `T-81`: aquí había DOS direcciones escritas a mano
        //
        // Y una de ellas estuvo rota desde `9.21a`. La mudanza a `/backoffice`
        // presumía de costar «una línea y ni una URL que corregir», y era casi
        // verdad: `docs/08` prohíbe escribir URLs en las **vistas**, así que las
        // 149 pantallas se mudaron solas. Lo que nadie miró fue `bootstrap/`,
        // que no es una vista y por eso no lo cubría ni la regla ni la búsqueda
        // que se hizo entonces —`app/`, `resources/` y `config/`—.
        //
        // El resultado, reportado así: *«el entrar me lleva a NOT FOUND»*. Quien
        // YA tenía sesión y pulsaba «Entrar» caía en `guest`, que lo mandaba a
        // `/panel`, que desde `9.21a` no existe.
        //
        // Ahora salen del enrutador. Un cierre que acepta `Request` es lo que
        // permite usar `route()` aquí: en el momento de configurar el
        // middleware todavía no hay rutas cargadas, así que la cadena literal no
        // era pereza —era la forma obvia—, y el cierre es la que no se rompe.
        $middleware->redirectGuestsTo(fn (): string => route('acceso'));
        $middleware->redirectUsersTo(fn (): string => route('panel'));
        // `->middleware('permiso:creator.view')` en las rutas.
        $middleware->alias(['permiso' => ExigirPermiso::class]);

        // `T-23`. Va en el GRUPO y no en cada ruta a proposito: una obligacion
        // que hay que acordarse de poner en cada pantalla nueva es una
        // obligacion que se salta la primera pantalla que alguien olvide. Aqui
        // cubre todo lo que existe y todo lo que se anada.
        //
        // El middleware se calla si no hay sesion o si la marca no esta, asi
        // que ponerlo en `web` entero no afecta al formulario de acceso.
        $middleware->appendToGroup('web', ExigirCambioDePassword::class);

        // `9.19`. Va DESPUES de la contrasena y por el mismo motivo que aquel
        // va en el grupo: una obligacion que hay que acordarse de poner en cada
        // pantalla nueva es una obligacion que se salta la primera que alguien
        // olvide. Aqui cubre todo lo que existe y todo lo que se anada.
        //
        // El orden importa: quien tiene que cambiar la contrasena la cambia
        // primero. Encadenar dos muros al reves dejaria a alguien aceptando
        // terminos con una credencial que conoce un tercero.
        //
        // Se calla para los usuarios internos y para quien no tenga ficha de
        // creador, asi que ponerlo en `web` entero no afecta al equipo.
        $middleware->appendToGroup('web', ExigirTerminos::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
