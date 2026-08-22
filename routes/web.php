<?php

declare(strict_types=1);

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

    Route::get('/catalogos/{catalogo}', [CatalogosController::class, 'show'])
        ->name('catalogos.show');

    Route::get('/creadores', [CreadoresController::class, 'index'])->name('creadores.index');
    Route::get('/creadores/{uuid}', [CreadoresController::class, 'show'])
        ->whereUuid('uuid')
        ->name('creadores.show');
});
