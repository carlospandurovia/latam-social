<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Qué instalación es ésta (9.22a, `DEC-029`)
|--------------------------------------------------------------------------
|
| Éste es, a propósito, el ÚNICO ajuste del sistema que sigue viviendo en la
| máquina y no en la base de datos, y contradice a `DEC-190` por una razón que
| conviene leer entera antes de «arreglarlo»:
|
|   `DEC-190` saca la configuración de la máquina para poder cambiarla sin
|   entrar por SSH. Aquí lo que se guarda no es un ajuste: es la IDENTIDAD de
|   la máquina. Y la identidad de la máquina es justamente lo que NO puede
|   viajar con una copia de los datos.
|
| Si esto viviera en la base, restaurar un volcado de producción en un servidor
| de pruebas traería consigo «soy producción», y la barrera se abriría sola en
| el único momento en que hace falta que esté cerrada.
|
*/

return [

    /*
    | Qué es esta instalación. Sale de `APP_ENV`, que es lo que ya distingue una
    | máquina de otra y lo que NO viaja en un volcado de la base.
    */
    'entorno' => env('APP_ENV', 'production'),

    /*
    | La anulación temporal de `DEC-029`: dejar que una instalación que no es
    | producción use conexiones de producción.
    |
    | Existe porque una prueba de humo contra el servicio real es legítima una
    | vez al año. Vive AQUÍ y no en el panel por lo mismo que `entorno`: un
    | interruptor en la base viaja con la copia, y entonces no es una barrera.
    |
    | Cada vez que se ejerce queda escrito en `audit_logs`. Lo que TODAVÍA NO
    | tiene —y se dice para que nadie crea lo contrario— es caducidad ni permiso
    | por usuario: hoy quien puede editar el archivo del servidor puede abrirla.
    */
    'permitir_conexiones_de_produccion' => (bool) env('PERMITIR_CONEXIONES_DE_PRODUCCION', false),

];
