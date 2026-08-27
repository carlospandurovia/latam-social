<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Eventos\CorreoPedido;
use App\Shared\Eventos\Eventos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * La aprobación del cliente, por enlace firmado (8.5).
 *
 * La primera vez que entra al sistema alguien de la **marca**. Sin portal, sin
 * cuenta y sin contraseña: la autorización es el token, igual que la invitación
 * del creador (`7.6`) y el enlace de contraseña (`5.9`).
 *
 * ### La respuesta se registra; el equipo cierra (`DEC-151`)
 *
 * `responder()` escribe lo que dijo el cliente, con su hora y su IP, y **no toca
 * el entregable**. Quien emite el veredicto que lo mueve sigue siendo alguien de
 * la plataforma, desde la cola de revisión de `8.3`.
 *
 * No es burocracia: la corrección del cliente **gasta ronda**, y desde `8.4` una
 * ronda de más exige firma y decisión de facturación. El cliente no puede firmar
 * un cargo contra sí mismo. Si su respuesta moviera la pieza sola, o se le
 * cobraría sin que nadie lo autorizara, o habría que dejar una puerta por la que
 * las rondas no se cuentan.
 *
 * ### El silencio no hace nada (`DEC-152`)
 *
 * El enlace caduca, la pieza se queda donde estaba y sale en la bandeja. **No
 * hay comando de caducidad**, al revés que en `7.6`: allí una invitación sin
 * contestar dejaba dinero comprometido y una plaza de cupo ocupada, y había un
 * estado del mundo que corregir. Aquí `expires_at < NOW()` basta.
 */
final class Aprobaciones
{
    public const APROBADA = 'approved';

    public const CAMBIOS = 'changes_requested';

    /**
     * Cuánto vive el enlace. Cinco días, y no las 72 h de la invitación del
     * creador: al creador se le pregunta si quiere trabajar y contesta con el
     * móvil; a un cliente se le pide que revise una pieza, y eso suele pasar por
     * más de una persona y por un fin de semana.
     */
    public const HORAS = 120;

    /** Por qué un enlace no sirve. El texto es para alguien de fuera. */
    public const FALLOS = [
        'desconocido' => 'Este enlace no existe. Compruebe que lo copio entero desde el correo.',
        'caducado' => 'Este enlace ya vencio. Pidale a su contacto que le mande uno nuevo.',
        'contestado' => 'Esta pieza ya la contesto. Si quiere cambiar algo, hable con su contacto.',
        'anulado' => 'Este enlace se anulo. Pidale a su contacto que le mande uno nuevo.',
    ];

    // ------------------------------------------------------------- pedirla

    /**
     * Manda la pieza al cliente. Devuelve el token, que es la única vez que existe.
     *
     * Del token se guarda **la huella**, no el token: si alguien se lleva la
     * tabla, no se lleva los enlaces. Misma pieza que `5.9` y `7.6`.
     *
     * Si ya había un enlace vivo sobre esta pieza, se **anula** antes. La
     * decimoséptima columna puerta (`uq_apl_viva`) lo impondría igual con un
     * `1062`; esto es para que el operador no lo vea nunca.
     */
    public static function pedir(object $entregable, string $correo, ?int $usuarioId): string
    {
        $ahora = now();
        $token = bin2hex(random_bytes(32));
        $uuid = (string) Str::uuid();

        DB::transaction(function () use ($entregable, $correo, $usuarioId, $ahora, $token, $uuid): void {
            self::anularVivos((int) $entregable->id, 'reemplazado', $ahora);

            DB::table('approval_links')->insert([
                'uuid' => $uuid,
                'deliverable_id' => (int) $entregable->id,
                'deliverable_version_id' => (int) $entregable->approved_version_id,
                'token_hash' => hash('sha256', $token),
                'sent_to' => $correo,
                'sent_by_user_id' => $usuarioId,
                'sent_at' => $ahora,
                'expires_at' => $ahora->copy()->addHours(self::HORAS),
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        });

        Bitacora::registrar(
            accion: 'approval_link.sent',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $entregable->id,
            // El correo del destinatario sí; el token JAMÁS.
            cambios: ['destinatario' => ['antes' => null, 'despues' => $correo]],
        );

        Eventos::ocurrio(
            nombre: 'approval_link.sent',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $entregable->id,
            payload: ['horas' => self::HORAS],
        );

        Event::dispatch(new CorreoPedido(
            codigo: 'content.client_approval',
            destinatario: $correo,
            variables: [
                'campana' => (string) $entregable->campana,
                'marca' => (string) ($entregable->marca ?? ''),
                'formato' => (string) ($entregable->formato ?? 'la pieza'),
                'creador' => (string) $entregable->creador,
                'limite' => $ahora->copy()->addHours(self::HORAS)->format('d/m/Y'),
                'enlace' => route('aprobacion.ver', ['token' => $token]),
            ],
            idioma: 'es',
            tipoRelacionado: 'deliverable',
            idRelacionado: (int) $entregable->id,
        ));

        return $token;
    }

    /** Anula los enlaces vivos de una pieza. Devuelve cuántos. */
    public static function anularVivos(int $entregableId, string $motivo, ?Carbon $cuando = null): int
    {
        $ahora = $cuando ?? now();

        return DB::table('approval_links')
            ->where('deliverable_id', $entregableId)
            ->whereNull('responded_at')
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $ahora,
                'revoked_reason' => $motivo,
                'updated_at' => $ahora,
            ]);
    }

    // ------------------------------------------------------------ usarla

    /**
     * ¿Sirve este token? Devuelve el motivo cuando no, para poder decirlo.
     *
     * @return array{ok: bool, motivo?: string, enlace?: \stdClass}
     */
    public static function validar(string $token): array
    {
        $fila = self::porToken($token);

        if ($fila === null) {
            return ['ok' => false, 'motivo' => 'desconocido'];
        }

        $motivo = match (true) {
            $fila->revoked_at !== null => 'anulado',
            $fila->responded_at !== null => 'contestado',
            // Comparado como texto contra el reloj de la aplicación, igual que
            // en 7.6: la columna es DATETIME(3) y la comparación en PHP con
            // milisegundos ya nos dio un intermitente una vez (`T-39`).
            $fila->expires_at < now()->format('Y-m-d H:i:s.v') => 'caducado',
            default => null,
        };

        return $motivo === null
            ? ['ok' => true, 'enlace' => $fila]
            : ['ok' => false, 'motivo' => $motivo];
    }

    /** Que lo abrió consta antes de que decida nada. */
    public static function marcarAbierto(int $enlaceId): void
    {
        DB::table('approval_links')
            ->where('id', $enlaceId)
            ->whereNull('opened_at')
            ->update(['opened_at' => now(), 'updated_at' => now()]);
    }

    /**
     * El cliente contesta. **No toca el entregable** (`DEC-151`).
     *
     * @return array{ok: bool, motivo?: string}
     */
    public static function responder(
        string $token,
        string $respuesta,
        ?string $comentario,
        ?string $ip,
    ): array {
        $resultado = self::validar($token);

        if (!$resultado['ok']) {
            return $resultado;
        }

        /** @var \stdClass $enlace */
        $enlace = $resultado['enlace'];
        $ahora = now();
        $empaquetada = is_string($ip) ? inet_pton($ip) : false;

        DB::table('approval_links')->where('id', $enlace->id)->update([
            'responded_at' => $ahora,
            'response' => $respuesta,
            'comments' => $comentario !== null ? mb_substr($comentario, 0, 2000) : null,
            'responded_ip' => $empaquetada === false ? null : $empaquetada,
            'updated_at' => $ahora,
        ]);

        Bitacora::registrar(
            accion: 'approval_link.answered',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $enlace->deliverable_id,
            cambios: ['respuesta' => ['antes' => null, 'despues' => $respuesta]],
        );

        // El QUÉ contestó, sin el texto libre: `2.2 §7`.
        Eventos::ocurrio(
            nombre: 'approval_link.answered',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $enlace->deliverable_id,
            payload: ['respuesta' => $respuesta],
        );

        return ['ok' => true];
    }

    // ----------------------------------------------------------- mirarla

    /** El enlace por su token, o `null`. */
    public static function porToken(string $token): ?object
    {
        return DB::table('approval_links')
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    /**
     * Lo que el cliente puede ver de la pieza, y **nada más**.
     *
     * `BR-SEC-001` es 🔴: ni el importe del creador, ni el presupuesto, ni el
     * margen, ni las demás piezas de la campaña. Esta consulta es la frontera, y
     * por eso enumera columnas en vez de traerse la fila entera.
     */
    public static function pieza(object $enlace): ?object
    {
        return DB::table('deliverable_versions as v')
            ->join('deliverables as d', 'd.id', '=', 'v.deliverable_id')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->join('creators as cr', 'cr.id', '=', 'cc.creator_id')
            ->join('campaign_requirements as r', 'r.id', '=', 'd.campaign_requirement_id')
            ->join('content_formats as f', 'f.id', '=', 'r.content_format_id')
            ->leftJoin('platforms as p', 'p.id', '=', 'f.platform_id')
            ->leftJoin('client_brands as b', 'b.id', '=', 'c.client_brand_id')
            ->where('v.id', $enlace->deliverable_version_id)
            ->first([
                'v.version_number', 'v.external_url', 'v.caption', 'v.creator_notes',
                'v.submitted_at', 'd.sequence_number', 'c.name as campana',
                'b.name as marca', 'f.code as formato', 'p.name as red',
                'cr.display_name as creador',
            ]);
    }

    /**
     * Lo que el cliente contestó y **nadie ha cerrado todavía**.
     *
     * Es la mitad visible de `DEC-153`: una petición de cambios sin rondas se
     * queda aquí hasta que alguien decida si se cobra o se absorbe.
     *
     * @return Collection<int, \stdClass>
     */
    public static function pendientes(?int $campanaId = null): Collection
    {
        $consulta = DB::table('approval_links as a')
            ->join('deliverables as d', 'd.id', '=', 'a.deliverable_id')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->join('creators as cr', 'cr.id', '=', 'cc.creator_id')
            ->whereNotNull('a.responded_at')
            ->whereNull('a.content_review_id');

        if ($campanaId !== null) {
            $consulta->where('c.id', $campanaId);
        }

        return $consulta
            ->orderBy('a.responded_at')
            ->get([
                'a.uuid', 'a.response', 'a.comments', 'a.responded_at', 'a.sent_to',
                'd.uuid as entregable_uuid', 'd.sequence_number',
                'c.id as campana_id', 'c.name as campana', 'cr.display_name as creador',
            ]);
    }

    /** La respuesta del cliente sobre esta pieza, si la hay y sigue sin cerrar. */
    public static function respuestaPendiente(int $entregableId): ?object
    {
        return DB::table('approval_links')
            ->where('deliverable_id', $entregableId)
            ->whereNotNull('responded_at')
            ->whereNull('content_review_id')
            ->orderByDesc('responded_at')
            ->first(['id', 'uuid', 'response', 'comments', 'responded_at', 'sent_to']);
    }

    /** El enlace vivo de esta pieza, si lo hay. */
    public static function vivoDe(int $entregableId): ?object
    {
        return DB::table('approval_links')
            ->where('deliverable_id', $entregableId)
            ->whereNull('responded_at')
            ->whereNull('revoked_at')
            ->first(['uuid', 'sent_to', 'sent_at', 'expires_at', 'opened_at']);
    }

    /**
     * Ata la respuesta del cliente al veredicto que la cerró.
     *
     * Sin esto, la respuesta se quedaría para siempre en la bandeja de
     * pendientes — y con esto queda escrito **qué veredicto** contestó a qué
     * cliente, que es lo que alguien va a querer leer dentro de dos años.
     */
    public static function transcribir(int $enlaceId, int $revisionId): void
    {
        DB::table('approval_links')->where('id', $enlaceId)->update([
            'content_review_id' => $revisionId,
            'updated_at' => now(),
        ]);
    }
}
