<?php

declare(strict_types=1);

namespace App\Modules\Finance\Emision;

/**
 * Qué contestó la administración (9.9e).
 *
 * ### Los cinco finales, y por qué son cinco
 *
 * Porque exigen **cinco arreglos distintos**, y en un `catch` genérico se ven
 * iguales. Es la misma lección de `Decolecta` en `9.2`: «no hay credencial»,
 * «la API contestó 500» y «contestó 200 con un cuerpo que no entiendo» no se
 * arreglan igual, así que no se llaman igual.
 *
 * | | Qué pasó | Qué hay que hacer |
 * |---|---|---|
 * | `aceptado` | Entró y es válido | Nada |
 * | `observado` | Entró y es válido, con reparos | Mirarlos: el siguiente puede no entrar |
 * | `rechazado` | **NO existe** para la administración | Corregir el documento y emitir otro |
 * | `error_red` | No se llegó a saber | **Reintentar**, y sólo eso |
 * | `no_configurado` | Falta algo de esta instalación | Configurarlo |
 *
 * La diferencia entre `rechazado` y `error_red` es la que más cara sale
 * confundir: un rechazo **no se reintenta** —el documento es inválido y
 * reenviarlo da el mismo rechazo— y un error de red **sólo se reintenta**,
 * porque el comprobante puede haber entrado y no haberse podido leer la
 * respuesta.
 */
final readonly class RespuestaDeEnvio
{
    public const ACEPTADO = 'aceptado';

    public const OBSERVADO = 'observado';

    public const RECHAZADO = 'rechazado';

    public const ERROR_RED = 'error_red';

    public const NO_CONFIGURADO = 'no_configurado';

    /** @param list<string> $notas */
    public function __construct(
        public string $estado,
        public ?string $codigo,
        public string $descripcion,
        public array $notas = [],
        public ?string $cdr = null,
        public ?string $nombreCdr = null,
    ) {}

    /**
     * ¿Tiene sentido volver a intentarlo?
     *
     * **Sólo el error de red.** Un rechazo reenviado da el mismo rechazo, y
     * reintentarlo en bucle es cómo se acaba bloqueado por el servicio.
     */
    public function sePuedeReintentar(): bool
    {
        return $this->estado === self::ERROR_RED;
    }

    /** ¿Quedó el comprobante en poder de la administración? */
    public function entro(): bool
    {
        return in_array($this->estado, [self::ACEPTADO, self::OBSERVADO], true);
    }
}
