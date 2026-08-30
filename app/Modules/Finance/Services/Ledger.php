<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Shared\Audit\Bitacora;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * El libro mayor del creador (iteración 9.3).
 *
 * ### El saldo NO es una columna
 *
 * `BR-FIN-001`. Es la suma de los asientos, filtrada por estado. Una columna de
 * saldo es una caché que se desincroniza, y el día que lo hace nadie sabe cuál
 * de los dos números es el bueno. Aquí no hay dos números.
 *
 * ### Y un asiento no se edita
 *
 * `BR-FIN-002`, impuesta por `tg_ledger_no_update` desde la Fase 2 —columna por
 * columna, dejando pasar sólo `status`—. Corregir es **otro asiento**, de
 * reversión, que apunta al original. Los dos quedan.
 *
 * ### Lo que esta clase añade
 *
 * `devengar()`, `revisarPagable()`, `retener()`, `liberar()`, `anular()` y
 * `saldo()`. Y sobre todo `requisitos()`, que es donde vive `BR-FIN-003`.
 *
 * **En la moneda pactada** (decisión 2026-08-27). El asiento se escribe con el
 * `agreed_amount` y la moneda que se congelaron al aceptar en `7.5`: se le debe
 * lo que se le prometió, en la moneda en que se le prometió. La conversión
 * ocurre **al pagar**, con la tasa de ese día, y se congela en el asiento de
 * pago. Convertir al devengar fijaría el tipo de cambio meses antes de pagar, y
 * una subida del dólar cambiaría lo que se le debe sin que nadie lo decidiera.
 */
final class Ledger
{
    public const DEVENGADO = 'accrued';

    public const PAGABLE = 'payable';

    public const PAGADO = 'paid';

    public const RETENIDO = 'on_hold';

    public const ANULADO = 'void';

    public const DEVENGO = 'earning';

    public const PAGO = 'payment';

    public const REVERSION_DE_PAGO = 'payment_reversal';

    public const AJUSTE = 'adjustment';

    /** @var array<string, string> */
    public const ESTADOS = [
        self::DEVENGADO => 'Devengado',
        self::PAGABLE => 'Pagable',
        self::PAGADO => 'Pagado',
        self::RETENIDO => 'Retenido',
        self::ANULADO => 'Anulado',
    ];

    /**
     * Lo que cuenta como «se le debe».
     *
     * `on_hold` NO está: un asiento retenido es dinero cuyo pago está parado a
     * la espera de una decisión, y meterlo en el saldo del creador sería
     * prometerle algo que todavía no está decidido.
     */
    public const DEBIDOS = [self::DEVENGADO, self::PAGABLE];

    /** El texto que escribe el sistema cuando mueve un asiento sin humano detrás. */
    public const MOTIVO_AUTOMATICO = 'Se cumplieron las cinco condiciones de BR-FIN-003.';

    /**
     * Las cinco condiciones de `BR-FIN-003`, evaluadas para una participación.
     *
     * Devuelve **todas** con su veredicto y no la primera que falla: quien mira
     * por qué un creador no cobra necesita la lista entera, no un motivo cada
     * vez que arregla uno. Es el mismo criterio que
     * `Campanas::loQueFaltaParaSalirDeBorrador()`.
     *
     * @return array<string, array{cumple: bool, dice: string}>
     */
    public static function requisitos(int $participacionId): array
    {
        $p = DB::table('campaign_creators as cc')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->where('cc.id', $participacionId)
            ->first(['cc.id', 'cc.creator_id', 'cc.completed_at', 'cc.status',
                'cc.agreed_amount', 'cc.currency_code', 'c.starts_on']);

        if ($p === null) {
            throw new RuntimeException("No existe la participacion {$participacionId}.");
        }

        $entregables = DB::table('deliverables')->where('campaign_creator_id', $participacionId);

        $total = (clone $entregables)->count();
        $aprobados = (clone $entregables)->whereIn('status', ['approved', 'published', 'verified', 'fulfilled'])->count();

        // «Verificada SI APLICA»: un entregable sin publicacion --porque su
        // formato no la exige-- no puede bloquear el pago para siempre. Se
        // cuentan los que SI la exigen y se comprueban esos.
        $conPublicacion = DB::table('publications as pub')
            ->join('deliverables as d', 'd.id', '=', 'pub.deliverable_id')
            ->where('d.campaign_creator_id', $participacionId);

        $publicaciones = (clone $conPublicacion)->count();
        $verificadas = (clone $conPublicacion)
            ->whereIn('pub.status', ['verified', 'fulfilled'])->count();

        $fiscal = DB::table('creator_tax_profiles')
            ->where('creator_id', $p->creator_id)
            ->where('status', 'approved')
            ->whereNotNull('current_gate')
            ->first(['withholding_status']);

        $medio = DB::table('creator_payment_methods')
            ->where('creator_id', $p->creator_id)
            ->where('status', 'verified')
            ->count();

        return [
            'participacion' => [
                'cumple' => $p->completed_at !== null,
                'dice' => $p->completed_at !== null
                    ? 'La participacion esta completada.'
                    : 'La participacion todavia no esta completada.',
            ],
            'contenido' => [
                'cumple' => $total > 0 && $aprobados === $total,
                'dice' => $total === 0
                    ? 'No hay ningun entregable: no hay nada que pagar todavia.'
                    : "Aprobados {$aprobados} de {$total} entregables.",
            ],
            'publicacion' => [
                // Sin publicaciones el requisito se da por cumplido --«si
                // aplica»--; con ellas, todas tienen que estar verificadas.
                'cumple' => $publicaciones === 0 || $verificadas === $publicaciones,
                'dice' => $publicaciones === 0
                    ? 'Ningun entregable exige publicacion.'
                    : "Verificadas {$verificadas} de {$publicaciones} publicaciones.",
            ],
            'fiscal' => [
                'cumple' => $fiscal !== null && $fiscal->withholding_status !== 'pending_review',
                'dice' => match (true) {
                    $fiscal === null => 'No tiene perfil fiscal aprobado y vigente.',
                    $fiscal->withholding_status === 'pending_review' => 'Su perfil fiscal no dice todavia si se le retiene (DEC-048).',
                    default => 'Perfil fiscal aprobado, con la retencion decidida.',
                },
            ],
            'medio_de_pago' => [
                'cumple' => $medio > 0,
                'dice' => $medio > 0
                    ? 'Tiene medio de pago verificado.'
                    : 'No tiene ningun medio de pago verificado.',
            ],
        ];
    }

    /** @return list<string> lo que falta, con sus palabras */
    public static function loQueFalta(int $participacionId): array
    {
        return array_values(array_map(
            fn (array $r): string => $r['dice'],
            array_filter(self::requisitos($participacionId), fn (array $r): bool => !$r['cumple']),
        ));
    }

    /**
     * Crea el devengo de una participación. **Uno por participación.**
     *
     * No comprueba aquí que no exista otro: lo impide `uq_ledger_devengo` en la
     * base, y se deja que sea ella quien conteste. Una comprobación en PHP sólo
     * protege al que pasa por este método, y `9.4` va a devengar desde un
     * listener de eventos que no tiene por qué pasar.
     */
    public static function devengar(int $participacionId, ?int $autorId = null): string
    {
        $p = DB::table('campaign_creators')->where('id', $participacionId)
            ->first(['id', 'creator_id', 'agreed_amount', 'currency_code', 'accepted_at']);

        if ($p === null) {
            throw new RuntimeException("No existe la participacion {$participacionId}.");
        }

        if ($p->accepted_at === null) {
            throw new RuntimeException(
                'No se devenga lo que el creador no acepto: sin aceptacion no hay importe pactado.',
            );
        }

        if ((float) $p->agreed_amount <= 0) {
            // Una campana gratis es legitima (`7.5` guarda el cero declarado),
            // pero un asiento de cero no es un asiento --`ck_ledger_amount`--.
            throw new RuntimeException(
                'El importe pactado es cero: una colaboracion gratuita no devenga nada.',
            );
        }

        $uuid = (string) Str::uuid();

        DB::table('ledger_entries')->insert([
            'uuid' => $uuid,
            'creator_id' => $p->creator_id,
            'entry_type' => self::DEVENGO,
            'amount' => $p->agreed_amount,
            'currency_code' => $p->currency_code,
            'status' => self::DEVENGADO,
            'campaign_creator_id' => $participacionId,
            'description' => 'Devengo por participacion en campana',
            // Tiempo de NEGOCIO: el hecho economico es la aceptacion, no el
            // instante en que este metodo corrio. `created_at` guarda lo segundo.
            'occurred_at' => $p->accepted_at,
            'created_at' => now(),
            'created_by_user_id' => $autorId,
        ]);

        return $uuid;
    }

    /**
     * Mueve a `payable` si se cumplen las cinco. Devuelve si lo movió.
     *
     * **Automático a propósito** (decisión 2026-08-27): las cinco condiciones ya
     * las firmó alguien una por una —quién verificó la publicación, quién aprobó
     * el perfil fiscal—. Pedir una sexta firma que sólo dice «sí, se cumplen las
     * cinco» es un botón que alguien acaba pulsando sin mirar.
     */
    public static function revisarPagable(int $asientoId): bool
    {
        $a = DB::table('ledger_entries')->where('id', $asientoId)
            ->first(['id', 'status', 'entry_type', 'campaign_creator_id']);

        if ($a === null || $a->status !== self::DEVENGADO || $a->entry_type !== self::DEVENGO) {
            return false;
        }

        if (self::loQueFalta((int) $a->campaign_creator_id) !== []) {
            return false;
        }

        return self::mover($asientoId, self::PAGABLE, self::MOTIVO_AUTOMATICO, null);
    }

    /**
     * Retiene un asiento: sale de la cola de pago y espera a una persona.
     *
     * Es lo que decidió `8.8` para un post caído, aplicado al dinero: el sistema
     * **no descuenta nada**. Retener no es no pagar; es parar hasta que alguien
     * con el expediente delante diga qué se paga.
     */
    public static function retener(int $asientoId, string $motivo, ?int $autorId = null): bool
    {
        return self::mover($asientoId, self::RETENIDO, $motivo, $autorId);
    }

    /** Y lo suelta: vuelve a la cola. Reversible a propósito. */
    public static function liberar(int $asientoId, string $motivo, ?int $autorId = null): bool
    {
        return self::mover($asientoId, self::PAGABLE, $motivo, $autorId);
    }

    public static function anular(int $asientoId, string $motivo, ?int $autorId = null): bool
    {
        return self::mover($asientoId, self::ANULADO, $motivo, $autorId);
    }

    /**
     * El saldo de un creador, por moneda. **Calculado, nunca almacenado.**
     *
     * @param list<string> $estados
     * @return Collection<int, \stdClass>
     */
    public static function saldo(int $creadorId, array $estados = self::DEBIDOS): Collection
    {
        return DB::table('ledger_entries')
            ->where('creator_id', $creadorId)
            ->whereIn('status', $estados)
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->get([
                'currency_code',
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as asientos'),
            ]);
    }

    /**
     * Participaciones **aceptadas y sin devengo**.
     *
     * Desde `9.4` esto debería estar siempre vacío: el listener anota el asiento
     * al aceptar. Existe porque un listener puede fallar —y si falla, el creador
     * queda aceptado y su dinero invisible—, y porque las participaciones
     * aceptadas **antes** de `9.4` no pasaron por él. Es la misma red que `8.1`
     * dejó para los entregables.
     *
     * Las gratuitas quedan fuera: un canje no devenga, y sacarlas aquí evita que
     * el barrido intente lo imposible todos los días.
     *
     * @return Collection<int, \stdClass>
     */
    public static function sinDevengo(int $limite = 500): Collection
    {
        return DB::table('campaign_creators as cc')
            ->leftJoin('ledger_entries as le', function ($j): void {
                $j->on('le.campaign_creator_id', '=', 'cc.id')
                    ->where('le.entry_type', '=', self::DEVENGO)
                    ->where('le.status', '<>', self::ANULADO);
            })
            ->whereNotNull('cc.accepted_at')
            ->where('cc.agreed_amount', '>', 0)
            ->whereNull('le.id')
            ->orderBy('cc.accepted_at')
            ->limit($limite)
            ->get(['cc.id', 'cc.creator_id', 'cc.agreed_amount', 'cc.currency_code', 'cc.accepted_at']);
    }

    /**
     * Los devengos que están esperando algo, con lo que les falta.
     *
     * @return Collection<int, \stdClass>
     */
    public static function pendientes(int $limite = 100): Collection
    {
        return DB::table('ledger_entries as le')
            ->join('campaign_creators as cc', 'cc.id', '=', 'le.campaign_creator_id')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->join('creators as cr', 'cr.id', '=', 'le.creator_id')
            ->where('le.entry_type', self::DEVENGO)
            ->whereIn('le.status', [self::DEVENGADO, self::RETENIDO])
            ->orderBy('le.occurred_at')
            ->limit($limite)
            ->get(['le.id', 'le.uuid', 'le.amount', 'le.currency_code', 'le.status',
                'le.status_reason', 'le.occurred_at', 'le.campaign_creator_id',
                'cr.display_name as creador', 'c.code as campana']);
    }

    /**
     * El cambio de estado, en un solo sitio.
     *
     * La transición válida la comprueba `tg_ledger_estado` en la base, no este
     * método: `9.4` va a mover asientos desde un listener y `9.6` desde el lote
     * de pago, y ninguno tiene por qué pasar por aquí.
     */
    private static function mover(int $asientoId, string $hasta, string $motivo, ?int $autorId): bool
    {
        $fila = DB::table('ledger_entries')->where('id', $asientoId)
            ->first(['status', 'status_changed_at']);

        if ($fila === null || $fila->status === $hasta) {
            return false;
        }

        $antes = (string) $fila->status;

        // `tg_ledger_estado` exige que `status_changed_at` CAMBIE, no solo que
        // exista: si no, el motivo del movimiento anterior --que sigue en la
        // fila-- explicaria este.
        //
        // Se formatea con milisegundos A MANO. La columna es `DATETIME(3)` pero
        // un `Carbon` sin formatear se escribe como `Y-m-d H:i:s` --sin
        // fraccion-- y dos transiciones dentro del mismo SEGUNDO quedaban
        // identicas: la regla rechazaba la segunda y el servicio fallaba en un
        // caso que se da constantemente (pasar a pagable y retener seguido).
        // Y si aun asi coinciden, se empuja un milisegundo.
        $cuando = now()->format('Y-m-d H:i:s.v');

        if ($cuando === (string) $fila->status_changed_at) {
            $cuando = now()->addMillisecond()->format('Y-m-d H:i:s.v');
        }

        DB::table('ledger_entries')->where('id', $asientoId)->update([
            'status' => $hasta,
            'status_changed_at' => $cuando,
            'status_changed_by_user_id' => $autorId ?? Auth::id(),
            'status_reason' => mb_substr($motivo, 0, 255),
        ]);

        DB::table('status_transitions')->insert([
            'entity_type' => 'ledger_entry',
            'entity_id' => $asientoId,
            'from_status' => $antes,
            'to_status' => $hasta,
            'actor_user_id' => $autorId ?? Auth::id(),
            'reason' => mb_substr($motivo, 0, 255),
            'occurred_at' => $cuando,
        ]);

        // A la bitacora solo lo que una PERSONA decide. Las transiciones
        // automaticas son miles y llenarian la bitacora de ruido, tapando
        // justo lo que se busca ahi: quien decidio algo.
        if (($autorId ?? Auth::id()) !== null) {
            Bitacora::registrar(
                accion: 'ledger.status',
                tipoEntidad: 'ledger_entry',
                idEntidad: $asientoId,
                cambios: ['status' => ['antes' => $antes, 'despues' => $hasta]],
            );
        }

        return true;
    }
}
