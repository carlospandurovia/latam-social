<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
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
        // A dónde va quien no ha iniciado sesión.
        $middleware->redirectGuestsTo('/entrar');
        // Y a dónde va quien ya la inició e intenta volver al formulario.
        $middleware->redirectUsersTo('/panel');
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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
