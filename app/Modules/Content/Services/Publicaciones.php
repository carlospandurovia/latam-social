<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Eventos\Eventos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El post publicado (8.6).
 *
 * ### La huella: por qué la URL cruda no sirve
 *
 * `uq_pub_fingerprint` existe desde la Fase 2 para impedir que **dos creadores
 * reclamen el mismo post**. Pasa: el mismo enlace con `?utm_source=ig` y sin él
 * son la misma página y dos cadenas distintas, y con la URL cruda la restricción
 * no impediría nada.
 *
 * Así que se normaliza antes de firmar. Lo que se quita es **lo que la plataforma
 * añade y no identifica el post**; lo que identifica el post se respeta con
 * cuidado, incluidas las mayúsculas del identificador — `instagram.com/p/AbC` y
 * `/p/abc` son dos posts distintos, y bajar todo a minúsculas los fundiría en uno.
 *
 * ### Sólo se publica lo aprobado
 *
 * Decisión de negocio (2026-08-26). `BR-CONTENT-002` dice que nada llega al
 * cliente sin aprobación interna; registrar la publicación de algo no aprobado es
 * darlo por bueno a posteriori, con la firma de nadie. Lo impone además
 * `tg_pub_version_aprobada`, porque de esta fila cuelga el pago.
 *
 * ### Y la red tiene que ser la del brief
 *
 * El brief compró un reel de Instagram; un TikTok no es eso, por bueno que sea.
 * La red se **deduce del enlace** con `platforms.url_pattern` —que está en el
 * esquema desde `2.6` y no lo usaba nadie— en vez de preguntársela a quien
 * reporta: un desplegable ahí sólo sirve para que alguien elija mal.
 */
final class Publicaciones
{
    public const REPORTADA = 'reported';

    /**
     * Parámetros que se quitan de la URL antes de firmarla.
     *
     * Todos son de **medición**, no de contenido. `si` es el de Spotify y
     * YouTube, `igsh`/`igshid` los de Instagram, `_t`/`_r` los de TikTok.
     *
     * La lista es explícita y no «quítalo todo»: hay redes donde un parámetro sí
     * identifica el post —`youtube.com/watch?v=...` es el caso obvio— y una regla
     * que borre la query entera convertiría dos vídeos distintos en la misma
     * huella. Eso es peor que no normalizar: rechazaría un post legítimo diciendo
     * que ya está reclamado.
     */
    public const PARAMETROS_DE_MEDICION = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'fbclid', 'gclid', 'mc_cid', 'mc_eid',
        'igsh', 'igshid', 'si', '_t', '_r', 'is_from_webapp', 'sender_device', 'web_id',
    ];

    /**
     * La URL normalizada: la misma página, escrita siempre igual.
     *
     * Qué se toca y qué no:
     *
     * | Se normaliza | Por qué |
     * |---|---|
     * | esquema y host a minúsculas | el DNS no distingue mayúsculas |
     * | `www.` fuera | `instagram.com` y `www.instagram.com` son lo mismo |
     * | barra final fuera | `/p/abc` y `/p/abc/` son lo mismo |
     * | fragmento (`#...`) fuera | nunca llega al servidor |
     * | parámetros de medición fuera | los añade la plataforma al compartir |
     * | resto de parámetros, **ordenados** | mismo conjunto, mismo orden, misma huella |
     *
     * **La ruta conserva sus mayúsculas.** `instagram.com/p/AbC` y `/p/abc` son
     * dos posts distintos.
     */
    public static function normalizar(string $url): string
    {
        $partes = parse_url(trim($url));

        if ($partes === false || ($partes['host'] ?? '') === '') {
            // Sin host no hay nada que normalizar. Se devuelve tal cual y el veto
            // se encarga: aquí no se decide si la URL vale, sólo cómo se escribe.
            return trim($url);
        }

        $esquema = mb_strtolower($partes['scheme'] ?? 'https');
        $host = mb_strtolower($partes['host']);

        if (str_starts_with($host, 'www.')) {
            $host = mb_substr($host, 4);
        }

        $ruta = rtrim($partes['path'] ?? '', '/');

        $consulta = '';

        if (($partes['query'] ?? '') !== '') {
            parse_str($partes['query'], $parametros);

            foreach (self::PARAMETROS_DE_MEDICION as $sobra) {
                unset($parametros[$sobra]);
            }

            // Ordenados: el mismo conjunto de parámetros escrito en otro orden es
            // la misma página, y sin esto serían dos huellas.
            ksort($parametros);

            if ($parametros !== []) {
                $consulta = '?'.http_build_query($parametros);
            }
        }

        return $esquema.'://'.$host.$ruta.$consulta;
    }

    /** La huella de una URL: `sha256` de su forma normalizada. */
    public static function huella(string $url): string
    {
        return hash('sha256', self::normalizar($url));
    }

    /**
     * La red social a la que pertenece un enlace, o `null`.
     *
     * Se deduce con `platforms.url_pattern`, que es una expresión regular y vive
     * en el catálogo desde `2.6`. Preguntarle la red a quien reporta sería un
     * desplegable que sólo sirve para elegir mal.
     */
    public static function redDe(string $url): ?object
    {
        $normalizada = self::normalizar($url);

        return DB::table('platforms')
            ->where('is_active', 1)
            ->whereNotNull('url_pattern')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'url_pattern'])
            ->first(static function (object $red) use ($normalizada, $url): bool {
                // Contra la normalizada Y contra la cruda: los patrones aceptan
                // `www.` y la normalización lo quita, así que probar sólo una de
                // las dos dejaría fuera la mitad de los enlaces reales.
                $patron = '#'.$red->url_pattern.'#i';

                return preg_match($patron, $normalizada) === 1
                    || preg_match($patron, trim($url)) === 1;
            });
    }

    /** La red que pide el brief de un entregable, o `null` si el formato no la fija. */
    public static function redDelBrief(int $entregableId): ?object
    {
        return DB::table('deliverables as d')
            ->join('campaign_requirements as r', 'r.id', '=', 'd.campaign_requirement_id')
            ->join('content_formats as f', 'f.id', '=', 'r.content_format_id')
            ->join('platforms as p', 'p.id', '=', 'f.platform_id')
            ->where('d.id', $entregableId)
            ->first(['p.id', 'p.code', 'p.name']);
    }

    /**
     * Por qué este post **no** se puede registrar, o lista vacía.
     *
     * Devuelve **todos** los motivos, como los vetos de `8.1` y `8.3`.
     *
     * @return list<string>
     */
    public static function vetoParaPublicar(object $entregable, string $url, ?string $cuando): array
    {
        $motivos = [];

        if ($entregable->approved_at === null || $entregable->approved_version_id === null) {
            // Se devuelve solo: sin aprobación, lo demás no importa todavía.
            return ['Solo se publica lo aprobado. Este entregable no lo esta: apruebelo antes de registrar el post.'];
        }

        if (self::yaTiene((int) $entregable->id)) {
            return ['Este entregable ya tiene un post registrado.'];
        }

        $enlace = trim($url);

        if (!str_starts_with(mb_strtolower($enlace), 'https://')) {
            $motivos[] = 'El enlace del post tiene que empezar por https://';
        }

        $red = self::redDe($enlace);

        if ($red === null) {
            $motivos[] = 'Ese enlace no es de ninguna red conocida. Pegue el enlace publico del post.';
        } else {
            $delBrief = self::redDelBrief((int) $entregable->id);

            if ($delBrief !== null && (int) $delBrief->id !== (int) $red->id) {
                $motivos[] = sprintf(
                    'El brief pide %s y ese enlace es de %s. Pegue el post de %s.',
                    $delBrief->name, $red->name, $delBrief->name,
                );
            }
        }

        if ($cuando !== null && Carbon::parse($cuando)->isAfter(now())) {
            $motivos[] = 'Un post no se puede haber publicado en el futuro.';
        }

        if ($red !== null && self::reclamadoPorOtro($enlace, (int) $entregable->id)) {
            $motivos[] = 'Ese post ya esta registrado en otro entregable. Un mismo post no cuenta dos veces.';
        }

        return $motivos;
    }

    /** ¿Este entregable ya tiene un post? Uno por entregable. */
    public static function yaTiene(int $entregableId): bool
    {
        return DB::table('publications')
            ->where('deliverable_id', $entregableId)
            ->whereNotIn('status', ['rejected'])
            ->exists();
    }

    /**
     * ¿Alguien más reclamó ya este post?
     *
     * Las **rechazadas no cuentan**, igual que en `uq_pub_fingerprint` desde
     * `8.7`: una publicación que se miró y no valía no reclama nada, y el enlace
     * tiene que poder volver a registrarse — que es justo lo que se le pide al
     * creador cuando se le rechaza por «el enlace no lleva a ningún post».
     */
    public static function reclamadoPorOtro(string $url, int $entregableId): bool
    {
        return DB::table('publications')
            ->where('url_fingerprint', self::huella($url))
            ->where('status', '!=', 'rejected')
            ->where('deliverable_id', '!=', $entregableId)
            ->exists();
    }

    /**
     * Registra el post. Devuelve su uuid.
     *
     * `published_at` y `created_at` salen del **mismo** instante cuando la fecha
     * no se da: `ck_pub_published_no_futuro` compara las dos, y dos `now()`
     * separados pueden caer a los dos lados de un milisegundo. Es literalmente el
     * fallo intermitente de `T-39`, y aquí sería un rechazo aleatorio en la cara
     * del creador.
     */
    public static function reportar(
        object $entregable,
        string $url,
        ?string $cuando,
        ?int $usuarioId,
        ?string $ip,
    ): string {
        $ahora = now();
        $enlace = trim($url);
        $red = self::redDe($enlace);
        $empaquetada = is_string($ip) ? inet_pton($ip) : false;
        $uuid = (string) Str::uuid();
        $publicado = $cuando !== null ? Carbon::parse($cuando) : $ahora;

        DB::transaction(function () use (
            $entregable, $enlace, $red, $publicado, $ahora, $usuarioId, $empaquetada, $uuid,
        ): void {
            DB::table('publications')->insert([
                'uuid' => $uuid,
                'deliverable_id' => $entregable->id,
                'deliverable_version_id' => $entregable->approved_version_id,
                'platform_id' => $red?->id,
                'url' => $enlace,
                'url_fingerprint' => self::huella($enlace),
                'published_at' => $publicado,
                'reported_by_user_id' => $usuarioId,
                'reported_ip' => $empaquetada === false ? null : $empaquetada,
                'status' => self::REPORTADA,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            // El entregable pasa a `published`. A partir de aquí no admite más
            // versiones ni más veredictos: lo impiden `tg_dv_entregable_abierto` y
            // `tg_cvw_entregable_abierto`, y con razón — hay un post en el aire.
            DB::table('deliverables')->where('id', $entregable->id)->update([
                'status' => 'published',
                'updated_at' => $ahora,
            ]);
        });

        Bitacora::registrar(
            accion: 'publication.reported',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $entregable->id,
            cambios: [
                'status' => ['antes' => $entregable->status, 'despues' => 'published'],
                'red' => ['antes' => null, 'despues' => $red?->code],
            ],
        );

        Eventos::ocurrio(
            nombre: 'publication.reported',
            tipoEntidad: 'deliverable',
            idEntidad: (int) $entregable->id,
            // La URL no: `domain_events` persiste su payload y el enlace ya vive
            // en `publications`, que es su sitio. Aquí basta el hecho.
            payload: ['red' => (string) ($red->code ?? ''), 'version' => (int) $entregable->approved_version_id],
        );

        return $uuid;
    }

    /**
     * Los posts de un entregable, el más reciente primero.
     *
     * @return Collection<int, \stdClass>
     */
    public static function de(int $entregableId): Collection
    {
        return DB::table('publications as pb')
            ->leftJoin('platforms as p', 'p.id', '=', 'pb.platform_id')
            ->leftJoin('users as u', 'u.id', '=', 'pb.reported_by_user_id')
            ->join('deliverable_versions as v', 'v.id', '=', 'pb.deliverable_version_id')
            ->where('pb.deliverable_id', $entregableId)
            ->orderByDesc('pb.published_at')
            ->get([
                'pb.uuid', 'pb.url', 'pb.status', 'pb.published_at', 'pb.verified_at',
                'p.name as red', 'u.name as reportado_por', 'v.version_number',
            ]);
    }
}
