<?php

declare(strict_types=1);

namespace App\Modules\Finance\Emision;

/**
 * Con qué se habla con la administración (9.9e).
 *
 * `usuario` y `clave` llegan **en claro** porque autenticarse las exige. Viven
 * en una variable el tiempo de una llamada y no se escriben en ningún sitio: ni
 * en la bitácora, ni en el registro de intentos, ni en el log (`BR-SEC-001`).
 * Lo que sí queda escrito es **con qué conexión** se habló, por su nombre.
 */
final readonly class CredencialesDeEnvio
{
    public function __construct(
        public string $url,
        /**
         * El identificador fiscal del emisor. Va aparte del usuario porque
         * **cada administración lo combina a su manera**: SUNAT autentica con
         * `RUC` + usuario secundario pegados, y eso es una rareza suya, no una
         * forma general. Se manda por separado y que lo junte quien sabe cómo.
         */
        public string $identificadorEmisor,
        public string $usuario,
        public string $clave,
        public string $entorno,
        public string $conexion,
    ) {}
}
