<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Eventos\CorreoPedido;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Confirmar un pago contra el extracto, y registrar el que vuelve (9.7).
 *
 * ### `sent` no es «llegó»
 *
 * `9.6` deja los pagos en `sent`, que significa **«lo mandamos»**. Entre eso y
 * que el creador tenga el dinero está el banco, y ahí un pago se rechaza porque
 * la cuenta estaba mal, porque el titular no coincide, o porque el archivo iba
 * mal. Mientras nadie mire el extracto, el sistema no sabe cuál de las dos cosas
 * pasó — y el creador tampoco.
 *
 * ### Un pago que vuelve no deshace el devengo
 *
 * El devengo se queda en `paid` porque **se pagó**: lo que falló fue la
 * transferencia, no el trabajo. Y `paid` es terminal desde `DEC-169`.
 *
 * La corrección es un asiento de `payment_reversal` que apunta al asiento de
 * pago original (`BR-FIN-002`): positivo, así que devuelve el saldo al creador.
 * Nace `accrued` —lo exige `ck_ledger_estado_inicial`— y se mueve a `payable` en
 * la misma operación: el creador ya cumplió, y su dinero no tiene que esperar a
 * que alguien se acuerde (decisión 2026-08-27). Los dos hechos quedan escritos:
 * se pagó, y volvió.
 */
final class Pagos
{
    public const ENVIADO = 'sent';

    public const CONFIRMADO = 'confirmed';

    public const DEVUELTO = 'returned';

    /** @var array<string, string> */
    public const ESTADOS = [
        'pending' => 'Pendiente',
        self::ENVIADO => 'Enviado',
        self::CONFIRMADO => 'Confirmado',
        self::DEVUELTO => 'Devuelto',
        'cancelled' => 'Sacado del lote',
    ];

    /**
     * Los pagos enviados que nadie ha conciliado todavía.
     *
     * Es la bandeja de esta iteración. Ordenada por lo que lleva más esperando y
     * no por importe: lo que se descubre recorriendo se descubre tarde, y un
     * pago que lleva tres semanas sin conciliar es la señal, valga lo que valga.
     *
     * @return Collection<int, \stdClass>
     */
    public static function porConciliar(int $limite = 100): Collection
    {
        return DB::table('payouts as p')
            ->join('payout_batches as pb', 'pb.id', '=', 'p.payout_batch_id')
            ->join('creators as cr', 'cr.id', '=', 'p.creator_id')
            ->join('legal_entities as le', 'le.id', '=', 'pb.legal_entity_id')
            ->where('p.status', self::ENVIADO)
            ->orderBy('p.sent_at')
            ->limit($limite)
            ->get(['p.id', 'p.uuid', 'p.amount', 'p.currency_code', 'p.sent_at',
                'p.beneficiary_name_snapshot', 'p.account_masked_snapshot',
                'cr.display_name as creador', 'pb.code as lote', 'le.code as sociedad']);
    }

    /**
     * Da un pago por llegado, contra el extracto.
     *
     * La referencia y la fecha valor no son opcionales — las exige
     * `ck_payout_conciliado` en la base—: sin ellas, «confirmado» es la palabra
     * de quien lo marcó, y conciliar un extracto dentro de seis meses se vuelve
     * imposible.
     */
    public static function confirmar(
        int $pagoId,
        string $referencia,
        string $fechaValor,
        ?int $archivoId,
        int $autorId,
    ): void {
        $pago = self::pago($pagoId);

        if ((string) $pago->status !== self::ENVIADO) {
            throw new RuntimeException(
                'Solo se confirma un pago enviado. Este esta '
                .(self::ESTADOS[$pago->status] ?? $pago->status).'.',
            );
        }

        DB::table('payouts')->where('id', $pagoId)->update([
            'status' => self::CONFIRMADO,
            'bank_reference' => mb_substr(trim($referencia), 0, 80),
            'value_date' => $fechaValor,
            'proof_file_id' => $archivoId,
            'confirmed_at' => now(),
            'confirmed_by_user_id' => $autorId,
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'payout.confirmed',
            tipoEntidad: 'payout',
            idEntidad: $pagoId,
            cambios: ['referencia' => ['antes' => null, 'despues' => mb_substr($referencia, 0, 80)]],
        );

        self::avisar($pagoId, 'finance.payout_confirmed');
    }

    /**
     * El banco lo devolvió. Se anota, y el dinero vuelve a deberse.
     *
     * Devuelve el uuid del asiento de reversión, que es lo que hay que poder
     * enseñar cuando alguien pregunte por qué el saldo subió.
     */
    public static function devolver(int $pagoId, string $motivo, int $autorId): string
    {
        $pago = self::pago($pagoId);

        if ((string) $pago->status !== self::ENVIADO) {
            throw new RuntimeException(
                'Solo se devuelve un pago enviado. Este esta '
                .(self::ESTADOS[$pago->status] ?? $pago->status).'.',
            );
        }

        $uuid = DB::transaction(function () use ($pago, $pagoId, $motivo, $autorId): string {
            DB::table('payouts')->where('id', $pagoId)->update([
                'status' => self::DEVUELTO,
                'returned_at' => now(),
                'returned_by_user_id' => $autorId,
                'return_reason' => mb_substr(trim($motivo), 0, 255),
                'updated_at' => now(),
            ]);

            $original = DB::table('ledger_entries')
                ->where('payout_id', $pagoId)->where('entry_type', Ledger::PAGO)
                ->first(['id', 'amount', 'currency_code']);

            if ($original === null) {
                // No deberia pasar: `9.6` escribe uno al ejecutar. Si pasa, se
                // dice en vez de dejar el saldo del creador diciendo que cobro
                // algo que no tiene.
                throw new RuntimeException(
                    "El pago {$pagoId} no tiene asiento en el libro mayor. "
                    .'No se puede revertir lo que no esta anotado.',
                );
            }

            $uuid = (string) Str::uuid();

            DB::table('ledger_entries')->insert([
                'uuid' => $uuid,
                'creator_id' => (int) $pago->creator_id,
                'entry_type' => Ledger::REVERSION_DE_PAGO,
                // Positivo: devuelve el saldo (`ck_ledger_sign`).
                'amount' => abs((float) $original->amount),
                'currency_code' => (string) $original->currency_code,
                // Nace `accrued` porque `ck_ledger_estado_inicial` no admite
                // nacer pagable: a pagable se LLEGA.
                'status' => Ledger::DEVENGADO,
                'reverses_entry_id' => (int) $original->id,
                'description' => 'Devolucion del pago: '.mb_substr(trim($motivo), 0, 200),
                'occurred_at' => now(),
                'created_at' => now(),
                'created_by_user_id' => $autorId,
            ]);

            $reversionId = (int) DB::table('ledger_entries')->where('uuid', $uuid)->value('id');

            // Y vuelve a la cola en la misma operacion: el creador ya cumplio, y
            // su dinero no tiene que esperar a que alguien se acuerde.
            Ledger::liberar(
                $reversionId,
                'El banco devolvio el pago: el dinero se le vuelve a deber.',
                $autorId,
            );

            return $uuid;
        });

        Bitacora::registrar(
            accion: 'payout.returned',
            tipoEntidad: 'payout',
            idEntidad: $pagoId,
            cambios: ['motivo' => ['antes' => null, 'despues' => mb_substr($motivo, 0, 255)]],
        );

        self::avisar($pagoId, 'finance.payout_returned');

        return $uuid;
    }

    /**
     * El aviso al creador.
     *
     * **Al confirmar y al devolver, no al enviar** (decisión 2026-08-27).
     * Avisar al enviar es avisar de una intención: si el banco lo rechaza, el
     * creador ya cree que cobró — y el segundo correo desmiente al primero.
     *
     * No lleva ni el número de cuenta ni la referencia bancaria: un correo se
     * lee en pantallas ajenas y se reenvía, y para saber que le pagaron no hace
     * falta ver su cuenta. Es la misma decisión que los avisos de `4.13`.
     */
    private static function avisar(int $pagoId, string $plantilla): void
    {
        $datos = DB::table('payouts as p')
            ->join('creators as cr', 'cr.id', '=', 'p.creator_id')
            ->where('p.id', $pagoId)
            ->first(['p.amount', 'p.currency_code', 'p.return_reason',
                'cr.email', 'cr.display_name', 'cr.locale']);

        if ($datos === null || ($datos->email ?? null) === null) {
            return;
        }

        Event::dispatch(new CorreoPedido(
            codigo: $plantilla,
            destinatario: (string) $datos->email,
            variables: [
                'nombre' => (string) $datos->display_name,
                'importe' => number_format((float) $datos->amount, 2),
                'moneda' => (string) $datos->currency_code,
                'motivo' => (string) ($datos->return_reason ?? ''),
                'enlace' => route('ingresos.mios'),
            ],
            idioma: (string) ($datos->locale ?: 'es'),
            tipoRelacionado: 'payout',
            idRelacionado: $pagoId,
        ));
    }

    private static function pago(int $pagoId): object
    {
        $pago = DB::table('payouts')->where('id', $pagoId)
            ->first(['id', 'status', 'creator_id', 'amount', 'currency_code']);

        if ($pago === null) {
            throw new RuntimeException("No existe el pago {$pagoId}.");
        }

        return $pago;
    }
}
