<?php

declare(strict_types=1);

namespace App\Modules\Creator\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ¿Esta cuenta bancaria aparece en más de un creador? (`DEC-065`, `T-19`)
 *
 * ### Por qué se calcula y no se guarda
 *
 * `tg_cpm_compartida` es `BEFORE INSERT` y sólo puede escribir `NEW`. Cuando el
 * creador 2 da de alta la cuenta del creador 1, la fila del 2 queda
 * `pending_review` y **la del 1 sigue diciendo `unique`**. El operador que abre
 * la pantalla del creador 1 —el que probablemente cobre primero— ve «única»
 * mientras la cuenta está duplicada.
 *
 * Lo obvio sería un `AFTER INSERT` que marcase también a la fila anterior. **No
 * se puede**: comprobado contra el motor,
 *
 * > `ERROR 1442: Can't update table 'creator_payment_methods' in stored
 * > function/trigger because it is already used by statement which invoked this
 * > stored function/trigger.`
 *
 * Un disparador no puede tocar su propia tabla. Y hacerlo desde la aplicación
 * dejaría la regla fuera de la base: cualquier importación u orden de consola se
 * la saltaría, que es justo lo que este proyecto evita.
 *
 * Así que **el hecho no se guarda dos veces**. «Compartida» es una propiedad del
 * conjunto de filas con la misma huella, no de una fila; se pregunta al leer y
 * entonces todas las filas implicadas dicen lo mismo por construcción.
 *
 * ### Qué queda guardado
 *
 * `shared_account_status` deja de ser la DETECCIÓN y pasa a ser el resultado de
 * la REVISIÓN: `cleared` significa «una persona miró esto y dijo que está bien»,
 * y eso sí es un hecho de la fila y sí hay que conservarlo. `unique` y
 * `pending_review` los sigue poniendo el disparador y ya no se leen para decidir:
 * son el estado inicial.
 *
 * Efecto colateral: `T-20` desaparece. Si el estado no se guarda, el comando que
 * recalcula huellas tras rotar `APP_KEY` no puede dejarlo desfasado.
 */
final class CuentasCompartidas
{
    /**
     * Las huellas que aparecen en más de un creador, de entre las que se pasen.
     *
     * Se resuelve en **una** consulta para toda la pantalla: preguntar fila a
     * fila sería una consulta por medio de pago.
     *
     * Sólo se miran las cuentas **abiertas**: una cuenta retirada o rechazada ya
     * no cobra, así que compartirla con ella no es un riesgo de pago. Es la
     * misma frontera que usa `uq_cpm_open_account`.
     *
     * @param list<string> $huellas
     * @return array<string, list<int>> huella => ids de creador que la usan
     */
    public static function repartoDe(array $huellas): array
    {
        $huellas = array_values(array_unique(array_filter($huellas)));

        if ($huellas === []) {
            return [];
        }

        $filas = DB::table('creator_payment_methods')
            ->whereIn('account_number_fingerprint', $huellas)
            ->whereNotIn('status', ['rejected', 'disabled'])
            ->get(['account_number_fingerprint as huella', 'creator_id']);

        $porHuella = [];

        foreach ($filas as $fila) {
            $porHuella[(string) $fila->huella][] = (int) $fila->creator_id;
        }

        foreach ($porHuella as $huella => $creadores) {
            $unicos = array_values(array_unique($creadores));

            if (count($unicos) < 2) {
                unset($porHuella[$huella]);

                continue;
            }

            $porHuella[$huella] = $unicos;
        }

        return $porHuella;
    }

    /**
     * Marca cada medio de pago con `compartida_con`: cuántos OTROS creadores
     * usan esa misma cuenta. Cero si no la comparte nadie.
     *
     * La colección tiene que traer `account_number_fingerprint` y `creator_id`.
     *
     * @param Collection<int, \stdClass> $medios
     * @return Collection<int, \stdClass>
     */
    public static function anotar(Collection $medios): Collection
    {
        $reparto = self::repartoDe($medios->pluck('account_number_fingerprint')->all());

        return $medios->map(function (object $m) use ($reparto): object {
            $creadores = $reparto[(string) ($m->account_number_fingerprint ?? '')] ?? [];

            $m->compartida_con = count(array_filter(
                $creadores,
                fn (int $id): bool => $id !== (int) $m->creator_id,
            ));

            // Y la huella no sigue viaje hacia la vista: es un derivado del
            // número de cuenta y no tiene nada que hacer en una plantilla.
            unset($m->account_number_fingerprint);

            return $m;
        });
    }

    /**
     * ¿Comparte esta cuenta con algún otro creador, ahora mismo?
     */
    public static function estaCompartida(string $huella, int $creadorId): bool
    {
        $creadores = self::repartoDe([$huella])[$huella] ?? [];

        return array_filter($creadores, fn (int $id): bool => $id !== $creadorId) !== [];
    }
}
