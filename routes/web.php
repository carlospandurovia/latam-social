<?php

declare(strict_types=1);

use App\Modules\Campaign\Http\Controllers\CampanasController;
use App\Modules\Campaign\Http\Controllers\CandidatosController;
use App\Modules\Campaign\Http\Controllers\InvitacionController;
use App\Modules\Campaign\Http\Controllers\SeguimientoController;
use App\Modules\Client\Http\Controllers\ClientesController;
use App\Modules\Client\Http\Controllers\ContactosController;
use App\Modules\Client\Http\Controllers\MarcasController;
use App\Modules\Client\Http\Controllers\PerfilesFiscalesController;
use App\Modules\Communication\Http\Controllers\CorreosController;
use App\Modules\Content\Http\Controllers\EntregablesController;
use App\Modules\Content\Http\Controllers\MisEntregasController;
use App\Modules\Content\Http\Controllers\RevisionController;
use App\Modules\Core\Http\Controllers\BitacoraController;
use App\Modules\Core\Http\Controllers\CatalogosController;
use App\Modules\Core\Http\Controllers\EntidadesLegalesController;
use App\Modules\Core\Http\Controllers\PanelController;
use App\Modules\Creator\Http\Controllers\ActivacionController;
use App\Modules\Creator\Http\Controllers\CreadoresController;
use App\Modules\Creator\Http\Controllers\MediosPagoController;
use App\Modules\Creator\Http\Controllers\PerfilComercialController;
use App\Modules\Creator\Http\Controllers\PerfilFiscalController;
use App\Modules\Creator\Http\Controllers\RedesSocialesController;
use App\Modules\Creator\Http\Controllers\SolicitudesController;
use App\Modules\Identity\Http\Controllers\AccesoController;
use App\Modules\Identity\Http\Controllers\PasswordController;
use App\Modules\Identity\Http\Controllers\RecuperacionController;
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

// ---- Enlaces de contrasena (`4.1`, y la otra mitad de `5.9`) ---------------
//
// FUERA del grupo `guest` a proposito. Un enlace de alta o de recuperacion tiene
// que funcionar tambien para quien ya tiene una sesion abierta --el caso tipico
// es la cuenta compartida de un ordenador prestado--, y `guest` lo mandaria al
// panel sin decirle por que.
//
// El limite por IP es la primera barrera; `RecuperacionController` pone ademas
// uno por correo, que es el que impide inundar un buzon concreto desde IPs
// distintas.
Route::get('/recuperar', [RecuperacionController::class, 'pedir'])->name('recuperar');
Route::post('/recuperar', [RecuperacionController::class, 'enviar'])
    ->middleware('throttle:5,1')
    ->name('recuperar.enviar');

// La ruta que lleva el token. Lo unico que hace es guardarlo en la sesion y
// redirigir a `recuperar.formulario`, que ya no lo lleva: ver la cabecera del
// controlador.
Route::get('/contrasena/nueva/{token}', [RecuperacionController::class, 'usar'])
    ->middleware('throttle:20,1')
    ->where('token', '[a-f0-9]{64}')
    ->name('recuperar.usar');
Route::get('/contrasena/nueva', [RecuperacionController::class, 'formulario'])
    ->name('recuperar.formulario');
Route::post('/contrasena/nueva', [RecuperacionController::class, 'fijar'])
    ->middleware('throttle:10,1')
    ->name('recuperar.fijar');

// ---- La invitacion del creador (`7.6`) --------------------------------------
//
// La primera parte del sistema hecha para alguien de FUERA. Sin `auth` y sin
// `guest`: el creador no necesita entrar --su portal (`F6`) esta bloqueado por
// `T-09`-- y la autorizacion es el token, que vale una sola vez.
//
// Mismo tratamiento que el enlace de contrasena: la ruta que lleva el token lo
// guarda en la sesion y redirige a una URL limpia (`DEC-117`).
Route::get('/invitacion/{token}', [InvitacionController::class, 'ver'])
    ->middleware('throttle:20,1')
    ->where('token', '[a-f0-9]{64}')
    ->name('invitacion.ver');
Route::get('/invitacion', [InvitacionController::class, 'oferta'])->name('invitacion.oferta');
Route::post('/invitacion/aceptar', [InvitacionController::class, 'aceptar'])
    ->middleware('throttle:10,1')
    ->name('invitacion.aceptar');
Route::post('/invitacion/rechazar', [InvitacionController::class, 'rechazar'])
    ->middleware('throttle:10,1')
    ->name('invitacion.rechazar');
// `T-38`: preguntar no es contestar --la invitacion sigue viva-- y por eso es
// una ruta aparte y no un tercer boton del mismo formulario.
Route::post('/invitacion/pregunta', [InvitacionController::class, 'preguntar'])
    ->middleware('throttle:5,1')
    ->name('invitacion.preguntar');
Route::get('/invitacion/estado/gracias', [InvitacionController::class, 'gracias'])
    ->name('invitacion.gracias');
Route::get('/invitacion/estado/no-disponible', [InvitacionController::class, 'caducada'])
    ->name('invitacion.caducada');

Route::middleware('auth')->group(function (): void {
    Route::post('/salir', [AccesoController::class, 'salir'])->name('salir');

    Route::get('/panel', PanelController::class)->name('panel');

    // ---- La propia contrasena (T-23) -------------------------------------
    //
    // SIN `permiso:`, y es deliberado: si cambiar la propia contrasena
    // dependiera de un permiso, un usuario al que se le han revocado los
    // permisos no podria cambiarla — y ese es justo al que mas urge.
    //
    // `ExigirCambioDePassword` deja pasar estas dos y `salir`; cualquier otra
    // ruta redirige aqui mientras `must_change_password` este puesto.
    Route::get('/contrasena', [PasswordController::class, 'formulario'])->name('contrasena');
    Route::put('/contrasena', [PasswordController::class, 'cambiar'])->name('contrasena.cambiar');

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

    // 3.11 / T-15: anular NO es rechazar ni reemplazar, y por eso no comparte
    // permiso con ninguno de los dos. Rechazar para a un perfil antes de que
    // aplique; anular deshace uno que ya aplicaba, y eso reescribe el historico
    // del que sale la retencion practicada.
    Route::post('/creadores/{uuid}/fiscal/{id}/anular', [PerfilFiscalController::class, 'anular'])
        ->middleware('permiso:creator.tax.annul')
        ->whereUuid('uuid')->whereNumber('id')
        ->name('creadores.fiscal.anular');

    // ---- Entidades legales (iteración 4.5, hoja de ruta 4.5b) ------------
    //
    // La pantalla a la que `BR-LE-004` lleva mandando desde 4.1 y el aviso de
    // perfiles fiscales desde 4.4. Hasta ahora no existia (`Q-51`).
    //
    // `legal_entity.manage` es de `admin` y de nadie mas: dar de alta una
    // sociedad es constituir una empresa en el sistema.
    Route::get('/entidades', [EntidadesLegalesController::class, 'index'])
        ->middleware('permiso:legal_entity.manage')
        ->name('entidades.index');

    Route::get('/entidades/nueva', [EntidadesLegalesController::class, 'create'])
        ->middleware('permiso:legal_entity.manage')
        ->name('entidades.create');

    Route::post('/entidades', [EntidadesLegalesController::class, 'store'])
        ->middleware('permiso:legal_entity.manage')
        ->name('entidades.store');

    Route::get('/entidades/{uuid}', [EntidadesLegalesController::class, 'show'])
        ->middleware('permiso:legal_entity.manage')
        ->whereUuid('uuid')
        ->name('entidades.show');

    Route::get('/entidades/{uuid}/editar', [EntidadesLegalesController::class, 'edit'])
        ->middleware('permiso:legal_entity.manage')
        ->whereUuid('uuid')
        ->name('entidades.edit');

    Route::put('/entidades/{uuid}', [EntidadesLegalesController::class, 'update'])
        ->middleware('permiso:legal_entity.manage')
        ->whereUuid('uuid')
        ->name('entidades.update');

    Route::post('/entidades/{uuid}/cobertura', [EntidadesLegalesController::class, 'abrirCobertura'])
        ->middleware('permiso:legal_entity.manage')
        ->whereUuid('uuid')
        ->name('entidades.cobertura');

    // Dar de baja CIERRA las coberturas abiertas (`DEC-081`). Sin eso, los
    // paises que cubria quedan sin cubrir y sin poder cubrirse.
    Route::post('/entidades/{uuid}/baja', [EntidadesLegalesController::class, 'desactivar'])
        ->middleware('permiso:legal_entity.manage')
        ->whereUuid('uuid')
        ->name('entidades.baja');

    // ---- Clientes (iteración 4.1, hoja de ruta 7.0) ----------------------
    //
    // `client.view` para mirar, `client.manage` para tocar. Hasta ahora
    // `client.manage` estaba declarado y no lo tenia NINGUN rol: el permiso
    // existia y nadie podia crear un cliente.
    Route::get('/clientes', [ClientesController::class, 'index'])
        ->middleware('permiso:client.view')
        ->name('clientes.index');

    Route::get('/clientes/nuevo', [ClientesController::class, 'create'])
        ->middleware('permiso:client.manage')
        ->name('clientes.create');

    Route::post('/clientes', [ClientesController::class, 'store'])
        ->middleware('permiso:client.manage')
        ->name('clientes.store');

    Route::get('/clientes/{uuid}', [ClientesController::class, 'show'])
        ->middleware('permiso:client.view')
        ->whereUuid('uuid')
        ->name('clientes.show');

    Route::get('/clientes/{uuid}/editar', [ClientesController::class, 'edit'])
        ->middleware('permiso:client.manage')
        ->whereUuid('uuid')
        ->name('clientes.edit');

    Route::put('/clientes/{uuid}', [ClientesController::class, 'update'])
        ->middleware('permiso:client.manage')
        ->whereUuid('uuid')
        ->name('clientes.update');

    // ---- Marcas del cliente (iteración 4.2) ------------------------------
    //
    // Cuelgan del cliente en la URL y en la comprobacion: `MarcasController`
    // exige que la marca sea DE ESE cliente, no solo que exista.
    Route::get('/clientes/{uuid}/marcas/nueva', [MarcasController::class, 'create'])
        ->middleware('permiso:client.manage')
        ->whereUuid('uuid')
        ->name('marcas.create');

    Route::post('/clientes/{uuid}/marcas', [MarcasController::class, 'store'])
        ->middleware('permiso:client.manage')
        ->whereUuid('uuid')
        ->name('marcas.store');

    Route::get('/clientes/{uuid}/marcas/{marca}/editar', [MarcasController::class, 'edit'])
        ->middleware('permiso:client.manage')
        ->whereUuid('uuid')->whereUuid('marca')
        ->name('marcas.edit');

    Route::put('/clientes/{uuid}/marcas/{marca}', [MarcasController::class, 'update'])
        ->middleware('permiso:client.manage')
        ->whereUuid('uuid')->whereUuid('marca')
        ->name('marcas.update');

    // ---- Identidad fiscal del cliente (iteración 4.4) --------------------
    //
    // Permiso PROPIO, no `client.manage`: de estos campos salen la razon social
    // y el documento que se imprimen en la factura. El identificador de la ruta
    // es el id numerico porque `client_tax_profiles` no tiene `uuid`; el
    // controlador exige ademas que el perfil sea de ESE cliente y que este
    // vigente, asi que la ruta no sirve para llegar al de otro ni a uno cerrado.
    Route::get('/clientes/{uuid}/fiscal/nuevo', [PerfilesFiscalesController::class, 'create'])
        ->middleware('permiso:client.tax.manage')
        ->whereUuid('uuid')
        ->name('clientes.fiscal.create');

    Route::post('/clientes/{uuid}/fiscal', [PerfilesFiscalesController::class, 'store'])
        ->middleware('permiso:client.tax.manage')
        ->whereUuid('uuid')
        ->name('clientes.fiscal.store');

    Route::get('/clientes/{uuid}/fiscal/{perfil}/corregir', [PerfilesFiscalesController::class, 'edit'])
        ->middleware('permiso:client.tax.manage')
        ->whereUuid('uuid')->whereNumber('perfil')
        ->name('clientes.fiscal.edit');

    Route::put('/clientes/{uuid}/fiscal/{perfil}', [PerfilesFiscalesController::class, 'update'])
        ->middleware('permiso:client.tax.manage')
        ->whereUuid('uuid')->whereNumber('perfil')
        ->name('clientes.fiscal.update');

    // ---- Contactos del cliente (iteración 4.3) ---------------------------
    //
    // `uq_contacts_primary` deja un principal activo por cliente y tipo. El
    // relevo es automatico (DEC-075) y lo hace `Contactos`, no la ruta.
    Route::get('/clientes/{uuid}/contactos/nuevo', [ContactosController::class, 'create'])
        ->middleware('permiso:client.manage')
        ->whereUuid('uuid')
        ->name('contactos.create');

    Route::post('/clientes/{uuid}/contactos', [ContactosController::class, 'store'])
        ->middleware('permiso:client.manage')
        ->whereUuid('uuid')
        ->name('contactos.store');

    Route::get('/clientes/{uuid}/contactos/{contacto}/editar', [ContactosController::class, 'edit'])
        ->middleware('permiso:client.manage')
        ->whereUuid('uuid')->whereUuid('contacto')
        ->name('contactos.edit');

    Route::put('/clientes/{uuid}/contactos/{contacto}', [ContactosController::class, 'update'])
        ->middleware('permiso:client.manage')
        ->whereUuid('uuid')->whereUuid('contacto')
        ->name('contactos.update');

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
    // ---------------------------------------------------------------- Campañas
    //
    // `transicionar` es UNA ruta para las ocho transiciones, y su permiso no
    // está aquí: lo dice `EstadosDeCampana`. Aprobar exige `campaign.approve` y
    // el resto `campaign.manage`, así que fijar el permiso en la ruta obligaría
    // a partirla en dos y a acordarse de las dos al añadir un estado. Lo que sí
    // exige la ruta es poder gestionar campañas; el grafo afina desde ahí.
    Route::get('/campanas', [CampanasController::class, 'index'])
        ->middleware('permiso:campaign.view')
        ->name('campanas.index');

    Route::get('/campanas/nueva', [CampanasController::class, 'create'])
        ->middleware('permiso:campaign.manage')
        ->name('campanas.create');

    Route::post('/campanas', [CampanasController::class, 'store'])
        ->middleware('permiso:campaign.manage')
        ->name('campanas.store');

    Route::get('/campanas/{uuid}', [CampanasController::class, 'show'])
        ->middleware('permiso:campaign.view')
        ->whereUuid('uuid')
        ->name('campanas.show');

    Route::get('/campanas/{uuid}/editar', [CampanasController::class, 'edit'])
        ->middleware('permiso:campaign.manage')
        ->whereUuid('uuid')
        ->name('campanas.edit');

    Route::put('/campanas/{uuid}', [CampanasController::class, 'update'])
        ->middleware('permiso:campaign.manage')
        ->whereUuid('uuid')
        ->name('campanas.update');

    // El brief. `campaign.manage` y no `campaign.approve`: montar el brief es
    // trabajo de quien arma la campana; aprobarlo es lo que firma finanzas.
    Route::post('/campanas/{uuid}/requisitos', [CampanasController::class, 'anadirRequisito'])
        ->middleware('permiso:campaign.manage')
        ->whereUuid('uuid')
        ->name('campanas.requisitos.anadir');

    Route::delete('/campanas/{uuid}/requisitos/{requisito}', [CampanasController::class, 'quitarRequisito'])
        ->middleware('permiso:campaign.manage')
        ->whereUuid('uuid')->whereNumber('requisito')
        ->name('campanas.requisitos.quitar');

    // Los mercados (7.3). `anadirMercado` NO exige que la campana sea borrador:
    // ampliar a un pais nuevo se puede con la campana viva; quitarlo no, y eso
    // lo veta el servicio y lo impide `tg_cm_no_quitar_confirmada` en la base.
    Route::post('/campanas/{uuid}/mercados', [CampanasController::class, 'anadirMercado'])
        ->middleware('permiso:campaign.manage')
        ->whereUuid('uuid')
        ->name('campanas.mercados.anadir');

    Route::delete('/campanas/{uuid}/mercados/{mercado}', [CampanasController::class, 'quitarMercado'])
        ->middleware('permiso:campaign.manage')
        ->whereUuid('uuid')->whereNumber('mercado')
        ->name('campanas.mercados.quitar');

    // Ver el brief efectivo de un mercado es VER, no gestionar: es lo que un
    // revisor de contenido necesita mirar para saber que se pidio en ese pais.
    Route::get('/campanas/{uuid}/mercados/{mercado}', [CampanasController::class, 'mercado'])
        ->middleware('permiso:campaign.view')
        ->whereUuid('uuid')->whereNumber('mercado')
        ->name('campanas.mercados.ver');

    // ---- El portal del creador (`8.1`) ----------------------------------
    //
    // `creator.portal` es el primer permiso de ambito EXTERNO. Dice «esta
    // persona puede ver UN portal de creador»; **cual** lo decide
    // `creators.user_id = Auth::id()`, comprobado en cada accion. Sin eso,
    // cualquier creador con el permiso podria entregar en nombre de otro.
    Route::get('/mis-entregas', [MisEntregasController::class, 'index'])
        ->middleware('permiso:creator.portal')
        ->name('entregas.mias');
    Route::post('/mis-entregas/{uuid}', [MisEntregasController::class, 'entregar'])
        ->middleware(['permiso:creator.portal', 'throttle:20,1'])
        ->whereUuid('uuid')
        ->name('entregas.entregar');

    // 8.6: el creador pega el enlace de su post. Es quien lo sabe primero y quien
    // lo tiene en la mano; el equipo puede hacerlo por el desde la pantalla
    // interna, y en los dos casos queda quien lo reporto.
    Route::post('/mis-entregas/{uuid}/publicado', [MisEntregasController::class, 'publicar'])
        ->middleware(['permiso:creator.portal', 'throttle:20,1'])
        ->whereUuid('uuid')
        ->name('entregas.publicar');

    // 7.7: el panel de seguimiento. Es VER: contesta «como va», no cambia nada.
    // La ficha (`campanas.show`) contesta «que es»; mezclarlas daria una pagina
    // que hace las dos cosas a medias y en la que hay que buscar.
    Route::get('/campanas/{uuid}/seguimiento', SeguimientoController::class)
        ->middleware('permiso:campaign.view')
        ->whereUuid('uuid')
        ->name('campanas.seguimiento');

    // 8.1: los entregables de una campana, por dentro. Pantalla de CONTENT y no
    // del panel de seguimiento, y no por organizacion: `deptrac.yaml` dice
    // `Campaign: [..., Creator, Client]` y Content no esta en la lista, asi que
    // el panel de 7.7 no puede ni contarlos.
    Route::get('/campanas/{uuid}/entregables', [EntregablesController::class, 'index'])
        ->middleware('permiso:content.deliverable.view')
        ->whereUuid('uuid')
        ->name('campanas.entregables');

    // 8.6: y el equipo, por el creador. Mismo servicio y mismo veto que su
    // portal: solo cambia quien firma la fila.
    Route::post('/campanas/{uuid}/entregables/{entregable}/publicado', [EntregablesController::class, 'publicar'])
        ->middleware(['permiso:content.publication.manage', 'throttle:30,1'])
        ->whereUuid('uuid')->whereNumber('entregable')
        ->name('campanas.entregables.publicar');

    // La salida de emergencia cuando el evento que los crea fallo.
    Route::post('/campanas/{uuid}/entregables/{participacion}', [EntregablesController::class, 'generar'])
        ->middleware('permiso:content.deliverable.view')
        ->whereUuid('uuid')->whereNumber('participacion')
        ->name('campanas.entregables.generar');

    // 8.3: la cola de revision. BANDEJA GLOBAL y no una por campana: revisar es
    // trabajo por lotes --alguien se sienta y despacha lo que llego--, y una
    // cola por campana obliga a recorrer campanas para descubrir si hay algo
    // esperando. Lo que se descubre recorriendo se descubre tarde.
    Route::get('/revision', [RevisionController::class, 'index'])
        ->middleware('permiso:content.review')
        ->name('revision.cola');
    Route::get('/revision/{uuid}', [RevisionController::class, 'ver'])
        ->middleware('permiso:content.review')
        ->whereUuid('uuid')
        ->name('revision.ver');
    // El veredicto entra por `content.review`; APROBAR y AUTORIZAR una ronda de
    // mas se comprueban dentro, porque los tres llegan por el mismo POST y la
    // ruta solo sabe decir «puede entrar a revisar».
    Route::post('/revision/{uuid}', [RevisionController::class, 'revisar'])
        ->middleware(['permiso:content.review', 'throttle:30,1'])
        ->whereUuid('uuid')
        ->name('revision.revisar');

    // 8.2: reabrir. Accion propia y no una rama del veredicto: no es una opinion
    // sobre el contenido, es volver atras sobre una decision ya tomada. Entra por
    // `content.review` y el permiso de verdad --`content.reopen`-- se comprueba
    // dentro, como los otros tres de 8.3.
    Route::post('/revision/{uuid}/reabrir', [RevisionController::class, 'reabrir'])
        ->middleware(['permiso:content.review', 'throttle:30,1'])
        ->whereUuid('uuid')
        ->name('revision.reabrir');

    // Los candidatos (7.4). Buscar es VER --un revisor puede mirar a quien se
    // esta considerando-- pero armar la lista corta es gestionar.
    Route::get('/campanas/{uuid}/candidatos', [CandidatosController::class, 'index'])
        ->middleware('permiso:campaign.view')
        ->whereUuid('uuid')
        ->name('campanas.candidatos');

    Route::post('/campanas/{uuid}/candidatos', [CandidatosController::class, 'anadir'])
        ->middleware('permiso:campaign.manage')
        ->whereUuid('uuid')
        ->name('campanas.candidatos.anadir');

    Route::delete('/campanas/{uuid}/candidatos/{participacion}', [CandidatosController::class, 'quitar'])
        ->middleware('permiso:campaign.manage')
        ->whereUuid('uuid')->whereNumber('participacion')
        ->name('campanas.candidatos.quitar');

    // El compromiso economico (7.5). Poner el monto es gestionar; AUTORIZAR el
    // sobrecosto es de finanzas, misma separacion que aprobar la campana.
    Route::post('/campanas/{uuid}/candidatos/{participacion}/monto', [CandidatosController::class, 'comprometer'])
        ->middleware('permiso:campaign.manage')
        ->whereUuid('uuid')->whereNumber('participacion')
        ->name('campanas.candidatos.monto');

    // 7.6: invitar tiene permiso propio. Editar una campana es trabajo interno;
    // invitar es el momento en que un compromiso economico sale de la empresa y
    // llega a una persona.
    Route::post('/campanas/{uuid}/candidatos/{participacion}/invitar', [CandidatosController::class, 'invitar'])
        ->middleware('permiso:campaign.invite')
        ->whereUuid('uuid')->whereNumber('participacion')
        ->name('campanas.candidatos.invitar');

    Route::post('/campanas/{uuid}/candidatos/{participacion}/anular-invitacion', [CandidatosController::class, 'anularInvitacion'])
        ->middleware('permiso:campaign.invite')
        ->whereUuid('uuid')->whereNumber('participacion')
        ->name('campanas.candidatos.anular');

    // `T-38`: hacerse cargo de una pregunta no es invitar ni gestionar la
    // campana: es atender a alguien. Va con `campaign.invite`, que es quien
    // tiene la conversacion abierta con ese creador.
    Route::post('/campanas/{uuid}/candidatos/{participacion}/preguntas/{pregunta}', [CandidatosController::class, 'marcarPreguntaVista'])
        ->middleware('permiso:campaign.invite')
        ->whereUuid('uuid')->whereNumber('participacion')->whereNumber('pregunta')
        ->name('campanas.candidatos.pregunta');

    Route::post('/campanas/{uuid}/sobrecosto', [CandidatosController::class, 'autorizarSobrecosto'])
        ->middleware('permiso:campaign.approve')
        ->whereUuid('uuid')
        ->name('campanas.sobrecosto');

    // El correo (4.9). Solo lectura: publicar plantillas es por comando, porque
    // el texto de un aviso que le llega a 150 personas se revisa y se versiona
    // en el repositorio como cualquier otro texto legal.
    Route::get('/correos', [CorreosController::class, 'index'])
        ->middleware('permiso:comms.view')
        ->name('correos.index');

    Route::get('/correos/plantillas', [CorreosController::class, 'plantillas'])
        ->middleware('permiso:comms.view')
        ->name('correos.plantillas');

    Route::post('/campanas/{uuid}/estado', [CampanasController::class, 'transicionar'])
        ->middleware('permiso:campaign.view')
        ->whereUuid('uuid')
        ->name('campanas.estado');
});
