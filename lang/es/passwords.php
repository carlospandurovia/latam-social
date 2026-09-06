<?php

declare(strict_types=1);

/**
 * Los mensajes de recuperación de contraseña (L-7).
 *
 * `sent` y `user` dicen **lo mismo** a propósito: si «no existe ese correo»
 * fuera una respuesta distinta de «te hemos escrito», esta pantalla sería una
 * forma de averiguar quién tiene cuenta aquí probando correos.
 */
return [
    'reset' => 'Tu contraseña se ha cambiado.',
    'sent' => 'Si ese correo tiene cuenta, le hemos escrito con el enlace.',
    'throttled' => 'Espera un momento antes de volver a intentarlo.',
    'token' => 'Ese enlace de recuperación ya no vale. Pide uno nuevo.',
    'user' => 'Si ese correo tiene cuenta, le hemos escrito con el enlace.',
];
