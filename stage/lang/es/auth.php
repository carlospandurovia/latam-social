<?php

declare(strict_types=1);

/**
 * Los mensajes de acceso (L-7).
 *
 * Van aquí por lo mismo que `validation.php`: al crear `lang/` en la `L-6`, el
 * traductor dejó de usar las traducciones internas de Laravel y estas claves
 * empezaron a pintarse en crudo —`auth.failed` en la pantalla de acceso—.
 *
 * `failed` no dice **cuál** de las dos cosas está mal, y es a propósito: decir
 * «ese correo no existe» le confirma a quien prueba correos cuáles están dados
 * de alta.
 */
return [
    'failed' => 'El correo o la contraseña no son correctos.',
    'password' => 'La contraseña no es correcta.',
    'throttle' => 'Demasiados intentos. Vuelve a probar en :seconds segundos.',
];
