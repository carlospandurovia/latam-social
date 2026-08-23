<?php

declare(strict_types=1);

namespace App\Modules\Creator\Services;

/**
 * Una de las condiciones de `BR-CREATOR-006`, ya evaluada.
 *
 * Es un objeto y no un array asociativo por una razón práctica: la vista y las
 * pruebas leen `$r->cumple`, y si mañana se añade una séptima condición no hay
 * que ir buscando qué claves esperaba cada sitio.
 *
 * - `codigo`  identificador estable, para las pruebas y para la bitácora.
 * - `titulo`  lo que ve el operador en la lista.
 * - `cumple`  si esta condición está satisfecha ahora mismo.
 * - `detalle` qué falta exactamente, o con qué se cumplió. Es lo que evita que
 *             el operador tenga que adivinar qué pedirle al creador.
 * - `regla`   la regla de negocio que lo exige, para que la pantalla la cite.
 */
final class Requisito
{
    public function __construct(
        public readonly string $codigo,
        public readonly string $titulo,
        public readonly bool $cumple,
        public readonly string $detalle,
        public readonly string $regla,
    ) {}
}
