<?php

declare(strict_types=1);

use App\Modules\Core\Http\Controllers\BitacoraController;
use App\Modules\Core\Http\Controllers\CatalogosController;
use App\Modules\Core\Http\Controllers\PanelController;
use App\Modules\Creator\Http\Controllers\ActivacionController;
use App\Modules\Creator\Http\Controllers\CreadoresController;
use App\Modules\Creator\Http\Controllers\MediosPagoController;
use App\Modules\Creator\Http\Controllers\PerfilComercialController;
use App\Modules\Creator\Http\Controllers\PerfilFiscalController;
use App\Modules\Creator\Http\Controllers\RedesSocialesController;
use App\Modules\Creator\Http\Controllers\SolicitudesController;
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

    // La bandeja de entrada del sistema. Permiso propio: revisar identidades y
    // dar de alta no es lo mismo que corregir un teléfono.
    Route::get('/solicitudes', [SolicitudesController::class, 'index'])
        ->middleware('permiso:creator.approve')->name('solicitudes.index');
    Route::get('/solicitudes/{uuid}', [SolicitudesController::class, 'show'])
        ->middleware('permiso:creator.approve')->whereUuid('uuid')->name('solicitudes.show');
    Route::post('/solicitudes/{uuid}/aprobar', [SolicitudesController::class, 'aprobar'])
        ->middleware('permiso:creator.approve')->whereUuid('uuid')->name('solicitudes.aprobar');
    Route::post('/solicitudes/{uuid}/rechazar', [SolicitudesController::class, 'rechazar'])
        ->middleware('permiso:creator.approve')->whereUuid('uuid')->name('solicitudes.rechazar');

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

    // ---- La puerta de activación (iteración 3.5, BR-CREATOR-006) ----------
    //
    // Dos permisos distintos y no uno. Registrar evidencia (cotejar un DNI,
    // archivar el correo donde el creador aceptó los términos) es trabajo de
    // reclutamiento; activar es la decisión que le abre las campañas y los
    // pagos. Que hoy los tengan los mismos roles (DEC-060) no es motivo para
    // fundirlos: separarlos después obliga a repasar cada ruta, y separarlos
    // ahora no cuesta nada.
    Route::get('/creadores/{uuid}/activacion', [ActivacionController::class, 'show'])
        ->middleware('permiso:creator.activate,creator.verify')
        ->whereUuid('uuid')
        ->name('creadores.activacion');

    Route::post('/creadores/{uuid}/identidad', [ActivacionController::class, 'verificarIdentidad'])
        ->middleware('permiso:creator.verify')
        ->whereUuid('uuid')
        ->name('creadores.identidad');

    Route::post('/creadores/{uuid}/terminos', [ActivacionController::class, 'registrarTerminos'])
        ->middleware('permiso:creator.verify')
        ->whereUuid('uuid')
        ->name('creadores.terminos');

    Route::post('/creadores/{uuid}/activar', [ActivacionController::class, 'activar'])
        ->middleware('permiso:creator.activate')
        ->whereUuid('uuid')
        ->name('creadores.activar');

    // ---- Perfil tributario (iteración 3.6, BR-CREATOR-013) ----------------
    //
    // Ver exige `creator.view_sensitive` (DEC-053: son datos fiscales).
    // Capturar y aprobar son permisos distintos, y además `ck_ctp_segregation`
    // obliga a que sean dos PERSONAS distintas, no solo dos permisos.
    Route::get('/creadores/{uuid}/fiscal', [PerfilFiscalController::class, 'index'])
        ->middleware('permiso:creator.view_sensitive')
        ->whereUuid('uuid')
        ->name('creadores.fiscal');

    Route::post('/creadores/{uuid}/fiscal', [PerfilFiscalController::class, 'store'])
        ->middleware('permiso:creator.tax.manage')
        ->whereUuid('uuid')
        ->name('creadores.fiscal.store');

    Route::post('/creadores/{uuid}/fiscal/{id}/aprobar', [PerfilFiscalController::class, 'aprobar'])
        ->middleware('permiso:creator.tax.approve')
        ->whereUuid('uuid')->whereNumber('id')
        ->name('creadores.fiscal.aprobar');

    Route::post('/creadores/{uuid}/fiscal/{id}/rechazar', [PerfilFiscalController::class, 'rechazar'])
        ->middleware('permiso:creator.tax.approve')
        ->whereUuid('uuid')->whereNumber('id')
        ->name('creadores.fiscal.rechazar');

    // ---- Cuentas sociales (iteración 3.7, BR-CREATOR-003/004/005) --------
    //
    // Dar de alta una cuenta es `creator.manage`; verificarla es
    // `creator.verify`, el mismo permiso con el que se coteja un documento de
    // identidad, porque es el mismo tipo de acto: alguien se hace responsable
    // de que lo que dice el creador es cierto.
    Route::get('/creadores/{uuid}/redes', [RedesSocialesController::class, 'index'])
        ->middleware('permiso:creator.view')
        ->whereUuid('uuid')
        ->name('creadores.redes');

    Route::post('/creadores/{uuid}/redes', [RedesSocialesController::class, 'store'])
        ->middleware('permiso:creator.manage')
        ->whereUuid('uuid')
        ->name('creadores.redes.store');

    Route::post('/creadores/{uuid}/redes/{id}/verificar', [RedesSocialesController::class, 'verificar'])
        ->middleware('permiso:creator.verify')
        ->whereUuid('uuid')->whereNumber('id')
        ->name('creadores.redes.verificar');

    Route::post('/creadores/{uuid}/redes/{id}/metrica', [RedesSocialesController::class, 'registrarMetrica'])
        ->middleware('permiso:creator.manage')
        ->whereUuid('uuid')->whereNumber('id')
        ->name('creadores.redes.metrica');

    // ---- Medios de pago (iteración 3.8, BR-FIN-003/006/008) --------------
    //
    // Ver exige `creator.view_sensitive`, igual que el perfil fiscal
    // (`DEC-053`). Capturar y verificar son permisos distintos y además
    // `ck_cpm_segregation` exige dos PERSONAS distintas (`H-11`): aquí se
    // decide a dónde va el dinero.
    //
    // No hay ruta de edición. La cuenta es inmutable (`DEC-066`): cambiar de
    // cuenta es dar de alta otra y retirar la anterior.
    Route::get('/creadores/{uuid}/pagos', [MediosPagoController::class, 'index'])
        ->middleware('permiso:creator.view_sensitive')
        ->whereUuid('uuid')
        ->name('creadores.pagos');

    Route::post('/creadores/{uuid}/pagos', [MediosPagoController::class, 'store'])
        ->middleware('permiso:creator.payment.manage')
        ->whereUuid('uuid')
        ->name('creadores.pagos.store');

    Route::post('/creadores/{uuid}/pagos/{id}/verificar', [MediosPagoController::class, 'verificar'])
        ->middleware('permiso:creator.payment.verify')
        ->whereUuid('uuid')->whereNumber('id')
        ->name('creadores.pagos.verificar');

    Route::post('/creadores/{uuid}/pagos/{id}/retirar', [MediosPagoController::class, 'retirar'])
        ->middleware('permiso:creator.payment.verify')
        ->whereUuid('uuid')->whereNumber('id')
        ->name('creadores.pagos.retirar');

    Route::post('/creadores/{uuid}/pagos/{id}/predeterminado', [MediosPagoController::class, 'predeterminado'])
        ->middleware('permiso:creator.payment.manage')
        ->whereUuid('uuid')->whereNumber('id')
        ->name('creadores.pagos.predeterminado');

    Route::post('/creadores/{uuid}/pagos/{id}/compartida', [MediosPagoController::class, 'revisarCompartida'])
        ->middleware('permiso:creator.payment.verify')
        ->whereUuid('uuid')->whereNumber('id')
        ->name('creadores.pagos.compartida');

    // ---- Perfil comercial (iteración 3.9) --------------------------------
    //
    // Cuánto cuesta el creador y cuándo puede trabajar: lo que hace falta para
    // invitarlo a una campaña. Detrás de `creator.rate.manage` (`DEC-069`), un
    // permiso propio que NO abre sus datos fiscales ni su cuenta bancaria.
    //
    // Ver basta con `creator.view`: la tarifa es el costo del creador, no el
    // margen, y el margen sigue reservado a `campaign.view_margin`
    // (`BR-FIN-007`).
    Route::get('/creadores/{uuid}/comercial', [PerfilComercialController::class, 'index'])
        ->middleware('permiso:creator.view')
        ->whereUuid('uuid')
        ->name('creadores.comercial');

    Route::post('/creadores/{uuid}/comercial/tarifa', [PerfilComercialController::class, 'guardarTarifa'])
        ->middleware('permiso:creator.rate.manage')
        ->whereUuid('uuid')
        ->name('creadores.comercial.tarifa');

    Route::post('/creadores/{uuid}/comercial/disponibilidad', [PerfilComercialController::class, 'guardarDisponibilidad'])
        ->middleware('permiso:creator.rate.manage')
        ->whereUuid('uuid')
        ->name('creadores.comercial.disponibilidad');

    Route::post('/creadores/{uuid}/comercial/bloqueo', [PerfilComercialController::class, 'guardarBloqueo'])
        ->middleware('permiso:creator.rate.manage')
        ->whereUuid('uuid')
        ->name('creadores.comercial.bloqueo');

    Route::delete('/creadores/{uuid}/comercial/bloqueo/{id}', [PerfilComercialController::class, 'eliminarBloqueo'])
        ->middleware('permiso:creator.rate.manage')
        ->whereUuid('uuid')->whereNumber('id')
        ->name('creadores.comercial.bloqueo.eliminar');
});
