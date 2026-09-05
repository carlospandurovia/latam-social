<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * La portada pública, que es contenido y no plantilla (9.21b).
 *
 * ### Por qué esto no vive en un `.blade.php`
 *
 * Porque es white label (`DEC-190`). Un titular escrito en la plantilla es
 * «LATAM Social» escrito en tres sitios otra vez —lo que `9.17` tuvo que
 * arreglar— pero **peor**: la marca del panel la ve quien ya entró; esto lo ve
 * quien todavía está decidiendo si te escribe.
 *
 * ### Dos páginas, no un gestor de contenidos
 *
 * `marcas` y `creadores`, y ya. Cada una tiene su formulario, su público y su
 * plantilla, así que son **código** (el criterio de `DEC-026`): una tercera
 * creada desde el panel sería una fila perfectamente válida que ninguna ruta
 * sabe servir. Lo que sí es dato —y por eso está en tablas— es todo lo que se
 * lee: el titular, los bloques, el botón y lo que sale al compartir el enlace.
 */
final class Landing
{
    public const MARCAS = 'marcas';

    public const CREADORES = 'creadores';

    /** @var array<string, string> */
    public const TIPOS_DE_BLOQUE = [
        'feature' => 'Ventaja — qué gana quien lo lee',
        'step' => 'Paso — cómo funciona, en orden',
        'faq' => 'Pregunta — lo que preguntan antes de decidirse',
    ];

    /**
     * La página con sus bloques visibles, o `null` si no hay ninguna.
     *
     * Devuelve `null` y no lanza: una instalación recién migrada y sin sembrar
     * no tiene portada, y eso **no puede ser un 500 en la cara de un visitante**.
     * Quien llama decide —y decide llevar al acceso, que es lo que había antes—.
     *
     * Una portada **apagada** también devuelve `null`: para quien mira desde
     * fuera, apagada y sin sembrar son lo mismo. El editor del admin no pasa por
     * aquí —usa `todas()`— así que sigue viéndolas todas.
     */
    public static function portada(string $code): ?object
    {
        if (!Schema::hasTable('landing_pages')) {
            return null;
        }

        $marcaId = Marca::actual()?->id;

        if ($marcaId === null) {
            return null;
        }

        $pagina = DB::table('landing_pages')
            ->where('platform_brand_id', $marcaId)
            ->where('code', $code)
            // L-1: la publicacion se comprueba AQUI y no en cada consumidor.
            // Hasta hoy la regla vivia dentro de `PortadaController` --el unico
            // que la habia necesitado-- y al escribir el `sitemap.xml` salio lo
            // que eso significa: el mapa ofrecia a un buscador una portada
            // apagada, que redirige al acceso. Una regla que vive en un
            // consumidor es una regla que el segundo consumidor no tiene.
            ->where('is_published', true)
            ->first();

        if ($pagina === null) {
            return null;
        }

        $pagina->bloques = self::bloques((int) $pagina->id, soloVisibles: true);

        return $pagina;
    }

    /** @return Collection<int, \stdClass> */
    public static function bloques(int $paginaId, bool $soloVisibles = false): Collection
    {
        return DB::table('landing_blocks')
            ->where('landing_page_id', $paginaId)
            ->when($soloVisibles, fn ($q) => $q->where('is_visible', 1))
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'kind', 'heading', 'body', 'sort_order', 'is_visible']);
    }

    /**
     * Las dos páginas para la pantalla de edición, con TODOS sus bloques.
     *
     * @return Collection<int, \stdClass>
     */
    public static function todas(): Collection
    {
        $marcaId = Marca::actual()?->id;

        if ($marcaId === null) {
            return collect();
        }

        return DB::table('landing_pages')
            ->where('platform_brand_id', $marcaId)
            ->orderByRaw("FIELD(code, 'marcas', 'creadores')")
            ->get()
            ->each(function (object $pagina): void {
                $pagina->bloques = self::bloques((int) $pagina->id);
            });
    }

    /**
     * Guarda el texto de una página.
     *
     * @param array<string, mixed> $datos
     */
    public static function guardar(int $paginaId, array $datos): void
    {
        $fila = DB::table('landing_pages')->where('id', $paginaId)->first(['id', 'code']);

        if ($fila === null) {
            throw new RuntimeException('No existe esa portada.');
        }

        DB::table('landing_pages')->where('id', $paginaId)->update([
            'headline' => trim((string) $datos['headline']),
            'subheadline' => ($datos['subheadline'] ?? '') !== '' ? (string) $datos['subheadline'] : null,
            'cta_label' => trim((string) $datos['cta_label']),
            'cta_url' => ($datos['cta_url'] ?? '') !== '' ? (string) $datos['cta_url'] : null,
            'meta_title' => ($datos['meta_title'] ?? '') !== '' ? (string) $datos['meta_title'] : null,
            'meta_description' => ($datos['meta_description'] ?? '') !== '' ? (string) $datos['meta_description'] : null,
            'is_published' => (bool) ($datos['is_published'] ?? false),
            'updated_at' => now(),
        ]);

        // Lo que se ensena en la calle deja huella: quien lo cambio y cuando.
        Bitacora::registrar(
            accion: 'landing.updated',
            tipoEntidad: 'landing_page',
            idEntidad: $paginaId,
            cambios: ['pagina' => ['antes' => (string) $fila->code, 'despues' => (string) $fila->code]],
        );
    }

    /**
     * Crea o actualiza un bloque.
     *
     * @param array<string, mixed> $datos
     */
    public static function guardarBloque(int $paginaId, ?int $bloqueId, array $datos): int
    {
        $campos = [
            'kind' => (string) $datos['kind'],
            'heading' => trim((string) $datos['heading']),
            'body' => ($datos['body'] ?? '') !== '' ? (string) $datos['body'] : null,
            'sort_order' => (int) ($datos['sort_order'] ?? 100),
            'is_visible' => (bool) ($datos['is_visible'] ?? false),
            'updated_at' => now(),
        ];

        if ($bloqueId === null) {
            return (int) DB::table('landing_blocks')->insertGetId(
                $campos + ['landing_page_id' => $paginaId, 'created_at' => now()],
            );
        }

        DB::table('landing_blocks')
            ->where('id', $bloqueId)->where('landing_page_id', $paginaId)
            ->update($campos);

        return $bloqueId;
    }

    /**
     * Un bloque sí se borra, y es la diferencia con todo lo demás.
     *
     * En este proyecto no se borra nada —evidencias, números, políticas— porque
     * todo eso **sostiene una cifra o una firma**. Un párrafo de la portada no
     * sostiene nada: es texto de marketing que se cambia veinte veces. Guardarlo
     * para siempre sería confundir «no perder información» con «acumular».
     */
    public static function borrarBloque(int $paginaId, int $bloqueId): void
    {
        DB::table('landing_blocks')
            ->where('id', $bloqueId)->where('landing_page_id', $paginaId)
            ->delete();
    }

    // --------------------------------------------------------------- avisos

    /** @return list<Aviso> */
    public static function avisos(): array
    {
        if (!Schema::hasTable('landing_pages')) {
            return [];
        }

        $avisos = [];
        $paginas = self::todas();

        if ($paginas->isEmpty()) {
            return [Aviso::rojo(
                'No hay ninguna portada: la dirección pública lleva al acceso. Se siembran con '
                .'`php artisan db:seed --class=CimientosSeeder`.',
            )];
        }

        $apagadas = $paginas->where('is_published', 0)->pluck('code');

        if ($apagadas->isNotEmpty()) {
            $avisos[] = Aviso::ambar(sprintf(
                'Sin publicar: %s. Quien entre verá la pantalla de acceso, no un error.',
                $apagadas->implode(', '),
            ));
        }

        // Lo que sale en Google y al compartir el enlace por WhatsApp. Sin esto
        // se comparte con el titulo del navegador, que no dice nada de lo que
        // hay dentro --y es la primera impresion de alguien que no te conoce--.
        $sinMeta = $paginas->filter(
            static fn (object $p): bool => $p->meta_description === null || trim((string) $p->meta_description) === '',
        )->pluck('code');

        if ($sinMeta->isNotEmpty()) {
            $avisos[] = Aviso::ambar(sprintf(
                'Sin descripción para buscadores: %s. Al compartir el enlace saldrá el título del '
                .'navegador, que no cuenta nada.',
                $sinMeta->implode(', '),
            ));
        }

        $sinBloques = $paginas->filter(
            static fn (object $p): bool => $p->bloques->where('is_visible', 1)->isEmpty(),
        )->pluck('code');

        if ($sinBloques->isNotEmpty()) {
            $avisos[] = Aviso::ambar(sprintf(
                'Sólo con el titular, sin ningún bloque visible: %s.',
                $sinBloques->implode(', '),
            ));
        }

        return $avisos;
    }
}
