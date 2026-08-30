<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use Illuminate\Support\Facades\DB;

/**
 * Lo que una campaña deja (9.10).
 *
 * Tres cifras y una resta: **ingreso − costo de creadores − gasto operativo**.
 * Las tres existían desde iteraciones distintas y hasta hoy nunca se habían
 * puesto juntas — `revenue_amount` desde `7.1`, el devengo desde `9.3`, el gasto
 * desde `9.10a`, que es la que faltaba y por la que el margen de `7.7` salía más
 * alto de lo que era.
 *
 * ### Nunca se enseña fuera (`BR-SEC-001`, 🔴)
 *
 * Ni a un cliente ni a un creador. Y desde `9.10a`, tampoco a quien lleva la
 * campaña: `campaign.view_margin` es de finanzas y dirección (`DEC-181`).
 *
 * ### Cada moneda por su lado, y el porcentaje sólo cuando se puede
 *
 * Una campaña se cobra en soles y un envío se pagó en dólares. Restarlos exige
 * elegir compra, venta o media (`Q-63`), y **cada elección da un margen distinto
 * para los mismos hechos**; peor aún, el día que se elija cambian todos los
 * márgenes históricos a la vez.
 *
 * Así que se agrupa por moneda, como en `9.10a` y como el saldo del creador en
 * `9.8`. **El porcentaje sólo aparece cuando todo está en una sola moneda** —que
 * hoy es el caso normal— y cuando no, la pantalla dice que no puede darlo y por
 * qué. Un porcentaje que mezcla monedas es un número que parece exacto y
 * depende de una elección que nadie ha hecho.
 *
 * ### El ingreso es el DECLARADO, no el facturado
 *
 * `campaigns.revenue_amount` es lo que se dijo que se le cobra al cliente. No
 * hay facturación todavía (`9.9`), así que no es lo facturado ni lo cobrado, y
 * la pantalla lo dice con esas palabras. Es también la razón de que aquí no haya
 * total por sociedad: sumar precios declarados y presentarlos por sociedad se
 * parece a un estado de resultados y no lo es.
 */
final class Rentabilidad
{
    /**
     * La rentabilidad de UNA campaña, por moneda.
     *
     * @return array{
     *   monedas: array<string, array{ingreso: float, creadores: float, gasto: float, margen: float}>,
     *   porcentaje: float|null,
     *   moneda_unica: string|null,
     *   canje: bool,
     *   veto_porcentaje: string|null
     * }
     */
    public static function deUnaCampana(object $campana): array
    {
        $moneda = (string) $campana->currency_code;
        $canje = (bool) $campana->is_gratis;

        $monedas = [];
        $monedas[$moneda] = [
            'ingreso' => (float) $campana->revenue_amount,
            'creadores' => 0.0, 'gasto' => 0.0, 'margen' => 0.0,
        ];

        foreach (Costos::creadoresPorMoneda((int) $campana->id) as $codigo => $total) {
            $monedas[$codigo] ??= ['ingreso' => 0.0, 'creadores' => 0.0, 'gasto' => 0.0, 'margen' => 0.0];
            $monedas[$codigo]['creadores'] = $total;
        }

        foreach (Costos::resumen((int) $campana->id) as $codigo => $datos) {
            $monedas[$codigo] ??= ['ingreso' => 0.0, 'creadores' => 0.0, 'gasto' => 0.0, 'margen' => 0.0];
            $monedas[$codigo]['gasto'] = $datos['total'];
        }

        foreach ($monedas as $codigo => $fila) {
            $monedas[$codigo]['margen'] = $fila['ingreso'] - $fila['creadores'] - $fila['gasto'];
        }

        ksort($monedas);

        return [
            'monedas' => $monedas,
            'porcentaje' => self::porcentaje($monedas, $canje),
            'moneda_unica' => count($monedas) === 1 ? array_key_first($monedas) : null,
            'canje' => $canje,
            'veto_porcentaje' => self::vetoParaElPorcentaje($monedas, $canje),
        ];
    }

    /**
     * Todas las campañas con su margen, agrupadas por la moneda de la campaña.
     *
     * Ordenadas de peor a mejor dentro de cada grupo: la pregunta por la que se
     * abre esta pantalla es **cuáles pierden dinero**, y ésas tienen que estar
     * arriba sin que nadie ordene nada.
     *
     * No se ordena entre grupos. Comparar un margen en soles con uno en dólares
     * es la misma conversión que no se hace (`Q-63`).
     *
     * @param array<string, mixed> $filtros
     * @return array<string, array{
     *   campanas: list<array<string, mixed>>,
     *   total: array{ingreso: float, creadores: float, gasto: float, margen: float},
     *   fuera: int
     * }>
     */
    public static function listado(array $filtros = []): array
    {
        $consulta = DB::table('campaigns as c')
            ->leftJoin('client_brands as b', 'b.id', '=', 'c.client_brand_id')
            ->select(['c.id', 'c.uuid', 'c.code', 'c.name', 'c.status', 'c.currency_code',
                'c.revenue_amount', 'c.is_gratis', 'c.starts_on', 'b.name as marca']);

        if (($filtros['estado'] ?? '') !== '') {
            $consulta->where('c.status', $filtros['estado']);
        }

        $campanas = $consulta->orderByDesc('c.starts_on')->limit(300)->get();

        if ($campanas->isEmpty()) {
            return [];
        }

        $ids = $campanas->pluck('id')->all();
        $creadores = self::creadoresDe($ids);
        $gastos = self::gastosDe($ids);

        $grupos = [];

        foreach ($campanas as $campana) {
            $moneda = (string) $campana->currency_code;
            $canje = (bool) $campana->is_gratis;

            $mios = ($creadores[(int) $campana->id] ?? []) + [];
            $suyos = ($gastos[(int) $campana->id] ?? []) + [];

            // «Otras monedas» es la advertencia que hace honesta a la fila: si
            // una campana en soles pago un envio en dolares, el margen en soles
            // NO lo incluye, y ensenarlo sin decirlo es esconder un costo.
            $otras = array_values(array_unique(array_diff(
                array_merge(array_keys($mios), array_keys($suyos)),
                [$moneda],
            )));
            sort($otras);

            $ingreso = (float) $campana->revenue_amount;
            $costoCreadores = (float) ($mios[$moneda] ?? 0.0);
            $gasto = (float) ($suyos[$moneda] ?? 0.0);

            $fila = [
                'uuid' => (string) $campana->uuid,
                'code' => (string) $campana->code,
                'name' => (string) $campana->name,
                'marca' => $campana->marca,
                'status' => (string) $campana->status,
                'moneda' => $moneda,
                'ingreso' => $ingreso,
                'creadores' => $costoCreadores,
                'gasto' => $gasto,
                'margen' => $ingreso - $costoCreadores - $gasto,
                'canje' => $canje,
                'otras_monedas' => $otras,
            ];

            $grupos[$moneda]['campanas'][] = $fila;
        }

        foreach ($grupos as $moneda => $grupo) {
            usort($grupo['campanas'], static fn (array $a, array $b): int => $a['margen'] <=> $b['margen']);

            $total = ['ingreso' => 0.0, 'creadores' => 0.0, 'gasto' => 0.0, 'margen' => 0.0];
            $fuera = 0;

            foreach ($grupo['campanas'] as $fila) {
                // Un canje no entra en ningun total (DEC-184): su ingreso es
                // cero POR DECISION, asi que su margen siempre es negativo, y
                // promediarlo hace que la cartera entera parezca peor por un
                // motivo que fue deliberado. Tampoco entra una campana con
                // gastos en otra moneda: su margen aqui esta incompleto, y
                // sumar un numero incompleto lo vuelve invisible.
                if ($fila['canje'] || $fila['otras_monedas'] !== []) {
                    $fuera++;

                    continue;
                }

                $total['ingreso'] += $fila['ingreso'];
                $total['creadores'] += $fila['creadores'];
                $total['gasto'] += $fila['gasto'];
                $total['margen'] += $fila['margen'];
            }

            $grupos[$moneda] = ['campanas' => $grupo['campanas'], 'total' => $total, 'fuera' => $fuera];
        }

        ksort($grupos);

        return $grupos;
    }

    /**
     * El porcentaje, o `null` si no se puede dar.
     *
     * @param array<string, array{ingreso: float, creadores: float, gasto: float, margen: float}> $monedas
     */
    private static function porcentaje(array $monedas, bool $canje): ?float
    {
        if (self::vetoParaElPorcentaje($monedas, $canje) !== null) {
            return null;
        }

        $unica = $monedas[array_key_first($monedas)];

        return $unica['margen'] / $unica['ingreso'] * 100;
    }

    /**
     * Por qué NO se puede dar un porcentaje, o `null` si sí.
     *
     * Devuelve el motivo con palabras y no un `false`: quien mira una pantalla
     * donde falta un número necesita saber si falta porque no lo hay o porque no
     * se puede calcular, y son dos conversaciones distintas.
     *
     * @param array<string, array{ingreso: float, creadores: float, gasto: float, margen: float}> $monedas
     */
    private static function vetoParaElPorcentaje(array $monedas, bool $canje): ?string
    {
        if (count($monedas) > 1) {
            return 'Esta campana tiene importes en '.implode(' y ', array_keys($monedas))
                .'. Un porcentaje exigiria convertirlas, y que tasa se aplica --compra, venta '
                .'o media-- es una decision contable que sigue abierta.';
        }

        if ($canje) {
            return 'Es un canje: el ingreso es cero por decision, asi que un porcentaje '
                .'sobre cero no significa nada. Lo que si dice algo es cuanto costo.';
        }

        $unica = $monedas[array_key_first($monedas)];

        if ($unica['ingreso'] <= 0.0) {
            return 'Esta campana todavia no tiene ingreso declarado.';
        }

        return null;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, float>>
     */
    private static function creadoresDe(array $ids): array
    {
        $filas = DB::table('ledger_entries as le')
            ->join('campaign_creators as cc', 'cc.id', '=', 'le.campaign_creator_id')
            ->whereIn('cc.campaign_id', $ids)
            ->where('le.entry_type', Ledger::DEVENGO)
            ->whereNotIn('le.status', Costos::DEVENGOS_MUERTOS)
            ->groupBy('cc.campaign_id', 'le.currency_code')
            ->get(['cc.campaign_id', 'le.currency_code', DB::raw('SUM(le.amount) as total')]);

        $porCampana = [];

        foreach ($filas as $fila) {
            $porCampana[(int) $fila->campaign_id][(string) $fila->currency_code] = (float) $fila->total;
        }

        return $porCampana;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, float>>
     */
    private static function gastosDe(array $ids): array
    {
        $filas = DB::table('campaign_costs')
            ->whereIn('campaign_id', $ids)
            ->whereNull('voided_at')
            ->groupBy('campaign_id', 'currency_code')
            ->get(['campaign_id', 'currency_code', DB::raw('SUM(amount) as total')]);

        $porCampana = [];

        foreach ($filas as $fila) {
            $porCampana[(int) $fila->campaign_id][(string) $fila->currency_code] = (float) $fila->total;
        }

        return $porCampana;
    }
}
