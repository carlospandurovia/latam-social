<?php

/**
 * index.php para cuando la raíz del dominio NO puede apuntar a public/.
 *
 * La aplicación vive fuera de la raíz web (así el .env, storage/ y .git no son
 * descargables) y en la raíz del dominio queda SÓLO el contenido de public/.
 *
 * Este archivo sustituye a public/index.php DENTRO DE LA RAÍZ DEL DOMINIO.
 * El public/index.php del repositorio NO se toca.
 *
 * Lo único que hay que ajustar es $raiz_app.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// <<< LA ÚNICA LÍNEA QUE CAMBIA >>>
$raiz_app = '/home3/cpanduro/apps/latamsocial';

if (file_exists($mantenimiento = $raiz_app.'/storage/framework/maintenance.php')) {
    require $mantenimiento;
}

require $raiz_app.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $raiz_app.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
