<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use App\Shared\Texto\Marcado;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Las páginas públicas del sitio (L-2b).
 *
 * ### Qué vive aquí y qué no
 *
 * Aquí: la política de privacidad, el aviso legal, «sobre nosotros», las
 * cookies. Páginas que se publican y se leen.
 *
 * **No** el contrato del creador: ése vive en `terms_versions` desde `9.16`, con
 * su aceptación registrada apuntando a una versión concreta. Duplicarlo aquí
 * sería tener dos verdades sobre lo mismo.
 *
 * ### Una página no puede tapar una ruta
 *
 * Si alguien crea la página `creadores`, la portada de creadores deja de
 * abrirse. La lista de direcciones prohibidas **no se escribe a mano**: se
 * calcula preguntándole al enrutador cuáles son sus primeros segmentos. Una
 * lista escrita se queda vieja el día que se añade una ruta, y el fallo aparece
 * meses después con la forma de «una portada dejó de funcionar».
 */
final class Paginas
{
    /** @var array<string, string> */
    public const REVISION = [
        'sin_revisar' => 'Sin revisar por un abogado',
        'en_revision' => 'En revisión',
        'revisado' => 'Revisado',
    ];

    /** Las que el sistema necesita y no se borran. */
    public const DEL_SISTEMA = ['politica-de-privacidad', 'terminos-y-condiciones'];

    /**
     * Las páginas del pie, publicadas y en su orden.
     *
     * @return Collection<int, \stdClass>
     */
    public static function delPie(): Collection
    {
        if (!Schema::hasTable('content_pages')) {
            return collect();
        }

        $marcaId = Marca::idActual();

        if ($marcaId === null) {
            return collect();
        }

        // Solo las que TIENEN texto publicado. Una pagina creada y sin publicar
        // enlazada desde el pie es un 404 en la portada.
        return DB::table('content_pages as cp')
            ->join('content_page_versions as v', function ($union): void {
                $union->on('v.content_page_id', '=', 'cp.id')
                    ->whereNotNull('v.published_at')
                    ->whereNull('v.effective_to');
            })
            ->where('cp.platform_brand_id', $marcaId)
            ->where('cp.show_in_footer', true)
            ->orderBy('cp.sort_order')->orderBy('cp.title')
            ->get(['cp.slug', 'cp.title']);
    }

    /**
     * Todas, con su estado, para el admin.
     *
     * @return Collection<int, \stdClass>
     */
    public static function todas(): Collection
    {
        if (!Schema::hasTable('content_pages')) {
            return collect();
        }

        return DB::table('content_pages as cp')
            ->leftJoin('content_page_versions as v', function ($union): void {
                $union->on('v.content_page_id', '=', 'cp.id')
                    ->whereNotNull('v.published_at')
                    ->whereNull('v.effective_to');
            })
            ->where('cp.platform_brand_id', Marca::idActual())
            ->orderBy('cp.sort_order')->orderBy('cp.title')
            ->get(['cp.id', 'cp.uuid', 'cp.slug', 'cp.title', 'cp.show_in_footer',
                'cp.sort_order', 'cp.is_system', 'cp.meta_title', 'cp.meta_description',
                'v.version', 'v.effective_from', 'v.review_status', 'v.published_at']);
    }

    /**
     * La página pública lista para pintar, o `null`.
     *
     * Devuelve el cuerpo **ya sustituido y ya convertido a HTML seguro**. Quien
     * pinta no tiene que acordarse de hacer ninguna de las dos cosas, que es
     * exactamente el tipo de olvido que produce un `{{empresa.razon_social}}`
     * visible en producción —o algo peor—.
     */
    public static function publica(string $slug): ?object
    {
        $pagina = self::conVigente($slug);

        if ($pagina === null) {
            return null;
        }

        $extra = [
            'pagina.titulo' => (string) $pagina->title,
            'pagina.vigente_desde' => self::enPalabras((string) $pagina->effective_from),
        ];

        $pagina->cuerpo = Marcado::aHtml(
            Reemplazos::aplicar((string) $pagina->body_markdown, $extra),
        );

        return $pagina;
    }

    /** La página con su versión vigente, sin tocar el texto. */
    public static function conVigente(string $slug): ?object
    {
        if (!Schema::hasTable('content_pages')) {
            return null;
        }

        $marcaId = Marca::idActual();

        return $marcaId === null ? null : DB::table('content_pages as cp')
            ->join('content_page_versions as v', function ($union): void {
                $union->on('v.content_page_id', '=', 'cp.id')
                    ->whereNotNull('v.published_at')
                    ->whereNull('v.effective_to');
            })
            ->where('cp.platform_brand_id', $marcaId)
            ->where('cp.slug', $slug)
            ->first(['cp.id', 'cp.slug', 'cp.title', 'cp.meta_title', 'cp.meta_description',
                'v.id as version_id', 'v.version', 'v.body_markdown', 'v.effective_from',
                'v.review_status', 'v.published_at']);
    }

    /** El borrador de una página, si lo hay. */
    public static function borrador(int $paginaId): ?object
    {
        return DB::table('content_page_versions')
            ->where('content_page_id', $paginaId)->whereNull('published_at')
            ->orderByDesc('id')->first();
    }

    /** @return Collection<int, \stdClass> */
    public static function historial(int $paginaId): Collection
    {
        return DB::table('content_page_versions as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.published_by_user_id')
            ->where('v.content_page_id', $paginaId)
            ->orderByDesc('v.id')
            ->get(['v.id', 'v.version', 'v.effective_from', 'v.effective_to', 'v.published_at',
                'v.review_status', 'v.content_sha256', 'u.name as autor']);
    }

    /**
     * Las direcciones que una página **no** puede ocupar.
     *
     * Se calculan del enrutador, no de una lista escrita: el día que se añada
     * una ruta pública nueva, ésta se entera sola.
     *
     * @return list<string>
     */
    public static function reservadas(): array
    {
        $reservadas = ['backoffice', 'robots.txt', 'sitemap.xml',
            // Lo que sirve el servidor web y nunca llega a PHP.
            'build', 'img', 'css', 'js', 'storage', 'vendor', 'favicon.ico'];

        /** @var \Illuminate\Routing\Route $ruta */
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $primero = explode('/', $ruta->uri())[0];

            if ($primero !== '' && $primero !== '/' && !str_contains($primero, '{')) {
                $reservadas[] = $primero;
            }
        }

        sort($reservadas);

        return array_values(array_unique($reservadas));
    }

    /** @param array<string, mixed> $datos */
    public static function guardar(?string $uuid, array $datos, int $usuarioId): string
    {
        $marcaId = Marca::idActual();

        if ($marcaId === null) {
            throw new RuntimeException('No hay ninguna marca configurada.');
        }

        if ($uuid === null) {
            $uuid = (string) Str::uuid();
            DB::table('content_pages')->insert($datos + [
                'uuid' => $uuid, 'platform_brand_id' => $marcaId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            // Una pagina del sistema no cambia de direccion: su enlace vive en
            // correos y contratos que ya salieron.
            if (self::esDelSistema($uuid)) {
                unset($datos['slug']);
            }

            DB::table('content_pages')->where('uuid', $uuid)
                ->where('platform_brand_id', $marcaId)->update($datos + ['updated_at' => now()]);
        }

        Bitacora::registrar('content_page.saved', 'content_page', null,
            ['pagina' => ['antes' => null, 'despues' => (string) ($datos['title'] ?? $uuid)]]);

        return $uuid;
    }

    /** Guarda el borrador. Sin versión todavía: eso se decide al publicar. */
    public static function guardarBorrador(string $uuid, string $markdown): void
    {
        $pagina = self::porUuid($uuid);
        $borrador = self::borrador((int) $pagina->id);

        $campos = [
            'body_markdown' => $markdown,
            'content_sha256' => hash('sha256', $markdown),
            'updated_at' => now(),
        ];

        if ($borrador === null) {
            DB::table('content_page_versions')->insert($campos + [
                'uuid' => (string) Str::uuid(),
                'content_page_id' => (int) $pagina->id,
                'version' => self::siguienteVersion((int) $pagina->id),
                'effective_from' => now()->toDateString(),
                'created_at' => now(),
            ]);
        } else {
            DB::table('content_page_versions')->where('id', $borrador->id)->update($campos);
        }

        Bitacora::registrar('content_page.draft_saved', 'content_page', (int) $pagina->id);
    }

    /**
     * Publica el borrador: cierra la vigente y abre ésta.
     *
     * Las dos cosas en **una transacción**, porque entre cerrar y abrir hay un
     * instante en el que la página no tiene versión vigente, y si algo falla
     * justo ahí el sitio se queda sin política de privacidad.
     */
    public static function publicar(string $uuid, string $desde, int $usuarioId): void
    {
        $pagina = self::porUuid($uuid);
        $borrador = self::borrador((int) $pagina->id);

        if ($borrador === null) {
            throw new RuntimeException('Esa página no tiene ningún borrador que publicar.');
        }

        DB::transaction(function () use ($pagina, $borrador, $desde, $usuarioId): void {
            DB::table('content_page_versions')
                ->where('content_page_id', $pagina->id)
                ->whereNotNull('published_at')->whereNull('effective_to')
                // El dia ANTES: dos vigencias no pueden compartir un dia, y la
                // nueva empieza el que se pidio.
                ->update(['effective_to' => date('Y-m-d', strtotime($desde.' -1 day')),
                    'updated_at' => now()]);

            DB::table('content_page_versions')->where('id', $borrador->id)->update([
                'effective_from' => $desde,
                'published_at' => now(),
                'published_by_user_id' => $usuarioId,
                'updated_at' => now(),
            ]);
        });

        Bitacora::registrar('content_page.published', 'content_page', (int) $pagina->id,
            ['version' => ['antes' => null, 'despues' => (string) $borrador->version]]);
    }

    /** @param array<string, mixed> $datos */
    public static function marcarRevision(string $uuid, array $datos): void
    {
        $pagina = self::porUuid($uuid);

        // Se puede anotar sobre la VIGENTE aunque este publicada: el disparador
        // protege el TEXTO, no el estado de la revision juridica. Justo al
        // reves seria absurdo --un abogado revisa lo que ya esta publicado--.
        DB::table('content_page_versions')
            ->where('content_page_id', $pagina->id)
            ->whereNotNull('published_at')->whereNull('effective_to')
            ->update($datos + ['updated_at' => now()]);

        Bitacora::registrar('content_page.reviewed', 'content_page', (int) $pagina->id,
            ['estado' => ['antes' => null, 'despues' => (string) ($datos['review_status'] ?? '')]]);
    }

    public static function porUuid(string $uuid): object
    {
        $pagina = DB::table('content_pages')->where('uuid', $uuid)
            ->where('platform_brand_id', Marca::idActual())->first();

        if ($pagina === null) {
            throw new RuntimeException('Esa página no existe.');
        }

        return $pagina;
    }

    public static function esDelSistema(string $uuid): bool
    {
        return (bool) DB::table('content_pages')->where('uuid', $uuid)->value('is_system');
    }

    public static function borrar(string $uuid): void
    {
        if (self::esDelSistema($uuid)) {
            throw new RuntimeException(
                'Esa página la necesita el sistema y no se borra. Su texto se puede cambiar entero.',
            );
        }

        $pagina = self::porUuid($uuid);

        DB::transaction(function () use ($pagina): void {
            DB::table('content_page_versions')->where('content_page_id', $pagina->id)->delete();
            DB::table('content_pages')->where('id', $pagina->id)->delete();
        });

        Bitacora::registrar('content_page.deleted', 'content_page', (int) $pagina->id);
    }

    /**
     * Lo que falta, con prioridad y sin bloquear nada (`DEC-190`).
     *
     * @return list<Aviso>
     */
    public static function avisos(): array
    {
        if (!Schema::hasTable('content_pages')) {
            return [];
        }

        $avisos = [];

        foreach (self::todas() as $pagina) {
            // Rojo: una pagina del sistema sin publicar es un enlace que el pie
            // no puede pintar, y en el caso de la privacidad es ademas un
            // requisito para publicar un dominio.
            if ($pagina->published_at === null) {
                $avisos[] = $pagina->is_system
                    ? Aviso::rojo(sprintf(
                        'La página «%s» tiene su texto escrito y NO está publicada, así que el sitio '
                        .'todavía no la enseña. Se publica con un clic desde su pantalla; la primera '
                        .'publicación la hace una persona a propósito, porque queda escrito quién '
                        .'la publicó.',
                        (string) $pagina->title,
                    ))
                    : Aviso::ambar(sprintf(
                        'La página «%s» está creada pero sin publicar: no se ve.',
                        (string) $pagina->title,
                    ));

                continue;
            }

            // §56: un supuesto legal se identifica EXPLICITAMENTE para revision
            // juridica. No bloquea publicar; lo dice mientras siga asi.
            if ($pagina->is_system && $pagina->review_status === 'sin_revisar') {
                $avisos[] = Aviso::ambar(sprintf(
                    'La página «%s» está publicada con un texto que NO ha revisado ningún abogado. '
                    .'Es un texto de partida escrito a estándar de industria, no un dictamen: '
                    .'hágalo revisar antes de darle tráfico al dominio.',
                    (string) $pagina->title,
                ));
            }
        }

        // Ambar: un valor de FABRICA que se cuela en un documento publico.
        //
        // Salio mirando la politica de privacidad publicada: el domicilio decia
        // «Por completar, Perú», que es lo que siembra `CimientosSeeder` para
        // que la sociedad exista. Un marcador que se resuelve no es lo mismo que
        // un marcador que se resuelve BIEN, y esto lo lee un tercero.
        foreach (Reemplazos::valores() as $clave => $valor) {
            if (mb_stripos($valor, 'por completar') !== false) {
                $avisos[] = Aviso::ambar(sprintf(
                    'El dato «%s» todavía dice «%s», que es el valor de partida, y sale así en las '
                    .'páginas publicadas. Se completa en la sociedad operadora.',
                    $clave, $valor,
                ));
            }
        }

        // Rojo: marcadores que no se resuelven. Un documento legal que dice
        // «El responsable del tratamiento es —» no nombra a nadie.
        $faltan = self::marcadoresSinResolver();

        if ($faltan !== []) {
            $avisos[] = Aviso::rojo(sprintf(
                'Hay %s que ninguna configuración resuelve, y en la página se pintan como una raya: %s. '
                .'Se rellenan en Sitio público, en Marca o en la sociedad operadora.',
                count($faltan) === 1 ? 'un dato' : count($faltan).' datos',
                implode(', ', $faltan),
            ));
        }

        return $avisos;
    }

    /** @return list<string> */
    public static function marcadoresSinResolver(): array
    {
        $faltan = [];

        foreach (self::todas() as $pagina) {
            if ($pagina->published_at === null) {
                continue;
            }

            $texto = (string) DB::table('content_page_versions')
                ->where('content_page_id', $pagina->id)
                ->whereNotNull('published_at')->whereNull('effective_to')
                ->value('body_markdown');

            foreach (Reemplazos::sinResolver($texto, [
                'pagina.titulo' => (string) $pagina->title,
                'pagina.vigente_desde' => (string) $pagina->effective_from,
            ]) as $marcador) {
                if (!in_array($marcador, $faltan, true)) {
                    $faltan[] = $marcador;
                }
            }
        }

        return $faltan;
    }

    /** «1.0», «1.1», «1.2»… El número no lo teclea nadie: se cuenta. */
    private static function siguienteVersion(int $paginaId): string
    {
        $cuantas = DB::table('content_page_versions')->where('content_page_id', $paginaId)->count();

        return '1.'.$cuantas;
    }

    private static function enPalabras(string $fecha): string
    {
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
            'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $t = strtotime($fecha);

        return $t === false
            ? $fecha
            : sprintf('%d de %s de %d', (int) date('j', $t), $meses[(int) date('n', $t)], (int) date('Y', $t));
    }
}
