<?php

declare(strict_types=1);

namespace App\Modules\Finance\Emision;

/**
 * El XML firmado, con el nombre que exige el país (9.9d).
 *
 * El **nombre importa**: SUNAT lo usa para identificar el documento dentro del
 * ZIP que se le manda, y un nombre distinto del contenido se rechaza sin
 * explicar por qué. Lo decide el adaptador, que es quien conoce esa regla.
 */
final readonly class DocumentoArmado
{
    public function __construct(
        public string $nombre,
        public string $xml,
        public string $huella,
    ) {}
}
