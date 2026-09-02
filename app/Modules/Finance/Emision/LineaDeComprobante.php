<?php

declare(strict_types=1);

namespace App\Modules\Finance\Emision;

/**
 * Una línea del comprobante (9.9d). Importes en cadena, por lo mismo que
 * `Comprobante`.
 */
final readonly class LineaDeComprobante
{
    public function __construct(
        public int $numero,
        public string $descripcion,
        public string $cantidad,
        public string $precioUnitario,
        public string $subtotal,
        public string $impuesto,
        public string $total,
        public string $tasa,
    ) {}
}
