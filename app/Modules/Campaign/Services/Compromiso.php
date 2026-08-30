<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use Illuminate\Support\Facades\DB;

/**
 * El dinero que la campaña compromete con los creadores (7.5).
 *
 * ### `BR-CAMPAIGN-005` no es que nadie la comprobara: es que no se podía
 *
 * La regla es 🔴 y nombra *«el presupuesto de creadores de la campaña»*.
 * `campaigns` tenía `revenue_amount` —lo que se le cobra al cliente— y nada
 * más. **El dato que la regla nombra no estaba en el modelo.** Es el cuarto caso
 * del patrón de 7.1 a 7.4, y el peor de los cinco: en los otros faltaba el
 * código, aquí faltaba la columna.
 *
 * ### Qué cuenta como comprometido
 *
 * Las participaciones **vivas**: todo lo que no está `declined`, `expired` ni
 * `cancelled`. Un creador que rechazó no consume presupuesto — contarlo dejaría
 * campañas bloqueadas por dinero que nadie se va a gastar.
 *
 * `shortlisted` **sí cuenta**, y es deliberado. Un candidato con importe puesto
 * es dinero que alguien ya decidió gastar; descubrir el sobrecosto al invitar,
 * cuando la lista está armada y hay que deshacerla, es descubrirlo tarde.
 *
 * ### El margen no sale de aquí
 *
 * `margen()` existe y **nunca se enseña a un cliente ni a un creador**. Es
 * información interna: lo que se cobra menos lo que se paga. La pantalla que lo
 * usa exige `campaign.view_margin` y no `campaign.view`, y desde `9.10a` ese
 * permiso **ya no lo tiene quien lleva la campaña** (`DEC-181`).
 */
final class Compromiso
{
    /** Las participaciones que NO consumen presupuesto. */
    public const MUERTAS = ['declined', 'expired', 'cancelled'];

    /**
     * Lo que la campaña ya tiene comprometido con creadores.
     *
     * `$excepto` deja fuera una participación concreta, para poder preguntar
     * «¿cuánto habría si a ésta le pongo otro importe?» sin contar dos veces el
     * que ya tiene. Sin él, subir de 500 a 600 parecía gastar 1.100.
     */
    public static function comprometido(int $campanaId, ?int $excepto = null): float
    {
        $consulta = DB::table('campaign_creators')
            ->where('campaign_id', $campanaId)
            ->whereNotIn('status', self::MUERTAS);

        if ($excepto !== null) {
            $consulta->where('id', '!=', $excepto);
        }

        return (float) $consulta->sum('agreed_amount');
    }

    /**
     * Por qué este importe **no** se puede comprometer, o `null` si sí.
     *
     * Devuelve el veto con **los tres números**: lo que ya hay, lo que se añade
     * y el techo. «Excede el presupuesto» sin cifras obliga a ir a buscarlas, y
     * quien monta una campaña necesita saber por cuánto se pasa para decidir si
     * pide la autorización o baja el importe.
     */
    public static function vetoPorPresupuesto(
        object $campana,
        float $importe,
        ?int $excepto = null,
    ): ?string {
        // La autorizacion de finanzas levanta el techo para TODA la campana, no
        // para una participacion. Es lo que pidio el negocio: se firma una vez,
        // con su motivo, y queda en la fila.
        if ($campana->budget_override_at !== null) {
            return null;
        }

        $ya = self::comprometido((int) $campana->id, $excepto);
        $techo = (float) $campana->creator_budget_amount;
        $total = $ya + $importe;

        if ($total <= $techo) {
            return null;
        }

        if ($techo <= 0.0) {
            return 'Esta campana no tiene presupuesto de creadores. Pongale uno antes de '
                .'comprometer dinero con nadie (BR-CAMPAIGN-005).';
        }

        return sprintf(
            'Con este importe el comprometido sube a %s y el presupuesto de creadores es %s: '
            .'se pasa en %s. Baje el importe, o pida a finanzas que autorice el sobrecosto '
            .'dejando el motivo (BR-CAMPAIGN-005).',
            number_format($total, 2),
            number_format($techo, 2),
            number_format($total - $techo, 2),
        );
    }

    /**
     * El importe que sugiere el sistema para un creador, con las tarifas de hoy.
     *
     * Es la **referencia** de `BR-CREATOR-008`, no el compromiso: se propone, se
     * puede cambiar, y lo que vincula es lo que quede escrito en la fila. Se
     * calcula con la misma función que la columna de coste del buscador, para
     * que el número que se vio al elegir sea el que se propone al comprometer.
     *
     * @return array{importe: float|null, aviso: string|null, formatos: int}
     */
    public static function propuesta(object $campana, int $creadorId): array
    {
        $costes = BuscadorDeCreadores::costeEstimado($campana, [$creadorId]);

        return $costes[$creadorId] ?? ['importe' => null, 'aviso' => 'sin datos', 'formatos' => 0];
    }

    /**
     * Por qué el importe de esta participación ya no se toca, o `null`.
     *
     * En cuanto el creador aceptó, ese número es un acuerdo entre dos partes.
     * Lo impide además `tg_ccr_compromiso` en la base: de aquí sale lo que se le
     * paga a una persona, y tiene que sobrevivir a un mantenimiento.
     */
    public static function vetoPorCongelado(object $participacion): ?string
    {
        if ($participacion->accepted_at === null) {
            return null;
        }

        return 'Ese creador ya acepto: el monto acordado quedo congelado (BR-CREATOR-008). '
            .'Cambiarlo exige una enmienda aceptada por las dos partes (BR-CAMPAIGN-003), '
            .'no un formulario.';
    }

    /**
     * El margen interno de la campaña. **Nunca se enseña fuera.**
     *
     * Es `revenue_amount` menos lo comprometido con creadores. **No incluye los
     * costos operativos** de `campaign_costs` —producción, envíos—, que desde
     * `9.10a` sí se pueden anotar: este número sale más alto de lo que es, y la
     * pantalla lo dice con esas palabras hasta que `9.10` construya el margen
     * completo.
     *
     * No se arreglan aquí a propósito. `campaign_costs` es de Finance y
     * `deptrac` no deja que Campaign la conozca; y sumar costos en otra moneda
     * exige un tipo de cambio que nadie ha elegido (`Q-63`, `DEC-180`). El
     * margen completo vive en Finance, no aquí.
     *
     * @return array{ingreso: float, comprometido: float, margen: float, porcentaje: float|null}
     */
    public static function margen(object $campana): array
    {
        $ingreso = (float) $campana->revenue_amount;
        $comprometido = self::comprometido((int) $campana->id);

        return [
            'ingreso' => $ingreso,
            'comprometido' => $comprometido,
            'margen' => $ingreso - $comprometido,
            // Guardado contra el cero: una campana gratuita (7.2) tiene ingreso
            // cero, y dividir por el daria una division por cero justo en el
            // caso que 7.2 declaro legitimo.
            'porcentaje' => $ingreso > 0 ? ($ingreso - $comprometido) / $ingreso * 100 : null,
        ];
    }
}
