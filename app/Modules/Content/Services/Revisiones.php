<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Eventos\CorreoPedido;
use App\Shared\Eventos\Eventos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * La revisión de lo que entrega un creador (8.3).
 *
 * ### Sólo las rondas del CLIENTE cuentan contra el precio
 *
 * Decisión de negocio (2026-08-26). Que nuestro equipo le pida al creador rehacer
 * el encuadre antes de enseñárselo a nadie es control de calidad **nuestro**, y
 * cobrárselo al cliente sería cobrarle nuestro propio error.
 *
 * El portal del cliente es `8.5`, así que hoy quien lleva la cuenta traslada el
 * comentario y marca de parte de quién viene — que es exactamente como trabaja
 * una agencia antes de tener portal. En `8.5` el enlace firmado escribe la misma
 * fila sin intermediario, y esta clase no se entera.
 *
 * ### Y son POR ENTREGABLE
 *
 * `revision_rounds_used` estaba en `campaign_creators`, o sea dos rondas por
 * creador: dos correcciones sobre un reel dejaban sin ninguna a las otras cuatro
 * piezas que el cliente también pagó. Bajó a `deliverables` en 8.3.
 *
 * ### Pasarse exige decidir, no sólo avisar
 *
 * Cuando la ronda que se va a pedir es la tercera de dos incluidas, no se puede
 * seguir sin decir **si se cobra o se absorbe** y sin que alguien lo firme. Un
 * aviso que se puede saltar acaba en un cargo que se descubre al facturar, que es
 * tarde para discutirlo con el cliente.
 *
 * El cargo **no** va a `campaign_costs`: eso es lo que gastamos nosotros y resta
 * del margen (`BR-FIN-011`); una ronda de más facturada al cliente es ingreso.
 * Aquí queda la decisión y la pantalla la enseña como pendiente de facturar.
 * Dónde acaba la línea cuando exista facturación es `Q-57`.
 */
final class Revisiones
{
    public const APROBAR = 'approved';

    public const CAMBIOS = 'changes_requested';

    /** 8.2: volver atrás sobre algo ya aprobado. No deshace: añade. */
    public const REABRIR = 'reopened';

    /**
     * Por qué se reabre. Lista cerrada, como los motivos de rechazo de `7.6`.
     *
     * Sirve para contestar «¿por qué se reabren las piezas?» con un número: si el
     * 70 % es *«se aprobó por error»*, el problema es la pantalla de revisión y no
     * el cliente.
     */
    public const MOTIVOS_REAPERTURA = [
        'client_changed' => 'El cliente cambió de opinión',
        'approved_by_mistake' => 'Se aprobó por error',
        'brief_changed' => 'Cambió el brief de la campaña',
        'quality' => 'Se detectó un problema después de aprobar',
        'other' => 'Otro motivo',
    ];

    /** Los estados en los que un entregable está esperando un veredicto. */
    public const ESPERANDO = ['submitted', 'in_review'];

    /** Quién pide la corrección. Cambia si consume ronda. */
    public const LADOS = [
        'platform' => 'Nosotros (control de calidad interno)',
        'client' => 'El cliente',
    ];

    public const FACTURACION = [
        'charge' => 'Se cobra al cliente',
        'absorb' => 'La asumimos nosotros',
    ];

    /**
     * Lo que está esperando revisión, de todas las campañas.
     *
     * Bandeja global y no por campaña: revisar es trabajo por lotes —te sientas y
     * despachas lo que llegó, sea de quien sea—. El seguimiento de `7.7` ya
     * contesta «cómo va esta campaña»; esto contesta «qué me toca hoy».
     *
     * @param array{campana?: ?int, desde_dias?: ?int} $filtros
     * @return Collection<int, \stdClass>
     */
    public static function cola(array $filtros = []): Collection
    {
        $consulta = DB::table('deliverables as d')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->join('creators as cr', 'cr.id', '=', 'cc.creator_id')
            ->join('campaign_requirements as r', 'r.id', '=', 'd.campaign_requirement_id')
            ->join('content_formats as f', 'f.id', '=', 'r.content_format_id')
            ->leftJoin('client_brands as b', 'b.id', '=', 'c.client_brand_id')
            ->whereIn('d.status', self::ESPERANDO);

        if (($filtros['campana'] ?? null) !== null) {
            $consulta->where('c.id', $filtros['campana']);
        }

        if (($filtros['desde_dias'] ?? null) !== null) {
            $consulta->where('d.submitted_at', '<=', now()->subDays((int) $filtros['desde_dias']));
        }

        return $consulta
            // Lo que lleva más esperando, primero. Un creador que entregó hace
            // seis días y no sabe nada es el que está a punto de dejar de
            // contestar los correos.
            ->orderBy('d.submitted_at')
            ->get([
                'd.id', 'd.uuid', 'd.status', 'd.due_on', 'd.sequence_number',
                'd.submitted_at', 'd.revision_rounds_used',
                'c.id as campana_id', 'c.uuid as campana_uuid', 'c.name as campana',
                'c.included_revision_rounds', 'b.name as marca',
                'cr.display_name as creador', 'f.code as formato',
            ]);
    }

    /**
     * El entregable con su campaña y su brief, o `null`.
     *
     * Una sola consulta y no tres: la pantalla de revisión necesita las tres
     * cosas a la vez, y quien llama no tiene por qué saber de dónde sale cada
     * una.
     */
    public static function entregable(string $uuid): ?object
    {
        return DB::table('deliverables as d')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->join('creators as cr', 'cr.id', '=', 'cc.creator_id')
            ->join('campaign_requirements as r', 'r.id', '=', 'd.campaign_requirement_id')
            ->join('content_formats as f', 'f.id', '=', 'r.content_format_id')
            ->leftJoin('client_brands as b', 'b.id', '=', 'c.client_brand_id')
            ->where('d.uuid', $uuid)
            ->first([
                'd.id', 'd.uuid', 'd.status', 'd.due_on', 'd.sequence_number',
                'd.submitted_at', 'd.revision_rounds_used', 'd.approved_at',
                'd.approved_version_id',
                'cc.id as participacion_id',
                'c.id as campana_id', 'c.uuid as campana_uuid', 'c.name as campana',
                'c.included_revision_rounds', 'b.name as marca',
                'cr.display_name as creador', 'f.code as formato',
                'r.notes', 'r.hashtags', 'r.mentions',
            ]);
    }

    /**
     * La versión que toca revisar: la última.
     *
     * `tg_cvw_ultima_version` lo impone en la base. Un veredicto sobre contenido
     * que el creador ya reemplazó no lo lee nadie.
     */
    public static function ultimaVersion(int $entregableId): ?object
    {
        return DB::table('deliverable_versions')
            ->where('deliverable_id', $entregableId)
            ->orderByDesc('version_number')
            ->first();
    }

    /**
     * La versión aprobada de un entregable, o `null`.
     *
     * Sale del **puntero** y no de recorrer el historial: `8.6` publica lo
     * aprobado y `8.7` archiva evidencia de eso, y las dos tienen que leer el
     * mismo dato que la pantalla enseña.
     */
    public static function versionAprobada(int $entregableId): ?object
    {
        return DB::table('deliverables as d')
            ->join('deliverable_versions as v', 'v.id', '=', 'd.approved_version_id')
            ->where('d.id', $entregableId)
            ->first(['v.id', 'v.uuid', 'v.version_number', 'v.external_url',
                'v.caption', 'v.file_id', 'v.submitted_at']);
    }

    /**
     * Por qué este entregable **no** se puede reabrir, o `null`.
     *
     * Sólo se reabre lo **aprobado**. Lo publicado y lo verificado no —de ellos
     * cuelga evidencia y permanencia (`8.6`, `8.7`)— y lo cancelado tampoco: eso
     * no es volver atrás, es resucitar.
     */
    public static function vetoParaReabrir(object $entregable, string $motivo, ?string $nota): ?string
    {
        if ((string) $entregable->status !== 'approved') {
            return sprintf('Este entregable esta en «%s»: solo se reabre lo aprobado.', $entregable->status);
        }

        if (!array_key_exists($motivo, self::MOTIVOS_REAPERTURA)) {
            return 'Diga por que se reabre, con uno de los motivos de la lista.';
        }

        if ($motivo === 'other' && mb_strlen(trim((string) $nota)) < 10) {
            // «Otro motivo» sin explicación es la casilla que se marca para poder
            // seguir, y entonces la lista deja de contestar nada.
            return 'Si el motivo es «otro», hay que explicarlo.';
        }

        return null;
    }

    /**
     * Reabre un entregable aprobado. Devuelve el uuid de la fila.
     *
     * **No deshace la aprobación**: la deja donde estaba, en el historial, y añade
     * una fila que dice por qué se volvió atrás. Es lo mismo que hace `3.11` con
     * la anulación de un perfil fiscal, y por la misma razón — el registro tiene
     * que poder contestar «¿qué pasó?», no sólo «¿cómo está ahora?».
     *
     * El puntero **se limpia**: mientras esté reabierto no hay versión aprobada,
     * y `ck_del_approved_version` no dejaría que quedara una a medias.
     */
    public static function reabrir(
        object $entregable,
        string $motivo,
        ?string $nota,
        ?int $usuarioId,
        ?string $ip,
    ): string {
        $empaquetada = is_string($ip) ? inet_pton($ip) : false;
        $uuid = (string) Str::uuid();
        $texto = self::MOTIVOS_REAPERTURA[$motivo].(($nota ?? '') !== '' ? ' — '.$nota : '');
        $versionId = (int) $entregable->approved_version_id;

        DB::transaction(function () use ($entregable, $versionId, $texto, $usuarioId, $empaquetada, $uuid): void {
            // La fila ANTES del UPDATE: `tg_cvw_entregable_abierto` sólo deja
            // pasar un `reopened` mientras el entregable siga en `approved`.
            DB::table('content_reviews')->insert([
                'uuid' => $uuid,
                'deliverable_version_id' => $versionId,
                'reviewer_user_id' => $usuarioId,
                'reviewer_side' => 'platform',
                'outcome' => self::REABRIR,
                'comments' => $texto,
                // Reabrir NO gasta ronda. La ronda la gasta pedir un cambio, y
                // aquí todavía no se ha pedido ninguno.
                'consumes_round' => 0,
                'reviewed_at' => now(),
                'reviewed_ip' => $empaquetada === false ? null : $empaquetada,
                'created_at' => now(),
            ]);

            DB::table('deliverables')->where('id', $entregable->id)->update([
                'status' => 'in_review',
                'approved_at' => null,
                'approved_by_user_id' => null,
                'approved_version_id' => null,
                'updated_at' => now(),
            ]);
        });

        Bitacora::registrar(
            accion: 'deliverable.reopened',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $entregable->id,
            cambios: [
                'status' => ['antes' => 'approved', 'despues' => 'in_review'],
                'motivo' => ['antes' => null, 'despues' => $motivo],
            ],
        );

        Eventos::ocurrio(
            nombre: 'deliverable.reopened',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $entregable->id,
            // El motivo de lista cerrada sí; la nota libre no, que puede llevar
            // el nombre de una persona del cliente.
            payload: ['motivo' => $motivo, 'version' => $versionId],
        );

        return $uuid;
    }

    /**
     * El historial de veredictos de un entregable, el más reciente primero.
     *
     * @return Collection<int, \stdClass>
     */
    public static function historial(int $entregableId): Collection
    {
        return DB::table('content_reviews as rv')
            ->join('deliverable_versions as v', 'v.id', '=', 'rv.deliverable_version_id')
            ->leftJoin('users as u', 'u.id', '=', 'rv.reviewer_user_id')
            ->leftJoin('users as a', 'a.id', '=', 'rv.authorized_by_user_id')
            ->where('v.deliverable_id', $entregableId)
            // `rv.id` de desempate y no por gusto: dos veredictos pueden caer en
            // el MISMO milisegundo --pasa en las pruebas y puede pasar con dos
            // revisores a la vez-- y entonces «el mas reciente» sale a suertes.
            // `reviewed_at` va primero porque puede venir de fuera: la revision
            // del cliente que alguien traslada lleva la fecha en que el cliente
            // la dijo, no la de cuando se tecleo.
            ->orderByDesc('rv.reviewed_at')
            ->orderByDesc('rv.id')
            ->get([
                'rv.uuid', 'rv.outcome', 'rv.reviewer_side', 'rv.comments',
                'rv.consumes_round', 'rv.over_included', 'rv.billing_decision',
                'rv.reviewed_at', 'v.version_number',
                'u.name as revisor', 'a.name as autorizador',
            ]);
    }

    /**
     * Cómo van las rondas de un entregable.
     *
     * @return array{incluidas: int, usadas: int, quedan: int, agotadas: bool}
     */
    public static function rondas(object $entregable): array
    {
        $incluidas = (int) $entregable->included_revision_rounds;
        $usadas = (int) $entregable->revision_rounds_used;

        return [
            'incluidas' => $incluidas,
            'usadas' => $usadas,
            // Nunca negativo hacia fuera: «quedan -1» no significa nada para
            // quien lee la pantalla. Lo que informa es `agotadas`.
            'quedan' => max(0, $incluidas - $usadas),
            'agotadas' => $usadas >= $incluidas,
        ];
    }

    /**
     * Por qué este veredicto **no** se puede emitir, o lista vacía.
     *
     * Devuelve **todos** los motivos, igual que `Entregables::vetoParaEntregar()`.
     *
     * @param array{outcome?: ?string, reviewer_side?: ?string, comments?: ?string,
     *              billing_decision?: ?string} $datos
     * @return list<string>
     */
    public static function vetoParaRevisar(object $entregable, array $datos): array
    {
        $motivos = [];

        if (!in_array((string) $entregable->status, self::ESPERANDO, true)) {
            return [sprintf('Este entregable esta en «%s» y ya no admite veredictos.', $entregable->status)];
        }

        if (self::ultimaVersion((int) $entregable->id) === null) {
            return ['Este entregable no tiene ninguna version que revisar.'];
        }

        $veredicto = (string) ($datos['outcome'] ?? '');
        $lado = (string) ($datos['reviewer_side'] ?? '');
        $comentario = trim((string) ($datos['comments'] ?? ''));

        if ($veredicto === self::CAMBIOS && mb_strlen($comentario) < 10) {
            // `ck_cvw_comments` lo impide en la base. Se dice con palabras antes
            // de que la base lo diga con un 3819.
            $motivos[] = 'Para pedir cambios hay que decir cuales: escriba que tiene que cambiar.';
        }

        // La ronda de mas: sólo cuando la pide el cliente y ya no quedan.
        if (self::consumeRonda($veredicto, $lado) && self::rondas($entregable)['agotadas']) {
            $decision = (string) ($datos['billing_decision'] ?? '');

            if (!array_key_exists($decision, self::FACTURACION)) {
                $motivos[] = sprintf(
                    'Esta campana incluye %d rondas de correccion y esta pieza ya las gasto. '
                    .'Diga si esta se le cobra al cliente o la asumimos nosotros.',
                    (int) $entregable->included_revision_rounds,
                );
            }
        }

        return $motivos;
    }

    /**
     * ¿Este veredicto consume una de las rondas incluidas en el precio?
     *
     * Sólo la corrección —`ck_cvw_round`— y sólo la del cliente. Está aquí y no
     * repartido por los `if` de quien llama porque es **la** regla de dinero de
     * esta iteración y tiene que poder leerse de una vez.
     */
    public static function consumeRonda(string $veredicto, string $lado): bool
    {
        return $veredicto === self::CAMBIOS && $lado === 'client';
    }

    /**
     * Emite el veredicto. Devuelve el uuid de la revisión.
     *
     * Todo dentro de una transacción: la revisión, el contador de rondas y el
     * estado del entregable cuentan la misma historia y no pueden quedarse a
     * medias. El contador se sube **con la fila bloqueada** — dos revisores
     * simultáneos sobre la misma pieza calcularían la misma ronda.
     *
     * @param array{outcome: string, reviewer_side: string, comments?: ?string,
     *              billing_decision?: ?string} $datos
     */
    public static function emitir(
        object $entregable,
        object $version,
        array $datos,
        ?int $usuarioId,
        ?string $ip,
    ): string {
        $veredicto = (string) $datos['outcome'];
        $lado = (string) $datos['reviewer_side'];
        $consume = self::consumeRonda($veredicto, $lado);
        $empaquetada = is_string($ip) ? inet_pton($ip) : false;
        $uuid = (string) Str::uuid();

        $exceso = DB::transaction(function () use (
            $entregable, $version, $datos, $usuarioId, $veredicto, $lado,
            $consume, $empaquetada, $uuid,
        ): bool {
            $usadas = (int) DB::table('deliverables')
                ->where('id', $entregable->id)
                ->lockForUpdate()
                ->value('revision_rounds_used');

            $exceso = $consume && $usadas >= (int) $entregable->included_revision_rounds;
            $decision = $exceso ? (string) ($datos['billing_decision'] ?? '') : null;

            DB::table('content_reviews')->insert([
                'uuid' => $uuid,
                'deliverable_version_id' => $version->id,
                // La del cliente la traslada alguien nuestro hasta 8.5, y esa
                // persona queda anotada igual: quien la escribio responde de ella.
                'reviewer_user_id' => $usuarioId,
                'reviewer_side' => $lado,
                'outcome' => $veredicto,
                'comments' => $datos['comments'] ?? null,
                'consumes_round' => $consume ? 1 : 0,
                'over_included' => $exceso ? 1 : 0,
                'billing_decision' => $decision,
                'authorized_by_user_id' => $exceso ? $usuarioId : null,
                'reviewed_at' => now(),
                'reviewed_ip' => $empaquetada === false ? null : $empaquetada,
                'created_at' => now(),
            ]);

            $cambios = ['updated_at' => now()];

            if ($consume) {
                $cambios['revision_rounds_used'] = $usadas + 1;
            }

            if ($veredicto === self::APROBAR) {
                $cambios['status'] = 'approved';
                $cambios['approved_at'] = now();
                $cambios['approved_by_user_id'] = $usuarioId;
                // 8.2: QUE version se aprobo. `ck_del_approved_version` exige que
                // vaya con `approved_at`, asi que las tres van en el mismo UPDATE
                // y no hay un instante en que la fila diga «aprobado» sin decir
                // que.
                $cambios['approved_version_id'] = $version->id;
            } else {
                // El creador tiene que volver a entregar, así que la pieza
                // vuelve a estar abierta para él (`Entregables::ABIERTOS`).
                $cambios['status'] = 'changes_requested';
            }

            DB::table('deliverables')->where('id', $entregable->id)->update($cambios);

            return $exceso;
        });

        Bitacora::registrar(
            accion: 'deliverable.reviewed',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $entregable->id,
            cambios: [
                'veredicto' => ['antes' => $entregable->status, 'despues' => $veredicto],
                'lado' => ['antes' => null, 'despues' => $lado],
            ],
        );

        Eventos::ocurrio(
            nombre: $veredicto === self::APROBAR ? 'deliverable.approved' : 'deliverable.changes_requested',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $entregable->id,
            // Sin el comentario: `domain_events` PERSISTE su payload y el texto
            // de una correccion puede llevar datos del cliente. El hecho se
            // registra; el contenido vive en `content_reviews`.
            payload: [
                'version' => (int) $version->version_number,
                'lado' => $lado,
                // Un `bool` no cabe en el payload de `Eventos::ocurrio()`, que
                // es `array<string, float|int|string>` porque acaba en un JSON
                // que hay que poder leer dentro de diez meses. Un 0/1 dice lo
                // mismo y no obliga a ensanchar la firma de Shared por un caso.
                'ronda_de_mas' => $exceso ? 1 : 0,
            ],
        );

        if ($veredicto === self::CAMBIOS) {
            self::avisarAlCreador($entregable, $datos);
        }

        return $uuid;
    }

    /**
     * El correo de «hay que retocarlo», al creador.
     *
     * Sólo la corrección manda correo, y no la aprobación: una corrección trae
     * una fecha límite y una acción, y si el creador no la ve a tiempo el retraso
     * es de la campaña. Una aprobación no le pide nada y la ve en su portal.
     *
     * `CorreoPedido` y no `EventoOcurrido` porque `Content` no puede conocer a
     * `Communication` —`deptrac.yaml`— y porque este payload lleva el texto de la
     * corrección, que no tiene por qué quedarse guardado en `domain_events`.
     *
     * @param array{comments?: ?string} $datos
     */
    private static function avisarAlCreador(object $entregable, array $datos): void
    {
        $creador = DB::table('deliverables as d')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->join('creators as cr', 'cr.id', '=', 'cc.creator_id')
            ->where('d.id', $entregable->id)
            ->first(['cr.display_name', 'cr.email', 'cr.locale']);

        if ($creador === null || ($creador->email ?? null) === null) {
            return;
        }

        Event::dispatch(new CorreoPedido(
            codigo: 'content.changes_requested',
            destinatario: (string) $creador->email,
            variables: [
                'nombre' => (string) $creador->display_name,
                'campana' => (string) $entregable->campana,
                'formato' => (string) $entregable->formato,
                'comentario' => (string) ($datos['comments'] ?? ''),
                'limite' => date('d/m/Y', strtotime((string) $entregable->due_on)),
                'enlace' => route('entregas.mias'),
            ],
            idioma: (string) ($creador->locale ?: 'es'),
            tipoRelacionado: 'deliverable',
            idRelacionado: (int) $entregable->id,
        ));
    }

    /**
     * Las rondas cobradas de más que todavía nadie facturó, de una campaña.
     *
     * No hay tabla de cargos al cliente todavía —`Q-57`—, así que esto **es** el
     * registro: una lista que la pantalla de la campaña enseña para que no se
     * facture sin ella.
     *
     * @return Collection<int, \stdClass>
     */
    public static function cargosPendientes(int $campanaId): Collection
    {
        return DB::table('content_reviews as rv')
            ->join('deliverable_versions as v', 'v.id', '=', 'rv.deliverable_version_id')
            ->join('deliverables as d', 'd.id', '=', 'v.deliverable_id')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->join('creators as cr', 'cr.id', '=', 'cc.creator_id')
            ->leftJoin('users as a', 'a.id', '=', 'rv.authorized_by_user_id')
            ->where('cc.campaign_id', $campanaId)
            ->where('rv.over_included', 1)
            ->where('rv.billing_decision', 'charge')
            ->orderByDesc('rv.reviewed_at')
            ->get([
                'rv.uuid', 'rv.reviewed_at', 'rv.comments',
                'd.uuid as entregable_uuid', 'd.sequence_number',
                'cr.display_name as creador', 'a.name as autorizador',
            ]);
    }

    /**
     * Cuántas vueltas costó trabajar con un creador, en una campaña.
     *
     * Se calcula, no se guarda. Un contador denormalizado que nadie recalcula se
     * desvía en silencio, y de éste cuelga el Creator Score.
     */
    public static function vueltasDe(int $participacionId): int
    {
        return (int) DB::table('content_reviews as rv')
            ->join('deliverable_versions as v', 'v.id', '=', 'rv.deliverable_version_id')
            ->join('deliverables as d', 'd.id', '=', 'v.deliverable_id')
            ->where('d.campaign_creator_id', $participacionId)
            ->where('rv.outcome', self::CAMBIOS)
            ->count();
    }
}
