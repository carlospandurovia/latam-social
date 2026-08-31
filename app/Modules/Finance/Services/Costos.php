<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Shared\Audit\Bitacora;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lo que una campaña nos cuesta a nosotros (9.10a).
 *
 * ### Por qué existe esta iteración
 *
 * `campaign_costs` está en el esquema desde la Fase 2 con sus restricciones y
 * su `tg_cco_no_delete` — y con **cero filas**. Mientras siga vacía, cualquier
 * cuenta de rentabilidad sólo puede restar lo que se le paga a los creadores y
 * llamar «margen» al resto: un número que ignora el producto, los envíos y la
 * producción, y que por eso sale siempre más alto de lo que es.
 *
 * Ésta es la mitad que faltaba del dato. El P&L es `9.10`.
 *
 * ### Cada moneda por su lado
 *
 * Un envío se paga en dólares y la campaña se cobra en soles. Sumarlos exige un
 * tipo de cambio, y **cuál** es una decisión contable que sigue abierta
 * (`Q-63`): la tasa de compra, la de venta y la media dan tres márgenes
 * distintos para los mismos hechos. Así que aquí no se suma nada entre monedas
 * — se agrupa, exactamente como el saldo del creador en `9.8`.
 *
 * ### El costo de los creadores entra al DEVENGARSE
 *
 * No al pagarse. La deuda con el creador existe desde que acepta (`9.4`) y su
 * importe está congelado desde `7.5`; esperar al pago haría que una campaña
 * terminada en marzo pareciera rentabilísima hasta el lote de abril. Se excluye
 * lo anulado, que es dinero que ya no se debe.
 *
 * Nótese que **no** es lo mismo que `Compromiso::comprometido()`, que cuenta
 * también a los `shortlisted` — allí eso es correcto, porque su trabajo es
 * proteger el presupuesto ANTES de invitar. Aquí contaría dinero de gente que
 * todavía puede decir que no.
 */
final class Costos
{
    /** @var array<string, string> */
    public const TIPOS = [
        'product' => 'Producto',
        'shipping' => 'Envíos',
        'production' => 'Producción',
        'media' => 'Pauta y medios',
        'tool' => 'Herramientas',
        'other' => 'Otros',
    ];

    /** Los estados de un devengo que ya no cuestan nada. */
    public const DEVENGOS_MUERTOS = ['void'];

    /**
     * Anota un gasto de la campaña. Devuelve su id.
     *
     * No comprueba el estado de la campaña a propósito: una campaña cancelada
     * puede tener gastos de verdad —el producto ya salió— y no poder anotarlos
     * dejaría la pérdida sin registrar, que es justo el caso en que interesa.
     */
    public static function anotar(
        int $campanaId,
        string $tipo,
        string $descripcion,
        float $monto,
        string $moneda,
        string $fecha,
        ?int $archivoId,
        int $autorId,
    ): int {
        if (!array_key_exists($tipo, self::TIPOS)) {
            throw new RuntimeException("Tipo de costo desconocido: {$tipo}.");
        }

        $id = DB::table('campaign_costs')->insertGetId([
            'campaign_id' => $campanaId,
            'cost_type' => $tipo,
            'description' => mb_substr(trim($descripcion), 0, 255),
            'amount' => $monto,
            'currency_code' => $moneda,
            'incurred_on' => $fecha,
            'file_id' => $archivoId,
            'created_by_user_id' => $autorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'campaign_cost.created',
            tipoEntidad: 'campaign_cost',
            idEntidad: $id,
            cambios: [
                'campana' => ['antes' => null, 'despues' => $campanaId],
                'importe' => ['antes' => null, 'despues' => number_format($monto, 2, '.', '')],
                'moneda' => ['antes' => null, 'despues' => $moneda],
            ],
        );

        return $id;
    }

    /**
     * Anula un gasto mal anotado. **No se borra**: el margen de ayer tiene que
     * poder reconstruirse, y una fila que desaparece se lleva esa posibilidad
     * con ella (`tg_cco_no_delete`, Fase 2).
     */
    public static function anular(int $costoId, string $motivo, int $autorId): void
    {
        $costo = DB::table('campaign_costs')->where('id', $costoId)
            ->first(['id', 'voided_at', 'amount', 'currency_code']);

        if ($costo === null) {
            throw new RuntimeException("No existe el costo {$costoId}.");
        }

        if ($costo->voided_at !== null) {
            throw new RuntimeException('Ese costo ya estaba anulado.');
        }

        if (trim($motivo) === '') {
            throw new RuntimeException('Anular un costo exige decir por que.');
        }

        DB::table('campaign_costs')->where('id', $costoId)->update([
            'voided_at' => now(),
            'voided_by_user_id' => $autorId,
            'voided_reason' => mb_substr(trim($motivo), 0, 255),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'campaign_cost.voided',
            tipoEntidad: 'campaign_cost',
            idEntidad: $costoId,
            cambios: ['motivo' => ['antes' => null, 'despues' => mb_substr(trim($motivo), 0, 255)]],
        );
    }

    /**
     * Los gastos de la campaña, **incluidos los anulados**.
     *
     * Los anulados se enseñan tachados y con su motivo en vez de desaparecer:
     * quien mira una cifra que no le cuadra necesita ver que hubo una
     * corrección, y una fila que se esconde produce la misma pregunta dos veces.
     *
     * @return Collection<int, \stdClass>
     */
    public static function deUnaCampana(int $campanaId): Collection
    {
        return DB::table('campaign_costs as cc')
            ->leftJoin('users as u', 'u.id', '=', 'cc.created_by_user_id')
            ->leftJoin('users as v', 'v.id', '=', 'cc.voided_by_user_id')
            // 9.15: el uuid del comprobante, para poder enlazarlo. La ruta va
            // por uuid y no por id: un id correlativo en una URL invita a probar
            // el siguiente, y aunque el `Vigilante` lo pararia, la mejor puerta
            // es la que ni siquiera se puede enumerar.
            ->leftJoin('files as f', 'f.id', '=', 'cc.file_id')
            ->where('cc.campaign_id', $campanaId)
            ->orderByDesc('cc.incurred_on')->orderByDesc('cc.id')
            ->get(['cc.id', 'cc.cost_type', 'cc.description', 'cc.amount', 'cc.currency_code',
                'cc.incurred_on', 'cc.file_id', 'cc.voided_at', 'cc.voided_reason',
                'u.name as autor', 'v.name as anulador', 'f.uuid as archivo_uuid']);
    }

    /**
     * El gasto vivo de la campaña, por moneda y por tipo.
     *
     * @return array<string, array{total: float, tipos: array<string, float>}>
     */
    public static function resumen(int $campanaId): array
    {
        $filas = DB::table('campaign_costs')
            ->where('campaign_id', $campanaId)
            ->whereNull('voided_at')
            ->groupBy('currency_code', 'cost_type')
            ->get([
                'currency_code',
                'cost_type',
                DB::raw('SUM(amount) as total'),
            ]);

        $resumen = [];

        foreach ($filas as $fila) {
            $moneda = (string) $fila->currency_code;
            $resumen[$moneda] ??= ['total' => 0.0, 'tipos' => []];
            $resumen[$moneda]['tipos'][(string) $fila->cost_type] = (float) $fila->total;
            $resumen[$moneda]['total'] += (float) $fila->total;
        }

        ksort($resumen);

        return $resumen;
    }

    /**
     * Lo comprometido con creadores en esta campaña, por moneda.
     *
     * Sale del libro mayor y no de `campaign_creators` porque el libro es quien
     * sabe qué devengos siguen vivos: uno anulado ya no se debe, y sumar el
     * importe pactado lo seguiría contando.
     *
     * @return array<string, float>
     */
    public static function creadoresPorMoneda(int $campanaId): array
    {
        $filas = DB::table('ledger_entries as le')
            ->join('campaign_creators as cc', 'cc.id', '=', 'le.campaign_creator_id')
            ->where('cc.campaign_id', $campanaId)
            ->where('le.entry_type', Ledger::DEVENGO)
            ->whereNotIn('le.status', self::DEVENGOS_MUERTOS)
            ->groupBy('le.currency_code')
            ->get(['le.currency_code', DB::raw('SUM(le.amount) as total')]);

        $porMoneda = [];

        foreach ($filas as $fila) {
            $porMoneda[(string) $fila->currency_code] = (float) $fila->total;
        }

        ksort($porMoneda);

        return $porMoneda;
    }
}
