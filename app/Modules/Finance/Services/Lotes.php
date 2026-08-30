<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Shared\Audit\Bitacora;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Los lotes de pago (iteración 9.6).
 *
 * ### El lote existe para que la doble firma tenga dónde vivir
 *
 * `BR-FIN-016`: **todo pago pertenece a un lote**, y un pago único es un lote de
 * uno. Es lo que impide saltarse `BR-FIN-005` simplemente no creando lote. Lo
 * dice la migración de finanzas desde la Fase 2 y aquí sólo se respeta.
 *
 * ### Siempre dos firmas, sin umbral (decisión 2026-08-27)
 *
 * `BR-FIN-005` habla de un umbral configurable. Se decide **no tenerlo**: el
 * equipo es pequeño y los lotes son pocos y agrupados, así que la segunda firma
 * cuesta un clic al mes. Un umbral hay que elegirlo, mantenerlo y explicarlo — y
 * el día que alguien quiera saltarse la firma, parte el lote en dos.
 *
 * Que el aprobador sea otro lo impone `ck_pbatch_segregation` en la base.
 *
 * ### Un lote es de UNA sociedad y UNA moneda
 *
 * `payout_batches` ya tenía las dos columnas. La sociedad además **manda**:
 * `tg_pe_sociedad` no deja liquidar con este lote un devengo de una campaña de
 * otra sociedad (`BR-LE-009`, `DEC-157`).
 */
final class Lotes
{
    public const BORRADOR = 'draft';

    public const ESPERANDO = 'pending_approval';

    public const APROBADO = 'approved';

    public const EJECUTADO = 'executed';

    public const CANCELADO = 'cancelled';

    /** @var array<string, string> */
    public const ESTADOS = [
        self::BORRADOR => 'Borrador',
        self::ESPERANDO => 'Esperando aprobación',
        self::APROBADO => 'Aprobado',
        self::EJECUTADO => 'Ejecutado',
        self::CANCELADO => 'Cancelado',
    ];

    /**
     * Los devengos pagables de una sociedad y una moneda, agrupados por creador.
     *
     * La sociedad sale de la CAMPAÑA de cada devengo, no del creador: es
     * `DEC-156` —la campaña decide quién paga— convertida en consulta.
     *
     * @return Collection<int, \stdClass>
     */
    public static function loQueSePodriaPagar(int $entidadId, string $moneda): Collection
    {
        return DB::table('ledger_entries as le')
            ->join('campaign_creators as cc', 'cc.id', '=', 'le.campaign_creator_id')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->join('creators as cr', 'cr.id', '=', 'le.creator_id')
            ->leftJoin('payout_earnings as pe', function ($j): void {
                $j->on('pe.ledger_entry_id', '=', 'le.id')->whereNull('pe.voided_at');
            })
            ->where('le.entry_type', Ledger::DEVENGO)
            ->where('le.status', Ledger::PAGABLE)
            ->where('le.currency_code', $moneda)
            ->where('c.billing_legal_entity_id', $entidadId)
            ->whereNull('pe.id')
            ->orderBy('le.creator_id')->orderBy('le.occurred_at')
            ->get(['le.id', 'le.creator_id', 'le.amount', 'le.currency_code',
                'cr.display_name as creador', 'c.code as campana']);
    }

    /**
     * Arma un lote en borrador con todo lo pagable de esa sociedad y moneda.
     *
     * Un `payout` por creador, con la suma de sus devengos. El importe **no se
     * teclea**: sale de sumar lo que liquida, y por eso no puede discrepar de lo
     * que el lote dice que paga.
     *
     * Los datos bancarios se **copian congelados** al `payout`: hay que poder
     * reconstruir a dónde se envió el dinero aunque el creador cambie de cuenta
     * mañana. Es lo mismo que hace `BR-LE-005` con el emisor de una factura.
     */
    public static function armar(int $entidadId, string $moneda, int $autorId): string
    {
        $pagables = self::loQueSePodriaPagar($entidadId, $moneda);

        if ($pagables->isEmpty()) {
            throw new RuntimeException(
                'No hay nada pagable de esa sociedad en esa moneda. Un lote vacio no es un lote.',
            );
        }

        return DB::transaction(function () use ($pagables, $entidadId, $moneda, $autorId): string {
            $uuid = (string) Str::uuid();

            $loteId = (int) DB::table('payout_batches')->insertGetId([
                'uuid' => $uuid,
                'code' => 'LOTE-'.now()->format('Ymd').'-'.mb_strtoupper(Str::random(4)),
                'legal_entity_id' => $entidadId,
                'currency_code' => $moneda,
                'status' => self::BORRADOR,
                'created_by_user_id' => $autorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($pagables->groupBy('creator_id') as $creadorId => $devengos) {
                $medio = self::medioDePago((int) $creadorId);

                $pagoId = (int) DB::table('payouts')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'payout_batch_id' => $loteId,
                    'creator_id' => (int) $creadorId,
                    'payment_method_id' => (int) $medio->id,
                    'beneficiary_name_snapshot' => (string) $medio->holder_name,
                    'account_masked_snapshot' => (string) $medio->account_number_masked,
                    'amount' => $devengos->sum(fn (object $d): float => (float) $d->amount),
                    'currency_code' => $moneda,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($devengos as $devengo) {
                    // `tg_pe_sociedad` comprueba aqui que la sociedad del lote
                    // es la de la campana. No se comprueba antes en PHP a
                    // proposito: lo que solo protege el servicio no protege al
                    // proximo que escriba.
                    DB::table('payout_earnings')->insert([
                        'payout_id' => $pagoId,
                        'ledger_entry_id' => (int) $devengo->id,
                        'amount' => $devengo->amount,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            Bitacora::registrar(
                accion: 'payout_batch.created',
                tipoEntidad: 'payout_batch',
                idEntidad: $loteId,
                cambios: ['lote' => ['antes' => null, 'despues' => $pagables->count().' devengos']],
            );

            return $uuid;
        });
    }

    /** Por qué NO se puede aprobar este lote, o `null`. */
    public static function vetoParaAprobar(object $lote, int $aprobadorId): ?string
    {
        if (!in_array((string) $lote->status, [self::BORRADOR, self::ESPERANDO], true)) {
            return 'Este lote ya no espera aprobacion: esta '
                .(self::ESTADOS[$lote->status] ?? $lote->status).'.';
        }

        if ((int) $lote->created_by_user_id === $aprobadorId) {
            return 'Quien arma un lote no puede aprobarlo (BR-FIN-005). Tiene que firmarlo otra persona.';
        }

        $vivos = self::pagosVivos((int) $lote->id);

        if ($vivos === 0) {
            return 'Todos los pagos de este lote se sacaron. Un lote vacio no se aprueba: cancelelo.';
        }

        // El importe del lote es la suma de lo que liquida. Si discrepara, lo
        // que se firma no seria lo que se paga --y eso no se descubre mirando--.
        $descuadre = self::descuadre((int) $lote->id);

        if ($descuadre !== null) {
            return $descuadre;
        }

        return null;
    }

    public static function aprobar(object $lote, int $aprobadorId): void
    {
        $veto = self::vetoParaAprobar($lote, $aprobadorId);

        if ($veto !== null) {
            throw new RuntimeException($veto);
        }

        DB::table('payout_batches')->where('id', $lote->id)->update([
            'status' => self::APROBADO,
            'approved_by_user_id' => $aprobadorId,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'payout_batch.approved',
            tipoEntidad: 'payout_batch',
            idEntidad: (int) $lote->id,
            cambios: ['status' => ['antes' => $lote->status, 'despues' => self::APROBADO]],
        );
    }

    /**
     * Saca un pago del lote. El resto se ejecuta igual (decisión 2026-08-27).
     *
     * Se usa cuando un devengo se retiene entre la firma y la ejecución — un
     * post que se cae, por ejemplo. **No hace falta volver a aprobar**: el
     * importe total baja, nunca sube, y eso no puede sorprender a quien ya
     * firmó. Devolver el lote entero a borrador castigaría a diez creadores por
     * el problema de uno.
     *
     * Los devengos vuelven a la cola: su liquidación se anula, no se borra.
     */
    public static function sacarDelLote(int $pagoId, string $motivo, int $autorId): void
    {
        $pago = DB::table('payouts as p')
            ->join('payout_batches as pb', 'pb.id', '=', 'p.payout_batch_id')
            ->where('p.id', $pagoId)
            ->first(['p.id', 'p.status', 'pb.status as lote', 'pb.id as lote_id']);

        if ($pago === null) {
            throw new RuntimeException("No existe el pago {$pagoId}.");
        }

        if ((string) $pago->lote === self::EJECUTADO) {
            throw new RuntimeException(
                'El lote ya se ejecuto: el dinero salio. Esto se corrige con una devolucion, no sacandolo.',
            );
        }

        DB::transaction(function () use ($pago, $motivo, $autorId): void {
            DB::table('payout_earnings')
                ->where('payout_id', $pago->id)->whereNull('voided_at')
                ->update([
                    'voided_at' => now(),
                    'voided_by_user_id' => $autorId,
                    'voided_reason' => mb_substr($motivo, 0, 255),
                    'updated_at' => now(),
                ]);

            DB::table('payouts')->where('id', $pago->id)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
        });

        Bitacora::registrar(
            accion: 'payout.removed',
            tipoEntidad: 'payout',
            idEntidad: (int) $pago->id,
            cambios: ['motivo' => ['antes' => null, 'despues' => mb_substr($motivo, 0, 255)]],
        );
    }

    /**
     * Ejecuta el lote: el dinero sale.
     *
     * Cada pago vivo deja **un** asiento de pago en el libro mayor
     * (`BR-FIN-013`, `uq_ledger_payout`) y sus devengos pasan a `paid`, que es
     * terminal.
     */
    public static function ejecutar(object $lote, int $autorId): int
    {
        if ((string) $lote->status !== self::APROBADO) {
            throw new RuntimeException('Solo se ejecuta un lote aprobado.');
        }

        return DB::transaction(function () use ($lote, $autorId): int {
            $pagos = DB::table('payouts')->where('payout_batch_id', $lote->id)
                ->where('status', 'pending')
                ->get(['id', 'uuid', 'creator_id', 'amount', 'currency_code']);

            foreach ($pagos as $pago) {
                DB::table('ledger_entries')->insert([
                    'uuid' => (string) Str::uuid(),
                    'creator_id' => (int) $pago->creator_id,
                    'entry_type' => Ledger::PAGO,
                    // Negativo: un pago RESTA del saldo del creador
                    // (`ck_ledger_sign`).
                    'amount' => -1 * (float) $pago->amount,
                    'currency_code' => (string) $pago->currency_code,
                    'status' => Ledger::PAGADO,
                    'payout_id' => (int) $pago->id,
                    'description' => 'Pago del lote '.$lote->code,
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'created_by_user_id' => $autorId,
                ]);

                $liquidados = DB::table('payout_earnings')
                    ->where('payout_id', $pago->id)->whereNull('voided_at')
                    ->pluck('ledger_entry_id');

                foreach ($liquidados as $asientoId) {
                    Ledger::marcarPagado(
                        (int) $asientoId,
                        'Pagado en el lote '.$lote->code.'.',
                        $autorId,
                    );
                }

                DB::table('payouts')->where('id', $pago->id)->update([
                    'status' => 'sent', 'sent_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('payout_batches')->where('id', $lote->id)->update([
                'status' => self::EJECUTADO, 'executed_at' => now(), 'updated_at' => now(),
            ]);

            Bitacora::registrar(
                accion: 'payout_batch.executed',
                tipoEntidad: 'payout_batch',
                idEntidad: (int) $lote->id,
                cambios: ['pagos' => ['antes' => null, 'despues' => $pagos->count()]],
            );

            return $pagos->count();
        });
    }

    /**
     * El lote en CSV, para llevarlo al banco a mano.
     *
     * **No es el archivo del banco** (decisión 2026-08-27). El formato de una
     * transferencia masiva es específico de cada entidad y sale de su manual;
     * inventárselo produce un archivo que el banco rechaza sin decir por qué.
     * Esto es legible por una persona y sirve para teclear o para comprobar.
     */
    public static function csv(int $loteId): string
    {
        $filas = DB::table('payouts as p')
            ->join('creators as cr', 'cr.id', '=', 'p.creator_id')
            ->where('p.payout_batch_id', $loteId)
            ->where('p.status', '<>', 'cancelled')
            ->orderBy('cr.display_name')
            ->get(['cr.display_name', 'p.beneficiary_name_snapshot',
                'p.account_masked_snapshot', 'p.amount', 'p.currency_code']);

        $lineas = ['creador,beneficiario,cuenta,importe,moneda'];

        foreach ($filas as $f) {
            $lineas[] = implode(',', array_map(
                // Comillas y punto y coma fuera: un nombre con una coma parte la
                // linea y el banco lee otra cosa.
                fn (string $v): string => '"'.str_replace('"', "'", $v).'"',
                [(string) $f->display_name, (string) $f->beneficiary_name_snapshot,
                    (string) $f->account_masked_snapshot, (string) $f->amount,
                    (string) $f->currency_code],
            ));
        }

        return implode("\n", $lineas)."\n";
    }

    public static function pagosVivos(int $loteId): int
    {
        return DB::table('payouts')->where('payout_batch_id', $loteId)
            ->where('status', '<>', 'cancelled')->count();
    }

    /**
     * Si algún pago no vale lo que liquida, dicho con palabras. `null` si cuadra.
     *
     * No es una restricción de la base porque es una SUMA sobre otra tabla, y
     * eso ningún `CHECK` lo admite. Se comprueba antes de firmar, que es el
     * momento en que importa.
     */
    public static function descuadre(int $loteId): ?string
    {
        $pagos = DB::table('payouts as p')
            ->leftJoin('payout_earnings as pe', function ($j): void {
                $j->on('pe.payout_id', '=', 'p.id')->whereNull('pe.voided_at');
            })
            ->where('p.payout_batch_id', $loteId)
            ->where('p.status', '<>', 'cancelled')
            ->groupBy('p.id', 'p.amount')
            ->get(['p.id', 'p.amount', DB::raw('COALESCE(SUM(pe.amount), 0) as liquidado')]);

        foreach ($pagos as $pago) {
            if (abs((float) $pago->amount - (float) $pago->liquidado) > 0.00005) {
                return sprintf(
                    'El pago %d dice %s y liquida %s. Lo que se firma tiene que ser lo que se paga.',
                    $pago->id, $pago->amount, $pago->liquidado,
                );
            }
        }

        return null;
    }

    private static function medioDePago(int $creadorId): object
    {
        $medio = DB::table('creator_payment_methods')
            ->where('creator_id', $creadorId)->where('status', 'verified')
            ->orderByDesc('verified_at')
            ->first(['id', 'holder_name', 'account_number_masked']);

        if ($medio === null) {
            // No deberia pasar: tener medio verificado es una de las cinco
            // condiciones de `BR-FIN-003`. Si pasa, se dice en vez de insertar
            // un pago sin destino.
            throw new RuntimeException(
                "El creador {$creadorId} tiene devengos pagables y ningun medio de pago verificado. "
                .'Eso no deberia poder pasar: mire BR-FIN-003.',
            );
        }

        return $medio;
    }
}
