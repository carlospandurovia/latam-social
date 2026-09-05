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
 * La portada pública, que es contenido y no plantilla (9.21b, L-3).
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
 * lee: el titular, las franjas, sus encabezados, su orden, los bloques, los
 * botones y lo que sale al compartir el enlace.
 *
 * ### L-3: la franja también es dato
 *
 * Antes la plantilla decidía cuántas franjas había, en qué orden salían y cómo
 * se llamaban —«Cómo funciona» y «Preguntas» estaban escritos en el Blade—. Ahora
 * eso son filas de `landing_sections`, y de ahí salen además **las anclas del
 * menú de la cabecera**: el menú no puede estar escrito a mano porque entonces
 * enseña secciones que se han apagado.
 */
final class Landing
{
    public const MARCAS = 'marcas';

    public const CREADORES = 'creadores';

    /**
     * Las formas de pintar una franja.
     *
     * Es un enum de **código**: cada valor tiene su parcial en
     * `resources/views/publico/secciones/`. Uno inventado desde el panel sería
     * una fila válida que ninguna plantilla sabe dibujar, que es exactamente el
     * criterio de `DEC-026` para no convertir esto en un catálogo.
     *
     * @var array<string, string>
     */
    public const LAYOUTS = [
        'cards' => 'Tarjetas — una rejilla de ventajas, con icono',
        'steps' => 'Pasos — numerados, en orden',
        'faq' => 'Preguntas — lista de pregunta y respuesta',
        'claim' => 'Claim — una idea sola, a página completa, con el degradado',
        'plain' => 'Texto — el encabezado y la bajada, sin bloques',
    ];

    /**
     * Los iconos que sabe dibujar `parciales/icono`.
     *
     * Se ofrecen en el panel para que nadie tenga que adivinar el nombre; pero
     * la base **no** los encierra en un `IN (...)`. Es deliberado: un nombre
     * desconocido pinta el icono genérico y no rompe nada, que es la misma regla
     * que las redes del pie en `L-2a`. Encerrarlos convertiría añadir un icono
     * en una migración.
     *
     * @var array<string, string>
     */
    public const ICONOS = [
        'personas' => 'Personas',
        'megafono' => 'Megáfono',
        'rayo' => 'Rayo',
        'escudo' => 'Escudo',
        'verificado' => 'Verificado',
        'grafico' => 'Gráfico',
        'reloj' => 'Reloj',
        'camara' => 'Cámara',
        'chat' => 'Conversación',
        'documento' => 'Documento',
        'moneda' => 'Moneda',
        'estrella' => 'Estrella',
    ];

    /** Lo que se lee de una sección. Escrito una vez: lo usan cuatro consultas. */
    private const CAMPOS_SECCION = [
        'id', 'code', 'layout', 'eyebrow', 'title', 'subtitle',
        'cta_label', 'cta_url', 'sort_order', 'is_visible', 'show_in_nav',
    ];

    /** @var list<string> */
    private const CAMPOS_BLOQUE = [
        'id', 'landing_section_id', 'heading', 'body', 'icon',
        'image_file_id', 'cta_label', 'cta_url', 'sort_order', 'is_visible',
    ];

    /**
     * La página con sus secciones y sus bloques, o `null` si no hay ninguna.
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

        $marcaId = Marca::idActual();

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

        $pagina->secciones = self::secciones((int) $pagina->id, soloVisibles: true);

        self::resolverMarcadores($pagina);

        return $pagina;
    }

    /**
     * Sustituye los marcadores de TODO lo que se lee en la portada (L-4).
     *
     * Aquí no es un adorno: la franja «por qué confiar» dice la razón social y
     * el identificador fiscal de la empresa, y escribirlos dentro del texto
     * sería `DEC-190` roto en el peor sitio —el día que cambien habría que
     * editar a mano una portada buscando dónde se nombra a la empresa, que es
     * exactamente lo que la `L-2b` evitó en los documentos legales—.
     *
     * La tabla de valores se resuelve **una vez** y se pasa a cada sustitución.
     * `aplicar()` la volvería a construir en cada texto, y son sesenta: sesenta
     * consultas por la sociedad operadora para pintar una página pública.
     */
    private static function resolverMarcadores(object $pagina): void
    {
        $valores = Reemplazos::valores();
        $poner = static fn (?string $t): ?string => $t === null
            ? null
            : Reemplazos::conValores($t, $valores);

        foreach (['headline', 'subheadline', 'form_heading', 'form_intro'] as $campo) {
            $pagina->{$campo} = $poner($pagina->{$campo} ?? null);
        }

        foreach ($pagina->secciones as $seccion) {
            foreach (['eyebrow', 'title', 'subtitle'] as $campo) {
                $seccion->{$campo} = $poner($seccion->{$campo});
            }

            foreach ($seccion->bloques as $bloque) {
                $bloque->heading = (string) $poner($bloque->heading);
                $bloque->body = $poner($bloque->body);
            }
        }
    }

    /**
     * Las franjas de una página, cada una con sus bloques ya dentro.
     *
     * Dos consultas y no una por franja: son pocas filas, pero «una consulta por
     * elemento» es la costumbre que convierte una portada en veinte consultas el
     * día que la portada crezca, y esto lo mira alguien que no es cliente.
     *
     * @return Collection<int, \stdClass>
     */
    public static function secciones(int $paginaId, bool $soloVisibles = false): Collection
    {
        $secciones = DB::table('landing_sections')
            ->where('landing_page_id', $paginaId)
            ->when($soloVisibles, fn ($q) => $q->where('is_visible', 1))
            ->orderBy('sort_order')->orderBy('id')
            ->get(self::CAMPOS_SECCION);

        if ($secciones->isEmpty()) {
            return $secciones;
        }

        $bloques = DB::table('landing_blocks')
            ->whereIn('landing_section_id', $secciones->pluck('id')->all())
            ->when($soloVisibles, fn ($q) => $q->where('is_visible', 1))
            ->orderBy('sort_order')->orderBy('id')
            ->get(self::CAMPOS_BLOQUE)
            ->groupBy('landing_section_id');

        return $secciones->each(static function (object $seccion) use ($bloques): void {
            $seccion->bloques = $bloques->get((int) $seccion->id, collect());
        });
    }

    /**
     * Las anclas del menú de la cabecera.
     *
     * Salen de la base y **no** de una lista escrita en la plantilla, por una
     * razón concreta: un menú escrito a mano sigue ofreciendo la franja que se
     * apagó ayer, y pulsarlo no hace nada —no da error, no va a ningún sitio—.
     * Aquí sólo entra lo que está visible y marcado para el menú.
     *
     * @return Collection<int, \stdClass>
     */
    public static function navegacion(int $paginaId): Collection
    {
        if (!Schema::hasTable('landing_sections')) {
            return collect();
        }

        return DB::table('landing_sections')
            ->where('landing_page_id', $paginaId)
            ->where('is_visible', 1)->where('show_in_nav', 1)
            ->whereNotNull('title')
            ->orderBy('sort_order')->orderBy('id')
            ->get(['code', 'title']);
    }

    /**
     * Las dos páginas para la pantalla de edición, con TODO dentro.
     *
     * @return Collection<int, \stdClass>
     */
    public static function todas(): Collection
    {
        $marcaId = Marca::idActual();

        if ($marcaId === null) {
            return collect();
        }

        return DB::table('landing_pages')
            ->where('platform_brand_id', $marcaId)
            ->orderByRaw("FIELD(code, 'marcas', 'creadores')")
            ->get()
            ->each(function (object $pagina): void {
                $pagina->secciones = self::secciones((int) $pagina->id);
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
            // L-4 (`C-3`): el cierre deja de repetir el boton. El formulario es
            // codigo --validacion, campo trampa, throttle-- pero sus palabras no.
            'form_heading' => self::oNulo($datos['form_heading'] ?? null),
            'form_intro' => self::oNulo($datos['form_intro'] ?? null),
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

    // ------------------------------------------------------------- secciones

    /**
     * Crea o actualiza una franja.
     *
     * @param array<string, mixed> $datos
     */
    public static function guardarSeccion(int $paginaId, ?int $seccionId, array $datos): int
    {
        $campos = [
            'code' => self::ancla((string) $datos['code']),
            'layout' => (string) $datos['layout'],
            'eyebrow' => self::oNulo($datos['eyebrow'] ?? null),
            'title' => self::oNulo($datos['title'] ?? null),
            'subtitle' => self::oNulo($datos['subtitle'] ?? null),
            'cta_label' => self::oNulo($datos['cta_label'] ?? null),
            'cta_url' => self::oNulo($datos['cta_url'] ?? null),
            'sort_order' => (int) ($datos['sort_order'] ?? 100),
            'is_visible' => (bool) ($datos['is_visible'] ?? false),
            'show_in_nav' => (bool) ($datos['show_in_nav'] ?? false),
            'updated_at' => now(),
        ];

        if ($seccionId === null) {
            return (int) DB::table('landing_sections')->insertGetId(
                $campos + ['landing_page_id' => $paginaId, 'created_at' => now()],
            );
        }

        DB::table('landing_sections')
            ->where('id', $seccionId)->where('landing_page_id', $paginaId)
            ->update($campos);

        return $seccionId;
    }

    /**
     * Una franja se borra, y con ella sus bloques.
     *
     * En este proyecto no se borra nada —evidencias, números, políticas— porque
     * todo eso **sostiene una cifra o una firma**. Una franja de la portada no
     * sostiene nada: es texto de marketing que se cambia veinte veces. Guardarlo
     * para siempre sería confundir «no perder información» con «acumular».
     *
     * Los bloques se quitan a mano y no con `ON DELETE CASCADE`: la foránea es
     * `RESTRICT` en todo el proyecto y una excepción escondida en el esquema es
     * la clase de cosa que un día se lleva por delante algo que sí importaba.
     */
    public static function borrarSeccion(int $paginaId, int $seccionId): void
    {
        $seccion = DB::table('landing_sections')
            ->where('id', $seccionId)->where('landing_page_id', $paginaId)
            ->first(['id']);

        if ($seccion === null) {
            return;
        }

        DB::transaction(static function () use ($seccionId): void {
            DB::table('landing_blocks')->where('landing_section_id', $seccionId)->delete();
            DB::table('landing_sections')->where('id', $seccionId)->delete();
        });
    }

    // --------------------------------------------------------------- bloques

    /**
     * Crea o actualiza un bloque dentro de una franja.
     *
     * @param array<string, mixed> $datos
     */
    public static function guardarBloque(int $seccionId, ?int $bloqueId, array $datos): int
    {
        $campos = [
            'heading' => trim((string) $datos['heading']),
            'body' => self::oNulo($datos['body'] ?? null),
            'icon' => self::oNulo($datos['icon'] ?? null),
            'cta_label' => self::oNulo($datos['cta_label'] ?? null),
            'cta_url' => self::oNulo($datos['cta_url'] ?? null),
            'sort_order' => (int) ($datos['sort_order'] ?? 100),
            'is_visible' => (bool) ($datos['is_visible'] ?? false),
            'updated_at' => now(),
        ];

        if ($bloqueId === null) {
            return (int) DB::table('landing_blocks')->insertGetId(
                $campos + ['landing_section_id' => $seccionId, 'created_at' => now()],
            );
        }

        DB::table('landing_blocks')
            ->where('id', $bloqueId)->where('landing_section_id', $seccionId)
            ->update($campos);

        return $bloqueId;
    }

    public static function borrarBloque(int $seccionId, int $bloqueId): void
    {
        DB::table('landing_blocks')
            ->where('id', $bloqueId)->where('landing_section_id', $seccionId)
            ->delete();
    }

    /** La franja a la que pertenece un bloque, para comprobar que es de esta página. */
    public static function seccionDe(int $paginaId, int $seccionId): ?object
    {
        return DB::table('landing_sections')
            ->where('id', $seccionId)->where('landing_page_id', $paginaId)
            ->first(['id', 'code', 'layout']);
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

        $sinSecciones = $paginas->filter(
            static fn (object $p): bool => $p->secciones->where('is_visible', 1)->isEmpty(),
        )->pluck('code');

        if ($sinSecciones->isNotEmpty()) {
            $avisos[] = Aviso::ambar(sprintf(
                'Sólo con el titular, sin ninguna franja visible: %s.',
                $sinSecciones->implode(', '),
            ));
        }

        // L-3: una franja encendida y vacia es un encabezado sobre un hueco. Es
        // el mismo defecto que la columna «Contacto» del pie en `L-1`, que se
        // vio MIRANDO la pantalla: promete algo que no esta.
        $vacias = $paginas->flatMap(
            static fn (object $p): Collection => $p->secciones
                ->filter(static fn (object $s): bool => (bool) $s->is_visible
                    && $s->layout !== 'plain' && $s->layout !== 'claim'
                    && $s->bloques->where('is_visible', 1)->isEmpty())
                ->map(static fn (object $s): string => $p->code.'/'.$s->code),
        );

        if ($vacias->isNotEmpty()) {
            $avisos[] = Aviso::ambar(sprintf(
                'Franjas encendidas y sin ningún bloque visible: %s. Se pinta el encabezado sobre '
                .'un hueco.',
                $vacias->implode(', '),
            ));
        }

        // Sin menu, quien baja y no se decide no tiene como volver arriba: es el
        // defecto `C-1` de la auditoria, y es el que mas conversion cuesta.
        $sinMenu = $paginas->filter(
            static fn (object $p): bool => $p->secciones
                ->where('is_visible', 1)->where('show_in_nav', 1)->isEmpty(),
        )->pluck('code');

        if ($sinMenu->isNotEmpty()) {
            $avisos[] = Aviso::ambar(sprintf(
                'Sin ninguna franja en el menú de la cabecera: %s. Quien baje y no se decida no '
                .'tendrá a dónde volver.',
                $sinMenu->implode(', '),
            ));
        }

        // L-4: un marcador que no se puede resolver sale en la CALLE como una
        // raya. Es la misma regla que `Paginas::marcadoresSinResolver()` en la
        // `L-2b`, y aqui pesa mas: un documento legal lo lee quien ya es
        // cliente, y esto lo lee quien esta decidiendo si te escribe.
        $sinResolver = [];

        foreach ($paginas as $p) {
            foreach ($p->secciones as $s) {
                foreach ([$s->eyebrow, $s->title, $s->subtitle] as $texto) {
                    foreach (Reemplazos::sinResolver((string) $texto) as $m) {
                        $sinResolver[$m] = true;
                    }
                }

                foreach ($s->bloques as $b) {
                    foreach ([$b->heading, $b->body] as $texto) {
                        foreach (Reemplazos::sinResolver((string) $texto) as $m) {
                            $sinResolver[$m] = true;
                        }
                    }
                }
            }

            foreach ([$p->headline, $p->subheadline, $p->form_heading, $p->form_intro] as $texto) {
                foreach (Reemplazos::sinResolver((string) $texto) as $m) {
                    $sinResolver[$m] = true;
                }
            }
        }

        if ($sinResolver !== []) {
            $avisos[] = Aviso::rojo(sprintf(
                'La portada nombra datos que no están configurados y saldrán como una raya: %s. '
                .'Se completan en «Sitio público» y en «Entidades legales».',
                implode(', ', array_keys($sinResolver)),
            ));
        }

        return $avisos;
    }

    // ------------------------------------------------------------- auxiliares

    /**
     * Un ancla que de verdad ancle.
     *
     * Se normaliza aquí y además lo comprueba la base (`ck_ls_code`). Las dos
     * cosas: aquí para que escribir «Cómo funciona» en el panel produzca
     * `como-funciona` sin que nadie tenga que saber la regla, y en la base
     * porque una fila puede entrar por otro camino —una importación, una
     * consola— y un ancla rota no da ningún error: el enlace simplemente no
     * hace nada al pulsarlo.
     */
    public static function ancla(string $texto): string
    {
        $limpio = strtr(
            mb_strtolower(trim($texto)),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'],
        );

        $limpio = trim((string) preg_replace('/[^a-z0-9]+/', '-', $limpio), '-');

        return $limpio === '' ? 'seccion' : mb_substr($limpio, 0, 40);
    }

    private static function oNulo(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : $texto;
    }
}
