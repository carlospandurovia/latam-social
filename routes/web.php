<?php

declare(strict_types=1);

use App\Modules\Core\Http\Controllers\BitacoraController;
use App\Modules\Core\Http\Controllers\CatalogosController;
use App\Modules\Core\Http\Controllers\PanelController;
use App\Modules\Creator\Http\Controllers\CreadoresController;
use App\Modules\Identity\Http\Controllers\AccesoController;
use Illuminate\Support\Facades\Route;

/*
 | Rutas del back-office.
 |
 | Sin URLs escritas a mano en las vistas: todo va por nombre de ruta, que es
 | una de las reglas no negociables de docs/08.
 */

Route::redirect('/', '/panel');

Route::middleware('guest')->group(function (): void {
    Route::get('/entrar', [AccesoController::class, 'formulario'])->name('acceso');
    // Limita los intentos: 5 por minuto y por IP. Sin esto, la pantalla de
    // acceso es un oráculo de contraseñas.
    Route::post('/entrar', [AccesoController::class, 'entrar'])
        ->middleware('throttle:5,1')
        ->name('entrar');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/salir', [AccesoController::class, 'salir'])->name('salir');

    Route::get('/panel', PanelController::class)->name('panel');

    // Cada ruta de negocio declara el permiso que exige. `RutasProtegidasTest`
    // comprueba que no se cuele ninguna sin declararlo: es fácil añadir una
    // pantalla y olvidar el middleware, y el olvido no se nota hasta que alguien
    // ve algo que no debía.
    Route::get('/bitacora', BitacoraController::class)
        ->middleware('permiso:audit.view')
        ->name('bitacora');

    Route::get('/catalogos/{catalogo}', [CatalogosController::class, 'show'])
        ->middleware('permiso:catalog.view')
        ->name('catalogos.show');

    Route::get('/creadores', [CreadoresController::class, 'index'])
        ->middleware('permiso:creator.view')
        ->name('creadores.index');
    Route::get('/creadores/{uuid}', [CreadoresController::class, 'show'])
        ->middleware('permiso:creator.view')
        ->whereUuid('uuid')
        ->name('creadores.show');

    // Escritura: permiso propio, distinto del de lectura. Poder mirar y poder
    // corregir no son la misma autorización.
    Route::get('/creadores/{uuid}/editar', [CreadoresController::class, 'edit'])
        ->middleware('permiso:creator.manage')
        ->whereUuid('uuid')
        ->name('creadores.edit');
    Route::put('/creadores/{uuid}', [CreadoresController::class, 'update'])
        ->middleware('permiso:creator.manage')
        ->whereUuid('uuid')
        ->name('creadores.update');
});
