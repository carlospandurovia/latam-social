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
 * La prueba de que el post existió (8.7).
 *
 * ### Lo que vale es la captura, y hay una razón técnica
 *
 * Decisión de negocio (2026-08-26), tomada con la limitación delante.
 *
 * Instagram y TikTok devuelven `200` con un muro de login, o `403` a todo lo que
 * no sea un navegador de verdad. Un `http_status` **no distingue** «el post
 * existe» de «nos bloquearon». Archivar sólo eso sería archivar un dato que no
 * demuestra nada, y `BR-CONTENT-004` es 🔴: de `verified` cuelga el pago.
 *
 * Así que la sonda HTTP se guarda —es barata y a veces dice algo útil, como un
 * `404` limpio— pero **no decide**. Lo que permite pasar a `verified` es una
 * captura con archivo detrás, y lo impone `tg_pub_verificada_con_evidencia`.
 *
 * La solución buena son las APIs oficiales, que sí devuelven el post. Exigen una
 * app revisada por Meta y tokens del creador: son semanas y son `F12`. Esto es
 * lo honesto con lo que se puede probar hoy.
 *
 * ### Si el post no está, el entregable vuelve al creador
 *
 * La publicación queda `rejected` con su motivo y el entregable vuelve a
 * `approved`. El contenido no tenía nada malo —ya se aprobó—; lo que falla es
 * que no está publicado, así que lo que hay que rehacer es el enlace, no la
 * pieza. Y se le avisa por correo, porque tiene algo que hacer.
 */
final class Evidencias
{
    public const VERIFICADA = 'verified';

    public const RECHAZADA = 'rejected';

    /** Los estados de una publicación que todavía esperan a que alguien mire. */
    public const ESPERANDO = ['reported'];

    /**
     * Por qué se rechaza una publicación. Lista cerrada, como los motivos de
     * `7.6` y `8.2`, y por lo mismo: para poder contestar con un número
     * *«¿por qué se caen las publicaciones?»*.
     */
    public const MOTIVOS = [
        'not_found' => 'El enlace no lleva a ningún post',
        'private' => 'La cuenta o el post son privados',
        'wrong_content' => 'El post no es el contenido aprobado',
        'wrong_account' => 'Está publicado en otra cuenta',
        'other' => 'Otro motivo',
    ];

    /**
     * Lo que espera verificación, de todas las campañas.
     *
     * Bandeja global, igual que la cola de revisión de `8.3` y por lo mismo:
     * verificar es trabajo por lotes. Y ordenada por lo que lleva más esperando,
     * porque un post sin verificar es un pago que no puede salir.
     *
     * @return Collection<int, \stdClass>
     */
    public static function cola(?int $campanaId = null): Collection
    {
        $consulta = DB::table('publications as pb')
            ->join('deliverables as d', 'd.id', '=', 'pb.deliverable_id')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->join('creators as cr', 'cr.id', '=', 'cc.creator_id')
            ->leftJoin('platforms as p', 'p.id', '=', 'pb.platform_id')
            ->whereIn('pb.status', self::ESPERANDO);

        if ($campanaId !== null) {
            $consulta->where('c.id', $campanaId);
        }

        return $consulta
            ->orderBy('pb.published_at')
            ->get([
                'pb.uuid', 'pb.url', 'pb.status', 'pb.published_at',
                'd.sequence_number', 'c.id as campana_id', 'c.name as campana',
                'cr.display_name as creador', 'p.name as red',
            ]);
    }

    /** La publicación con lo que la pantalla necesita, o `null`. */
    public static function publicacion(string $uuid): ?object
    {
        return DB::table('publications as pb')
            ->join('deliverables as d', 'd.id', '=', 'pb.deliverable_id')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->join('creators as cr', 'cr.id', '=', 'cc.creator_id')
            ->join('campaign_requirements as r', 'r.id', '=', 'd.campaign_requirement_id')
            ->leftJoin('platforms as p', 'p.id', '=', 'pb.platform_id')
            ->where('pb.uuid', $uuid)
            ->first([
                'pb.id', 'pb.uuid', 'pb.url', 'pb.status', 'pb.published_at',
                'pb.deliverable_id', 'pb.verified_at', 'pb.rejected_reason',
                'd.uuid as entregable_uuid', 'd.sequence_number',
                'r.permanence_days', 'c.name as campana',
                'cr.display_name as creador', 'cr.email as creador_email',
                'cr.locale as creador_locale', 'p.name as red',
            ]);
    }

    /**
     * Lo archivado de una publicación, lo más reciente primero.
     *
     * @return Collection<int, \stdClass>
     */
    public static function de(int $publicacionId): Collection
    {
        return DB::table('publication_evidence as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.captured_by_user_id')
            ->leftJoin('files as f', 'f.id', '=', 'e.file_id')
            ->where('e.publication_id', $publicacionId)
            ->orderByDesc('e.captured_at')
            ->get([
                'e.uuid', 'e.evidence_type', 'e.http_status', 'e.captured_at',
                'u.name as capturado_por', 'f.original_name as archivo',
            ]);
    }

    /** ¿Hay ya una captura archivada de esta publicación? */
    public static function tieneCaptura(int $publicacionId): bool
    {
        return DB::table('publication_evidence')
            ->where('publication_id', $publicacionId)
            ->where('evidence_type', 'screenshot')
            ->whereNotNull('file_id')
            ->exists();
    }

    /**
     * Archiva una evidencia. Devuelve su uuid.
     *
     * `publication_evidence` lleva `no_delete` desde `3.12`: esto no se borra
     * nunca. Es lo que va a mirar quien discuta un pago dentro de dos años.
     *
     * @param array{tipo: string, file_id?: ?int, http_status?: ?int, payload?: ?string} $datos
     */
    public static function archivar(int $publicacionId, array $datos, ?int $usuarioId): string
    {
        $ahora = now();
        $uuid = (string) Str::uuid();

        DB::table('publication_evidence')->insert([
            'uuid' => $uuid,
            'publication_id' => $publicacionId,
            'evidence_type' => $datos['tipo'],
            'file_id' => $datos['file_id'] ?? null,
            'http_status' => $datos['http_status'] ?? null,
            'raw_payload' => $datos['payload'] ?? null,
            'captured_at' => $ahora,
            'captured_by_user_id' => $usuarioId,
            'created_at' => $ahora,
        ]);

        return $uuid;
    }

    /**
     * Por qué esta publicación **no** se puede verificar, o lista vacía.
     *
     * @return list<string>
     */
    public static function vetoParaVerificar(object $publicacion, ?int $archivoId): array
    {
        if (!in_array((string) $publicacion->status, self::ESPERANDO, true)) {
            return [sprintf('Esta publicacion esta en «%s» y ya no espera veredicto.', $publicacion->status)];
        }

        if ($archivoId === null && !self::tieneCaptura((int) $publicacion->id)) {
            // Se dice con palabras antes de que lo diga el disparador con un 1644.
            return ['Suba la captura del post. Un estado HTTP no prueba que exista: '
                .'Instagram y TikTok responden igual a un post vivo que a un bloqueo.'];
        }

        return [];
    }

    /**
     * Da la publicación por verificada y calcula su permanencia.
     *
     * `permanence_until` es `published_at + permanence_days` del requisito, y se
     * calcula **aquí** y no al reportar: hasta que alguien mira no se sabe si hay
     * post del que contar permanencia. Es lo que `8.8` vigila.
     */
    public static function verificar(object $publicacion, ?int $archivoId, ?int $usuarioId): void
    {
        $ahora = now();

        DB::transaction(function () use ($publicacion, $archivoId, $usuarioId, $ahora): void {
            if ($archivoId !== null) {
                self::archivar((int) $publicacion->id, [
                    'tipo' => 'screenshot', 'file_id' => $archivoId,
                ], $usuarioId);
            }

            $hasta = Carbon::parse((string) $publicacion->published_at)
                ->addDays((int) $publicacion->permanence_days)
                ->toDateString();

            DB::table('publications')->where('id', $publicacion->id)->update([
                'status' => self::VERIFICADA,
                'verified_at' => $ahora,
                'verified_by_user_id' => $usuarioId,
                'permanence_until' => $hasta,
                'updated_at' => $ahora,
            ]);

            DB::table('deliverables')->where('id', $publicacion->deliverable_id)->update([
                'status' => 'verified',
                'updated_at' => $ahora,
            ]);
        });

        Bitacora::registrar(
            accion: 'publication.verified',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $publicacion->deliverable_id,
            cambios: ['status' => ['antes' => 'published', 'despues' => 'verified']],
        );

        Eventos::ocurrio(
            nombre: 'publication.verified',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $publicacion->deliverable_id,
            payload: ['publicacion' => (int) $publicacion->id],
        );
    }

    /**
     * Rechaza la publicación y **devuelve el entregable al creador**.
     *
     * El contenido no tenía nada malo —ya se aprobó—; lo que falla es que no
     * está publicado. Así que la pieza vuelve a `approved`, que es donde su
     * portal le vuelve a ofrecer pegar un enlace, y no a revisión.
     *
     * La evidencia de lo que se vio se archiva igual: *«no había nada»* también
     * es algo que hay que poder demostrar.
     */
    public static function rechazar(
        object $publicacion,
        string $motivo,
        ?string $nota,
        ?int $archivoId,
        ?int $usuarioId,
    ): void {
        $ahora = now();
        $texto = self::MOTIVOS[$motivo].(($nota ?? '') !== '' ? ' — '.$nota : '');

        DB::transaction(function () use ($publicacion, $texto, $archivoId, $usuarioId, $ahora): void {
            if ($archivoId !== null) {
                self::archivar((int) $publicacion->id, [
                    'tipo' => 'screenshot', 'file_id' => $archivoId,
                ], $usuarioId);
            }

            DB::table('publications')->where('id', $publicacion->id)->update([
                'status' => self::RECHAZADA,
                'verified_at' => $ahora,
                'verified_by_user_id' => $usuarioId,
                'rejected_reason' => mb_substr($texto, 0, 255),
                'updated_at' => $ahora,
            ]);

            // Vuelve a `approved`: `Publicaciones::yaTiene()` no cuenta las
            // rechazadas, así que el creador puede registrar otro enlace.
            DB::table('deliverables')->where('id', $publicacion->deliverable_id)->update([
                'status' => 'approved',
                'updated_at' => $ahora,
            ]);
        });

        Bitacora::registrar(
            accion: 'publication.rejected',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $publicacion->deliverable_id,
            cambios: ['status' => ['antes' => 'published', 'despues' => 'approved']],
        );

        Eventos::ocurrio(
            nombre: 'publication.rejected',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $publicacion->deliverable_id,
            // El motivo de lista cerrada sí; la nota libre no.
            payload: ['motivo' => $motivo],
        );

        self::avisarAlCreador($publicacion, $texto);
    }

    /**
     * Una sonda HTTP: se guarda, y **no decide**.
     *
     * No se llama sola desde ningún sitio todavía y es a propósito: una
     * comprobación automática contra Instagram devuelve lo mismo para un post
     * vivo que para un bloqueo, así que programarla daría una cifra que parece
     * medir algo. Aquí está para archivar el estado cuando quien verifica quiera
     * dejarlo constando junto a su captura.
     *
     * **No hace la petición**: recibe el estado. Salir a Internet desde una
     * transacción de negocio es lo que `2.2 §7` prohíbe, y hacerlo desde el
     * servidor de la aplicación contra una red que nos bloquea es además frágil.
     */
    public static function anotarSonda(int $publicacionId, int $estado, ?int $usuarioId): string
    {
        return self::archivar($publicacionId, [
            'tipo' => 'http_check', 'http_status' => $estado,
        ], $usuarioId);
    }

    /** El correo de «tu post no aparece», al creador. */
    private static function avisarAlCreador(object $publicacion, string $motivo): void
    {
        if (($publicacion->creador_email ?? null) === null) {
            return;
        }

        Event::dispatch(new CorreoPedido(
            codigo: 'content.publication_rejected',
            destinatario: (string) $publicacion->creador_email,
            variables: [
                'nombre' => (string) $publicacion->creador,
                'campana' => (string) $publicacion->campana,
                'enlace_post' => (string) $publicacion->url,
                'motivo' => $motivo,
                'enlace' => route('entregas.mias'),
            ],
            idioma: (string) ($publicacion->creador_locale ?: 'es'),
            tipoRelacionado: 'deliverable',
            idRelacionado: (int) $publicacion->deliverable_id,
        ));
    }
}
