<?php

declare(strict_types=1);

namespace App\Modules\Finance\Emision;

use RuntimeException;

/**
 * La frontera de `DEC-252`.
 *
 * Cuando el negocio eligió emisión propia con Greenter, quedó dicho que
 * **Greenter iría detrás de una frontera**, porque es peruano y esto es para
 * seis países. Esto es la frontera: una interfaz con un método.
 *
 * No es una promesa: `deptrac.yaml` declara una capa `Greenter` que **sólo**
 * `app/Modules/Finance/Emision/Peru/` puede nombrar. Nombrarla desde cualquier
 * otro sitio pone una puerta en rojo.
 */
interface ArmadorDeComprobante
{
    /**
     * Arma el XML del comprobante y lo firma con el certificado dado.
     *
     * @param string $pem El certificado y su clave privada, en PEM. Llega en
     *                    claro porque firmar exige la clave; quien lo pide lo
     *                    saca de `Certificados::material()` y no lo guarda.
     *
     * @throws RuntimeException si el comprobante no se puede armar con lo que trae.
     */
    public function arma(Comprobante $comprobante, string $pem): DocumentoArmado;

    /** Para qué país sirve. ISO de dos letras, en mayúsculas. */
    public function pais(): string;
}
