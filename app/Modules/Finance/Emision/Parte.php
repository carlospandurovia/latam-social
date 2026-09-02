<?php

declare(strict_types=1);

namespace App\Modules\Finance\Emision;

/**
 * Quien emite o quien recibe, congelado (9.9d).
 *
 * Sale de las **copias congeladas** de la factura (`BR-LE-005`) y no de las
 * tablas vivas: la sociedad cambia de domicilio, y la factura de ayer no. Si
 * esto leyera `legal_entities`, regenerar el XML de una factura de hace un año
 * produciría un documento distinto del que se emitió, y eso ya no es el mismo
 * comprobante.
 *
 * Los campos de localidad —`ubigeo`, `distrito`…— son los únicos que se leen de
 * la tabla viva, porque `9.9b` no los congeló. Queda anotado como `T-87`: la
 * primera vez que una sociedad se mude, el XML regenerado no cuadrará con el
 * emitido.
 */
final readonly class Parte
{
    public function __construct(
        public string $tipoIdentificacion,
        public string $numeroIdentificacion,
        public string $razonSocial,
        public string $direccion,
        public string $paisIso,
        public ?string $nombreComercial = null,
        public ?string $ubigeo = null,
        public ?string $distrito = null,
        public ?string $provincia = null,
        public ?string $departamento = null,
        public ?string $codigoLocal = null,
    ) {}
}
