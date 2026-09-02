<?php

declare(strict_types=1);

namespace App\Shared\Integracion;

use RuntimeException;

/**
 * Esta instalación no puede hablar con ese entorno (9.22a, `DEC-029`).
 *
 * ### Por qué tiene tipo propio y no es un `RuntimeException` más
 *
 * Porque quien la recibe tiene que poder **distinguirla de «falta configurar»**,
 * y son lo contrario la una de la otra:
 *
 * - «Falta configurar» se arregla **en el panel**, y se arregla aquí.
 * - Esto se arregla **en otra máquina**, o no se arregla: la respuesta correcta
 *   suele ser *no lo mandes desde aquí*.
 *
 * Es la misma lección que `DEC-275` con los cinco finales de un envío: dos
 * cosas que se ven iguales en un `catch` genérico y que exigen arreglos
 * distintos no pueden compartir tipo, o alguien acabará reintentando la que no
 * se reintenta.
 *
 * ### Vive en `Shared` a propósito
 *
 * La barrera no es de finanzas. Hoy la usa la emisión electrónica porque es lo
 * único que sale al mundo real; mañana la usan el correo, los cobros y los pagos
 * a creadores, y ninguno de esos puede depender de `Finance` para atrapar su
 * propia excepción.
 */
final class EntornoAjeno extends RuntimeException {}
