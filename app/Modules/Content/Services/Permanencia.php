<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Eventos\CorreoPedido;
use App\Shared\Eventos\Eventos;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * La permanencia mínima del post (8.8).
 *
 * `BR-CONTENT-006`: si el contrato exige que el post permanezca N días, el
 * sistema comprueba que sigue ahí y avisa si desaparece. `8.7` dejó
 * `permanence_until` calculado; esto es lo que lo vigila.
 *
 * ### Lo que este servicio NO hace: salir a Internet
 *
 * No hay ninguna llamada HTTP aquí, y no es un descuido.
 *
 * Primero, porque `2.2 §7` prohíbe salir a la red desde una transacción de
 * negocio: el día que Instagram tarde diez segundos, el que se cuelga es el
 * planificador. Y segundo, y más importante, porque **la respuesta no
 * significaría nada**: Instagram y TikTok devuelven lo mismo ante un post
 * borrado, un perfil puesto en privado y un bloqueo geográfico. Programar esa
 * llamada daría una cifra que **parece** medir algo, y de esa cifra colgaría un
 * pago retenido.
 *
 * Así que `anotar()` **recibe** el resultado de una comprobación —de una persona
 * que miró, o de un proceso externo que sepa mirar de verdad— y lo archiva. La
 * comprobación automática buena son las APIs oficiales, que sí devuelven el
 * post: exigen una app revisada por Meta y tokens del creador, y son `F12`.
 *
 * ### Retirar el post antes de tiempo bloquea el pago (`DEC-145`)
 *
 * La publicación pasa a `removed`, el entregable a `removed` también, y ahí se
 * para. El sistema **no descuenta nada por su cuenta**: eso exigiría que el
 * contrato lo dijera por escrito y que la detección fuera fiable.
 *
 * ### Lo único que el planificador decide solo es cerrar ventanas cumplidas
 *
 * `cerrarVentanas()` no mira ningún post: compara `permanence_until` con el
 * calendario. Esa sí es una operación que el sistema puede hacer solo, porque no
 * depende de nada que no sepa. Y es la que habilita el pago.
 */
final class Permanencia
{
    public const VIGILANDO = 'verified';

    public const CAIDA = 'removed';

    public const CUMPLIDA = 'fulfilled';

    /** De dónde salió una comprobación. */
    public const SONDA = 'probe';

    public const MANUAL = 'manual';

    /**
     * Por qué se da un post por caído. Lista cerrada, como los motivos de `8.7`
     * y por lo mismo: para poder contestar con un número *«¿por qué se caen los
     * posts?»*. La respuesta cambia lo que se le pide al creador la próxima vez.
     */
    public const MOTIVOS = [
        'post_deleted' => 'El creador borro el post',
        'account_private' => 'La cuenta paso a privada',
        'account_deleted' => 'La cuenta ya no existe',
        'url_changed' => 'El enlace ya no lleva a ese post',
        'other' => 'Otro motivo',
    ];

    /**
     * A los cuántos días sin comprobar una publicación vigilada se considera
     * desatendida. No es una regla de negocio: es el umbral de la bandeja, y
     * sale de que la ventana más corta que se contrata son días, no horas.
     */
    public const DIAS_DESATENDIDA = 7;

    // --------------------------------------------------------------- consultas

    /**
     * Lo que hay que mirar: caídas abiertas primero, luego lo vigilado que nadie
     * comprueba desde hace días.
     *
     * Bandeja global, como la cola de revisión de `8.3` y la de verificación de
     * `8.7`: una bandeja por campaña obliga a recorrer campañas para descubrir
     * si hay algo, y lo que se descubre recorriendo se descubre tarde.
     *
     * @return Collection<int, \stdClass>
     */
    public static function bandeja(?int $campanaId = null): Collection
    {
        // La última comprobación de cada publicación, por tabla derivada y no
        // por subconsulta correlacionada en el ON: MySQL 5.7 no materializa la
        // segunda y la bandeja pasaría a costar una consulta por fila.
        $ultimas = DB::table('permanence_checks')
            ->selectRaw('publication_id, MAX(checked_at) as checked_at')
            ->groupBy('publication_id');

        $consulta = self::base()
            ->leftJoinSub($ultimas, 'ult', 'ult.publication_id', '=', 'pb.id')
            ->leftJoin('permanence_checks as uc', function ($union): void {
                $union->on('uc.publication_id', '=', 'pb.id')
                    ->on('uc.checked_at', '=', 'ult.checked_at');
            })
            ->whereIn('pb.status', [self::VIGILANDO, self::CAIDA]);

        if ($campanaId !== null) {
            $consulta->where('c.id', $campanaId);
        }

        return $consulta
            // Las caídas primero: son las que tienen un pago parado detrás.
            ->orderByRaw("CASE WHEN pb.status = '".self::CAIDA."' THEN 0 ELSE 1 END")
            ->orderByRaw('uc.checked_at IS NOT NULL')
            ->orderBy('pb.permanence_until')
            ->get([
                'pb.uuid', 'pb.url', 'pb.status', 'pb.published_at',
                'pb.permanence_until', 'pb.removed_reason',
                'uc.checked_at as ultima_comprobacion', 'uc.is_live as ultima_viva',
                'd.sequence_number', 'c.id as campana_id', 'c.name as campana',
                'cr.display_name as creador', 'p.name as red',
            ]);
    }

    /** La publicación con lo que la pantalla necesita, o `null`. */
    public static function publicacion(string $uuid): ?object
    {
        return self::base()
            ->where('pb.uuid', $uuid)
            ->first([
                'pb.id', 'pb.uuid', 'pb.url', 'pb.status', 'pb.published_at',
                'pb.permanence_until', 'pb.verified_at', 'pb.removed_at',
                'pb.removed_reason', 'pb.fulfilled_at', 'pb.deliverable_id',
                'd.uuid as entregable_uuid', 'd.sequence_number',
                'r.permanence_days', 'c.name as campana',
                'cr.display_name as creador', 'cr.email as creador_email',
                'cr.locale as creador_locale', 'p.name as red',
            ]);
    }

    /**
     * El historial de comprobaciones, lo más reciente primero.
     *
     * @return Collection<int, \stdClass>
     */
    public static function comprobaciones(int $publicacionId): Collection
    {
        return DB::table('permanence_checks as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.checked_by_user_id')
            ->where('c.publication_id', $publicacionId)
            ->orderByDesc('c.checked_at')
            ->get([
                'c.uuid', 'c.source', 'c.checked_at', 'c.is_live',
                'c.http_status', 'c.notes', 'u.name as miro',
            ]);
    }

    /** La última comprobación de una publicación, o `null`. */
    public static function ultima(int $publicacionId): ?object
    {
        return DB::table('permanence_checks')
            ->where('publication_id', $publicacionId)
            ->orderByDesc('checked_at')
            ->first(['uuid', 'source', 'checked_at', 'is_live', 'http_status', 'notes']);
    }

    /** ¿Cuántos días le quedan de ventana? Negativo si ya pasó. */
    public static function diasRestantes(object $publicacion): ?int
    {
        if (($publicacion->permanence_until ?? null) === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays(
            Carbon::parse((string) $publicacion->permanence_until)->startOfDay(),
            false,
        );
    }

    // ------------------------------------------------------------ el vigilante

    /**
     * Cierra las ventanas que llegaron a su fecha con el post en pie.
     *
     * Lo único que el planificador decide solo, porque es lo único que no
     * depende de mirar ningún post: compara `permanence_until` con el
     * calendario. Y es lo que habilita el pago, así que se hace **hoy** y no
     * cuando alguien se acuerde.
     *
     * `permanence_until < CURDATE()` y no `<=`: la ventana incluye su último
     * día entero. Cerrarla el mismo día la recortaría en veinticuatro horas, y
     * eso es una obligación contractual medida en días.
     */
    public static function cerrarVentanas(): int
    {
        $ahora = now();

        /** @var list<int> $ids */
        $ids = DB::table('publications')
            ->where('status', self::VIGILANDO)
            ->whereNotNull('permanence_until')
            ->whereRaw('permanence_until < CURDATE()')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return 0;
        }

        // Por id y no por la misma condición: entre el SELECT y el UPDATE puede
        // haberse caído una, y volver a evaluar la condición la cerraría como
        // cumplida. Es el mismo cuidado que `T-49` pero por el otro motivo.
        DB::table('publications')
            ->whereIn('id', $ids)
            ->where('status', self::VIGILANDO)
            ->update([
                'status' => self::CUMPLIDA,
                'fulfilled_at' => $ahora,
                'updated_at' => $ahora,
            ]);

        foreach ($ids as $id) {
            Eventos::ocurrio(
                nombre: 'publication.permanence_fulfilled',
                tipoEntidad: 'publication',
                idEntidad: $id,
                payload: ['publicacion' => $id],
            );
        }

        return count($ids);
    }

    /**
     * Las que llevan `$dias` sin que nadie las mire, con la ventana abierta.
     *
     * @return Collection<int, \stdClass>
     */
    public static function desatendidas(int $dias = self::DIAS_DESATENDIDA): Collection
    {
        $limite = now()->subDays($dias);

        return self::base()
            ->where('pb.status', self::VIGILANDO)
            ->whereNotNull('pb.permanence_until')
            ->whereRaw('pb.permanence_until >= CURDATE()')
            ->whereNotExists(function ($sub) use ($limite): void {
                $sub->select(DB::raw(1))
                    ->from('permanence_checks as c')
                    ->whereColumn('c.publication_id', 'pb.id')
                    ->where('c.checked_at', '>=', $limite);
            })
            ->orderBy('pb.permanence_until')
            ->get(['pb.uuid', 'pb.url', 'pb.permanence_until',
                'c.name as campana', 'cr.display_name as creador']);
    }

    // ------------------------------------------------------------- escribir

    /**
     * Archiva una comprobación. Devuelve su uuid.
     *
     * **No cambia el estado de nada.** Una comprobación es una observación, y
     * `DEC-146` dice que la observación marca y la persona confirma.
     *
     * La sonda escribe como mucho una por publicación y día —`uq_pc_sonda_dia`,
     * una columna puerta más—, así que llamarla dos veces el mismo día es
     * un `1062` y no un correo repetido al creador.
     */
    public static function anotar(
        int $publicacionId,
        bool $viva,
        string $origen,
        ?int $estadoHttp,
        ?string $nota,
        ?int $usuarioId,
    ): string {
        $ahora = now();
        $uuid = (string) Str::uuid();

        DB::table('permanence_checks')->insert([
            'uuid' => $uuid,
            'publication_id' => $publicacionId,
            'source' => $origen,
            'checked_at' => $ahora,
            'is_live' => $viva ? 1 : 0,
            'checked_by_user_id' => $usuarioId,
            'http_status' => $estadoHttp,
            'notes' => $nota !== null ? mb_substr($nota, 0, 255) : null,
            'created_at' => $ahora,
        ]);

        return $uuid;
    }

    /**
     * Por qué esta publicación **no** se puede dar por caída, o lista vacía.
     *
     * @return list<string>
     */
    public static function vetoParaDarPorCaida(object $publicacion, bool $hayCaptura): array
    {
        if ((string) $publicacion->status !== self::VIGILANDO) {
            return [sprintf('Esta publicacion esta en «%s»: solo se cae lo que estaba vigilado.', $publicacion->status)];
        }

        $motivos = [];

        // Se dice con palabras antes de que lo diga `tg_pub_permanencia` con un
        // 1644 en la cara.
        $ultima = self::ultima((int) $publicacion->id);

        if ($ultima === null || (int) $ultima->is_live === 1) {
            $motivos[] = 'Anote antes una comprobacion que diga que el post no esta. '
                .'Una caida sin comprobacion es una acusacion sin nada detras.';
        }

        if (!$hayCaptura) {
            $motivos[] = 'Suba la captura de lo que ve ahora. La que probo que el post existia '
                .'no prueba que ya no este.';
        }

        return $motivos;
    }

    /**
     * Da el post por caído: la publicación a `removed`, el entregable también, y
     * el pago se queda quieto (`DEC-145`).
     *
     * El entregable NO vuelve a `approved` como en el rechazo de `8.7`. Allí el
     * contenido estaba bien y sólo fallaba el enlace, así que devolvérselo al
     * creador tenía sentido. Aquí el creador **retiró** lo que se había
     * comprometido a dejar puesto: no es un trámite que él pueda cerrar, es una
     * incidencia que alguien tiene que decidir.
     */
    public static function darPorCaida(
        object $publicacion,
        string $motivo,
        ?string $nota,
        int $archivoId,
        ?int $usuarioId,
    ): void {
        $ahora = now();
        $texto = self::MOTIVOS[$motivo].(($nota ?? '') !== '' ? ' — '.$nota : '');
        $restantes = self::diasRestantes($publicacion) ?? 0;

        DB::transaction(function () use ($publicacion, $texto, $archivoId, $usuarioId, $ahora): void {
            Evidencias::archivar((int) $publicacion->id, [
                'tipo' => 'screenshot', 'file_id' => $archivoId,
            ], $usuarioId);

            DB::table('publications')->where('id', $publicacion->id)->update([
                'status' => self::CAIDA,
                'removed_at' => $ahora,
                'removed_reason' => mb_substr($texto, 0, 255),
                'removed_by_user_id' => $usuarioId,
                'updated_at' => $ahora,
            ]);

            DB::table('deliverables')->where('id', $publicacion->deliverable_id)->update([
                'status' => self::CAIDA,
                'updated_at' => $ahora,
            ]);
        });

        Bitacora::registrar(
            accion: 'publication.permanence_broken',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $publicacion->deliverable_id,
            cambios: ['status' => ['antes' => 'verified', 'despues' => 'removed']],
        );

        Eventos::ocurrio(
            nombre: 'publication.permanence_broken',
            tipoEntidad: 'publication',
            idEntidad: (int) $publicacion->id,
            // El motivo de lista cerrada sí; la nota libre no. `2.2 §7`.
            payload: ['motivo' => $motivo, 'dias_que_faltaban' => max(0, $restantes)],
        );

        self::avisarAlCreador($publicacion, $texto);
    }

    /**
     * Devuelve la publicación a vigilada: era un falso positivo, o el creador la
     * repuso.
     *
     * `permanence_until` **no se toca**. Si la ventana debería alargarse por los
     * días que el post estuvo caído es una decisión de negocio que está abierta
     * como `Q-59`, y alargarla por mi cuenta sería inventarme una cláusula.
     */
    public static function reponer(object $publicacion, int $archivoId, ?int $usuarioId): void
    {
        $ahora = now();

        DB::transaction(function () use ($publicacion, $archivoId, $usuarioId, $ahora): void {
            Evidencias::archivar((int) $publicacion->id, [
                'tipo' => 'screenshot', 'file_id' => $archivoId,
            ], $usuarioId);

            DB::table('publications')->where('id', $publicacion->id)->update([
                'status' => self::VIGILANDO,
                'removed_at' => null,
                'removed_reason' => null,
                'removed_by_user_id' => null,
                'updated_at' => $ahora,
            ]);

            DB::table('deliverables')->where('id', $publicacion->deliverable_id)->update([
                'status' => 'verified',
                'updated_at' => $ahora,
            ]);
        });

        Bitacora::registrar(
            accion: 'publication.permanence_restored',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $publicacion->deliverable_id,
            cambios: ['status' => ['antes' => 'removed', 'despues' => 'verified']],
        );

        Eventos::ocurrio(
            nombre: 'publication.permanence_restored',
            tipoEntidad: 'publication',
            idEntidad: (int) $publicacion->id,
            payload: ['publicacion' => (int) $publicacion->id],
        );
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * El aviso de «tu post ya no está», al creador.
     *
     * Al creador y al equipo; **al cliente no** (`DEC-147`). El equipo lo ve en
     * su bandeja sin que nadie le mande nada: avisar al cliente de un falso
     * positivo cuesta más que el propio incidente.
     */
    private static function avisarAlCreador(object $publicacion, string $motivo): void
    {
        if (($publicacion->creador_email ?? null) === null) {
            return;
        }

        Event::dispatch(new CorreoPedido(
            codigo: 'content.permanence_broken',
            destinatario: (string) $publicacion->creador_email,
            variables: [
                'nombre' => (string) $publicacion->creador,
                'campana' => (string) $publicacion->campana,
                'enlace_post' => (string) $publicacion->url,
                'motivo' => $motivo,
                'hasta' => (string) $publicacion->permanence_until,
                'enlace' => route('entregas.mias'),
            ],
            idioma: (string) ($publicacion->creador_locale ?: 'es'),
            tipoRelacionado: 'deliverable',
            idRelacionado: (int) $publicacion->deliverable_id,
        ));
    }

    /** El armazón de JOINs que comparten la bandeja y la ficha. */
    private static function base(): Builder
    {
        return DB::table('publications as pb')
            ->join('deliverables as d', 'd.id', '=', 'pb.deliverable_id')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->join('creators as cr', 'cr.id', '=', 'cc.creator_id')
            ->join('campaign_requirements as r', 'r.id', '=', 'd.campaign_requirement_id')
            ->leftJoin('platforms as p', 'p.id', '=', 'pb.platform_id');
    }
}
