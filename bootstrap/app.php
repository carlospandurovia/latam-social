<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
