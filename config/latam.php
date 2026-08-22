<?php

declare(strict_types=1);

/*
 * Configuración propia de LATAM Social.
 *
 * Este archivo existe por una razón concreta: `env()` SOLO funciona dentro de
 * `config/`. En cualquier otro sitio devuelve null en cuanto alguien ejecuta
 * `php artisan config:cache` — que es lo normal en producción, porque Laravel
 * deja de leer el `.env`.
 *
 * El seeder del administrador leía `env('ADMIN_PASSWORD')` directamente. Con la
 * configuración cacheada eso devuelve null SIEMPRE, así que ignoraba en silencio
 * la contraseña que el operador hubiera puesto y generaba una al azar. No falla,
 * no avisa: simplemente hace otra cosa. Lo detectó PHPStan, no una prueba.
 */

return [
    'admin' => [
        'nombre' => env('ADMIN_NAME', 'Administrador'),
        'correo' => env('ADMIN_EMAIL', 'admin@portalcts.com'),
        // Sin valor por defecto a propósito: si no está, el seeder genera una
        // al azar y la imprime una vez. Una contraseña por defecto en el
        // repositorio termina siempre en producción.
        'clave' => env('ADMIN_PASSWORD'),
    ],
];
