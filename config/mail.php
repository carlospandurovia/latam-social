<?php

declare(strict_types=1);

/*
 * Correo saliente (iteración 4.9).
 *
 * `log` como valor por defecto y NO `smtp`: sin credenciales configuradas, un
 * `smtp` por defecto falla en cada envío y llena `email_log` de `failed` que no
 * son culpa de nadie. Con `log` el correo se escribe en el log de Laravel, el
 * registro queda en `sent`, y el flujo se puede probar entero antes de que
 * exista la cuenta de SMTP — que es exactamente la situación de hoy (`Q-20`).
 *
 * En producción se pone `MAIL_MAILER=smtp` y las credenciales en el `.env`.
 * Nunca en el repositorio.
 */

return [
    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => (int) env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => 15,
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-responder@portalcts.com'),
        'name' => env('MAIL_FROM_NAME', 'LATAM Social'),
    ],
];
