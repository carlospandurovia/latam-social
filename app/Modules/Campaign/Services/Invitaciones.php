<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Eventos\CorreoPedido;
use App\Shared\Eventos\Eventos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Invitar a un creador, y esperar su respuesta (7.6).
 *
 * ### El creador contesta él mismo, por enlace
 *
 * Decisión de negocio (2026-08-26). Su portal (`F6`) sigue bloqueado por `T-09`
 * —el texto de los términos—, así que no hay pantalla donde entre a decidir. La
 * alternativa era que un operador tecleara *«dijo que sí por WhatsApp»*, y eso
 * convierte una **aceptación** en la palabra de un tercero.
 *
 * Con enlace hay una IP, una hora y un token que sólo él tenía. Es la misma
 * pieza de `5.9`, que ya estaba construida y probada: token de 32 bytes del
 * generador criptográfico, del que se guarda **la huella y no el token**.
 *
 * ### El importe se copia al invitar
 *
 * `BR-CREATOR-008` congela el precio **al aceptar**. Entre el envío y la
 * respuesta, `agreed_amount` se podía mover — y el creador aceptaría una cifra
 * que nunca vio. La invitación guarda el importe con el que salió, y
 * `tg_ccr_monto_con_invitacion` impide moverlo mientras la invitación viva.
 *
 * Renegociar no está prohibido: hay que **anular la invitación** y mandar otra,
 * que es lo que pasa de verdad cuando se sube una oferta.
 *
 * ### Rechazar no cierra la puerta
 *
 * Decisión de negocio (2026-08-26): se puede reinvitar, y **queda constancia de
 * las dos**. La constancia vive en `invitations` —una fila por ronda, con su
 * motivo— que es justo para lo que la Fase 2 diseñó la tabla: *«cuántas veces
 * hubo que insistir alimenta el Creator Score»*.
 *
 * El motivo del rechazo es de lista cerrada para poder contestar *«¿por qué nos
 * dicen que no?»*. **No decide quién se puede reinvitar**: eso era la otra
 * opción y se descartó.
 */
final class Invitaciones
{
    /** Los estados de participación desde los que se puede (re)invitar. */
    public const INVITABLES = ['shortlisted', 'declined', 'expired'];

    /**
     * Por qué un creador dice que no. Cerrado, para poder sumarlo.
     *
     * @var array<string, string>
     */
    public const MOTIVOS = [
        'amount' => 'El importe no me compensa',
        'dates' => 'No me cuadran las fechas',
        'brand' => 'No trabajo con esta marca o categoria',
        'workload' => 'No tengo hueco ahora mismo',
        'other' => 'Otro motivo',
    ];

    /**
     * Por qué un enlace de invitación no sirve. En texto, porque se enseñan.
     *
     * @var array<string, string>
     */
    public const FALLOS = [
        'no_existe' => 'Esta invitacion no existe. Puede que hayas copiado el enlace a medias, '
            .'o que te hayamos mandado una mas reciente.',
        'caducada' => 'El plazo para contestar esta invitacion ya paso. Si sigues interesado, '
            .'escribenos y te mandamos otra.',
        'respondida' => 'Ya contestaste a esta invitacion. Si quieres cambiar tu respuesta, escribenos.',
        'anulada' => 'Esta invitacion se anulo porque te mandamos otra despues. '
            .'Usa el ultimo correo que hayas recibido.',
        'campana_cerrada' => 'Esta campana ya termino. Gracias de todas formas.',
    ];

    // ------------------------------------------------------------- invitar

    /**
     * Por qué esta participación no se puede invitar, o lista vacía.
     *
     * Devuelve **todos** los motivos: quien monta una campaña prefiere una lista
     * de tres cosas que arreglar a tres visitas.
     *
     * @return list<string>
     */
    public static function vetoParaInvitar(object $campana, object $participacion): array
    {
        $motivos = [];

        if ($campana->closed_at !== null) {
            return ['esta campana ya esta cerrada: no se invita a nadie mas'];
        }

        // Confirmada, no en borrador. Invitar es comprometerse con una persona
        // ajena a la empresa, y una campana en borrador todavia puede no
        // existir manana.
        if ($campana->confirmed_at === null) {
            $motivos[] = 'la campana no esta confirmada todavia: invitar a alguien a una campana '
                .'en borrador es prometer algo que aun se puede caer';
        }

        if (!in_array((string) $participacion->status, self::INVITABLES, true)) {
            $motivos[] = sprintf(
                'esa participacion esta en «%s»: solo se invita desde %s',
                $participacion->status,
                implode(', ', self::INVITABLES),
            );
        }

        // El importe. `tg_ccr_compromiso` (7.5) ya lo impide en la base; aqui se
        // dice con palabras y junto a los demas motivos, en vez de dejar que
        // reviente el INSERT con un SIGNAL.
        if ((float) $participacion->agreed_amount <= 0 && (int) $campana->is_gratis !== 1) {
            $motivos[] = 'no se le ha fijado importe: no se invita a nadie sin decirle cuanto se le paga '
                .'(BR-CREATOR-008). Si la campana es un canje, marquela como gratuita';
        }

        if (self::viva((int) $participacion->id) !== null) {
            $motivos[] = 'ya tiene una invitacion viva: espere a que conteste, o anulela antes de '
                .'mandarle otra';
        }

        $correo = DB::table('creators')->where('id', $participacion->creator_id)->value('email');

        if ((string) $correo === '') {
            $motivos[] = 'ese creador no tiene correo, y la invitacion se manda por correo';
        }

        return $motivos;
    }

    /**
     * Manda la invitación y devuelve el token, que es la única vez que existe.
     *
     * Reinvitar a alguien que rechazó **limpia `declined_at`** de la
     * participación: esa columna dice cuándo se rechazó la ronda **actual**, y
     * dejarla puesta haría que un `accepted` posterior conviviera con una fecha
     * de rechazo. El historial completo está en `invitations`, con una fila por
     * ronda, que es donde tiene que estar.
     */
    public static function invitar(object $campana, object $participacion, ?int $invitadorId = null): string
    {
        $creador = DB::table('creators')->where('id', $participacion->creator_id)
            ->first(['id', 'display_name', 'email', 'locale']);

        $token = bin2hex(random_bytes(32));
        $horas = max(1, (int) ($campana->invitation_hours ?: 72));
        $caduca = now()->addHours($horas);

        $invitacionId = DB::transaction(function () use (
            $participacion, $token, $caduca, $invitadorId
        ): int {
            // Cualquier invitacion viva anterior muere primero. `uq_inv_viva` lo
            // impediria de todas formas, pero con un 1062 en vez de con una
            // decision: aqui se toma a proposito.
            self::anular((int) $participacion->id, 'sustituida');

            $id = (int) DB::table('invitations')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'campaign_creator_id' => $participacion->id,
                'invited_by_user_id' => $invitadorId,
                'channel' => 'email',
                'token_hash' => hash('sha256', $token),
                'sent_at' => now(),
                'expires_at' => $caduca,
                // Lo que se le OFRECIO, copiado. Ver la cabecera de la clase.
                'amount_snapshot' => $participacion->agreed_amount,
                'currency_snapshot' => $participacion->currency_code,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('campaign_creators')->where('id', $participacion->id)->update([
                'status' => 'invited',
                'invited_at' => now(),
                // Se limpia la ronda anterior. Ver la cabecera del metodo.
                'declined_at' => null,
                'updated_at' => now(),
            ]);

            return $id;
        });

        Bitacora::registrar(
            accion: 'campaign_creator.invited',
            tipoEntidad: 'campaign_creator',
            idEntidad: (int) $participacion->id,
            cambios: [
                'status' => ['antes' => $participacion->status, 'despues' => 'invited'],
                'amount_snapshot' => ['antes' => null, 'despues' => (string) $participacion->agreed_amount],
                'expires_at' => ['antes' => null, 'despues' => $caduca->toDateTimeString()],
            ],
        );

        // El HECHO, sin el token.
        Eventos::ocurrio(
            nombre: 'campaign_creator.invited',
            tipoEntidad: 'campaign_creator',
            idEntidad: (int) $participacion->id,
            payload: [
                'campaign_id' => (int) $campana->id,
                'creator_id' => (int) $participacion->creator_id,
                'invitation_id' => $invitacionId,
                'expires_at' => $caduca->toDateTimeString(),
            ],
        );

        // Y el enlace, sólo por memoria.
        Event::dispatch(new CorreoPedido(
            codigo: 'campaign.invitation',
            destinatario: (string) $creador->email,
            variables: [
                'nombre' => (string) $creador->display_name,
                'campana' => (string) $campana->name,
                'importe' => self::importe($participacion),
                'enlace' => route('invitacion.ver', ['token' => $token]),
                'caduca' => $caduca->format('d/m/Y H:i'),
                'horas' => $horas,
            ],
            idioma: (string) ($creador->locale ?: 'es'),
            tipoRelacionado: 'campaign_creator',
            idRelacionado: (int) $participacion->id,
        ));

        return $token;
    }

    // -------------------------------------------------------- el enlace

    /**
     * ¿Sirve este token? Devuelve el motivo cuando no, para poder decirlo.
     *
     * @return array{ok: bool, motivo: ?string, invitacion: ?object}
     */
    public static function validar(string $token): array
    {
        $fila = self::porToken($token);

        if ($fila === null) {
            return ['ok' => false, 'motivo' => 'no_existe', 'invitacion' => null];
        }

        // La causa MAS ESPECIFICA primero. Una invitacion respondida que ademas
        // caduco es una respondida, y esa es la respuesta que le interesa a
        // quien pregunta «.llego a contestar?».
        $motivo = match (true) {
            $fila->responded_at !== null => 'respondida',
            // Anulada por CADUCIDAD no es lo mismo que anulada porque llego
            // otra, aunque en la tabla las dos sean un `revoked_at`. La primera
            // version decia «te mandamos otra despues» a quien simplemente se
            // habia pasado del plazo: le hacia buscar en su buzon un correo que
            // no existe. El motivo lo distingue.
            $fila->revoked_at !== null => $fila->revoked_reason === 'caducada' ? 'caducada' : 'anulada',
            $fila->closed_at !== null => 'campana_cerrada',
            $fila->expires_at < now()->format('Y-m-d H:i:s') => 'caducada',
            default => null,
        };

        return ['ok' => $motivo === null, 'motivo' => $motivo, 'invitacion' => $fila];
    }

    /** Deja constancia de que el creador abrió el enlace. No decide nada. */
    public static function marcarAbierta(int $invitacionId): void
    {
        DB::table('invitations')
            ->where('id', $invitacionId)
            ->whereNull('opened_at')
            ->update(['opened_at' => now(), 'updated_at' => now()]);
    }

    /**
     * El creador acepta.
     *
     * @return array{ok: bool, motivo: ?string}
     */
    public static function aceptar(string $token, ?string $ip): array
    {
        return self::responder($token, 'accepted', null, null, $ip);
    }

    /**
     * El creador rechaza, diciendo por qué.
     *
     * @return array{ok: bool, motivo: ?string}
     */
    public static function rechazar(string $token, string $motivo, ?string $nota, ?string $ip): array
    {
        if (!array_key_exists($motivo, self::MOTIVOS)) {
            // Un motivo que no esta en la lista no se guarda como `other`: eso
            // seria inventarse lo que dijo el creador. `ck_inv_reason_valido` lo
            // rechazaria de todas formas; aqui se contesta con palabras.
            return ['ok' => false, 'motivo' => 'no_existe'];
        }

        return self::responder($token, 'declined', $motivo, $nota, $ip);
    }

    // ------------------------------------------------------------ anular

    /**
     * Anula las invitaciones vivas de una participación. Devuelve cuántas.
     *
     * Nunca borra: una invitación que existió es evidencia de lo que se ofreció
     * y cuándo. Lo que cambia es que `viva_gate` pasa a `NULL` y deja libre el
     * hueco del índice único.
     */
    public static function anular(int $participacionId, string $motivo): int
    {
        return DB::table('invitations')
            ->where('campaign_creator_id', $participacionId)
            ->whereNull('responded_at')
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => $motivo,
                'updated_at' => now(),
            ]);
    }

    /**
     * Cierra las invitaciones cuyo plazo pasó. Devuelve cuántas participaciones movió.
     *
     * **Caducar no se escribe solo**, y por eso hace falta esto: `expires_at`
     * comparado con el reloj ya impide que el creador conteste, pero la
     * participación seguiría en `invited` para siempre y su importe seguiría
     * contando contra el presupuesto de la campaña. El cupo del mercado nunca se
     * liberaría.
     *
     * Lo llama `invitaciones:caducar` desde el planificador.
     */
    public static function caducar(): int
    {
        $vencidas = DB::table('invitations as i')
            ->join('campaign_creators as cc', 'cc.id', '=', 'i.campaign_creator_id')
            ->where('i.viva_gate', 1)
            ->where('i.expires_at', '<', now())
            ->where('cc.status', 'invited')
            ->get(['i.id', 'i.campaign_creator_id']);

        foreach ($vencidas as $vencida) {
            DB::transaction(function () use ($vencida): void {
                DB::table('invitations')->where('id', $vencida->id)->update([
                    'revoked_at' => now(),
                    'revoked_reason' => 'caducada',
                    'updated_at' => now(),
                ]);

                DB::table('campaign_creators')->where('id', $vencida->campaign_creator_id)->update([
                    'status' => 'expired',
                    'updated_at' => now(),
                ]);
            });

            Eventos::ocurrio(
                nombre: 'campaign_creator.invitation_expired',
                tipoEntidad: 'campaign_creator',
                idEntidad: (int) $vencida->campaign_creator_id,
                payload: ['invitation_id' => (int) $vencida->id],
            );
        }

        return $vencidas->count();
    }

    // ------------------------------------------------------------ lectura

    /**
     * Por qué no se puede tocar el importe de esta participación ahora, o `null`.
     *
     * Es la mitad con palabras de `tg_ccr_monto_con_invitacion`. La base lo
     * impide igual —de ahí sale lo que se le paga a una persona y tiene que
     * sobrevivir a un mantenimiento—, pero un operador merece leer qué hacer en
     * vez de un `SIGNAL`.
     */
    public static function vetoPorInvitacionViva(int $participacionId): ?string
    {
        $viva = self::viva($participacionId);

        if ($viva === null) {
            return null;
        }

        return sprintf(
            'Ese creador tiene una invitacion viva por %s hasta el %s: esta mirando esa cifra. '
            .'Anule la invitacion y mandele otra con el importe nuevo (BR-CREATOR-008).',
            number_format((float) $viva->amount_snapshot, 2),
            $viva->expires_at,
        );
    }

    /** La invitación viva de una participación, o `null`. */
    public static function viva(int $participacionId): ?object
    {
        return DB::table('invitations')
            ->where('campaign_creator_id', $participacionId)
            ->where('viva_gate', 1)
            ->first(['id', 'uuid', 'sent_at', 'expires_at', 'opened_at', 'amount_snapshot']);
    }

    /**
     * Todas las rondas de una participación, la más reciente primero.
     *
     * **Ésta es la constancia** de que hubo un rechazo antes de una aceptación.
     *
     * @return Collection<int, \stdClass>
     */
    public static function historial(int $participacionId): Collection
    {
        return DB::table('invitations as i')
            ->leftJoin('users as u', 'u.id', '=', 'i.invited_by_user_id')
            ->where('i.campaign_creator_id', $participacionId)
            ->orderByDesc('i.id')
            ->get([
                'i.uuid', 'i.sent_at', 'i.expires_at', 'i.opened_at', 'i.responded_at',
                'i.response', 'i.decline_reason', 'i.decline_note', 'i.amount_snapshot',
                'i.currency_snapshot', 'i.revoked_at', 'i.revoked_reason', 'u.name as invitador',
            ]);
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * @return array{ok: bool, motivo: ?string}
     */
    private static function responder(
        string $token,
        string $respuesta,
        ?string $motivo,
        ?string $nota,
        ?string $ip,
    ): array {
        $empaquetada = is_string($ip) ? inet_pton($ip) : false;

        $resultado = DB::transaction(function () use (
            $token, $respuesta, $motivo, $nota, $empaquetada
        ): array {
            // `lockForUpdate`: el doble clic de siempre no puede contestar dos
            // veces. Sin el bloqueo las dos peticiones leerian
            // `responded_at IS NULL` y las dos escribirian.
            $fila = DB::table('invitations as i')
                ->join('campaign_creators as cc', 'cc.id', '=', 'i.campaign_creator_id')
                ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
                ->where('i.token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first([
                    'i.id', 'i.campaign_creator_id', 'i.expires_at', 'i.responded_at',
                    'i.revoked_at', 'i.revoked_reason', 'i.amount_snapshot',
                    'cc.agreed_amount', 'cc.status as estado', 'c.closed_at',
                ]);

            if ($fila === null) {
                return ['ok' => false, 'motivo' => 'no_existe', 'participacionId' => null];
            }

            $fallo = match (true) {
                $fila->responded_at !== null => 'respondida',
                $fila->revoked_at !== null => $fila->revoked_reason === 'caducada' ? 'caducada' : 'anulada',
                $fila->closed_at !== null => 'campana_cerrada',
                $fila->expires_at < now()->format('Y-m-d H:i:s') => 'caducada',
                // El importe cambio desde que salio la invitacion. No deberia
                // poder pasar --`tg_ccr_monto_con_invitacion` lo impide-- y por
                // eso mismo se comprueba: si algun dia el disparador falta, esto
                // es lo que evita que alguien acepte una cifra que no vio.
                (float) $fila->amount_snapshot !== (float) $fila->agreed_amount => 'anulada',
                default => null,
            };

            if ($fallo !== null) {
                return ['ok' => false, 'motivo' => $fallo, 'participacionId' => null];
            }

            DB::table('invitations')->where('id', $fila->id)->update([
                'responded_at' => now(),
                'response' => $respuesta,
                'decline_reason' => $motivo,
                'decline_note' => $nota,
                // `ck_inv_responded_ip` la exige. Si el servidor no la da, no se
                // inventa: se guarda la de bucle local, que es lo que se sabe.
                'responded_ip' => $empaquetada === false ? inet_pton('127.0.0.1') : $empaquetada,
                'updated_at' => now(),
            ]);

            DB::table('campaign_creators')->where('id', $fila->campaign_creator_id)->update(
                $respuesta === 'accepted'
                    ? ['status' => 'accepted', 'accepted_at' => now(), 'updated_at' => now()]
                    : ['status' => 'declined', 'declined_at' => now(), 'updated_at' => now()],
            );

            return ['ok' => true, 'motivo' => null, 'participacionId' => (int) $fila->campaign_creator_id];
        });

        if ($resultado['ok']) {
            Eventos::ocurrio(
                nombre: $respuesta === 'accepted'
                    ? 'campaign_creator.accepted'
                    : 'campaign_creator.declined',
                tipoEntidad: 'campaign_creator',
                idEntidad: (int) $resultado['participacionId'],
                payload: array_filter(['decline_reason' => $motivo], static fn ($v): bool => $v !== null),
            );
        }

        return ['ok' => $resultado['ok'], 'motivo' => $resultado['motivo']];
    }

    private static function porToken(string $token): ?object
    {
        return DB::table('invitations as i')
            ->join('campaign_creators as cc', 'cc.id', '=', 'i.campaign_creator_id')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->join('creators as cr', 'cr.id', '=', 'cc.creator_id')
            ->leftJoin('client_brands as b', 'b.id', '=', 'c.client_brand_id')
            ->where('i.token_hash', hash('sha256', $token))
            ->first([
                'i.id', 'i.uuid', 'i.campaign_creator_id', 'i.expires_at', 'i.responded_at',
                'i.response', 'i.revoked_at', 'i.revoked_reason',
                'i.amount_snapshot', 'i.currency_snapshot',
                'cc.agreed_amount', 'cc.status as estado', 'cc.payment_term_days_snapshot',
                'c.name as campana', 'c.starts_on', 'c.ends_on', 'c.closed_at', 'c.uuid as campana_uuid',
                'cr.display_name', 'b.name as marca',
            ]);
    }

    /** El importe formateado para el correo. Nunca se manda un `float` a una plantilla. */
    private static function importe(object $participacion): string
    {
        return number_format((float) $participacion->agreed_amount, 2).' '
            .(string) $participacion->currency_code;
    }
}
