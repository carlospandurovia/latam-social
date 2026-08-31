<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Database\Vigencia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Los términos, editables desde el admin (9.16).
 *
 * ### Lo que cambia respecto de 3.5
 *
 * Publicar términos era un comando de consola, y sin términos publicados no se
 * activa ningún creador. Eso convertía una **configuración** en un bloqueo. El
 * sistema arranca ahora con un texto base sembrado y todo se cambia desde la
 * pantalla; el comando sigue existiendo para el despliegue.
 *
 * ### Borrador, publicada, cerrada
 *
 * Un borrador (`published_at IS NULL`) se edita libremente. Al publicarlo se
 * congela —`tg_terms_inmutable`— y cierra la versión anterior el día antes, así
 * que nunca hay dos vigentes el mismo día.
 *
 * ### El cambio menor
 *
 * Al publicar hay que declarar si el cambio es **de fondo** o **menor**. De
 * fondo: todos vuelven a aceptar. Menor: la aceptación anterior sigue valiendo.
 *
 * `versionesQueValen()` es donde eso se hace verdad: recorre hacia atrás la
 * cadena de versiones mientras cada una se declaró menor, y devuelve todas las
 * que cuentan. Un `fondo` corta la cadena.
 *
 * **Lo decide una persona y queda escrito.** Nadie puede deducir después que
 * aquello fue menor: la fila dice quién lo declaró y cuándo.
 */
final class Terminos
{
    /** @var array<string, string> */
    public const REVISION = [
        'sin_revisar' => 'Sin revisión legal',
        'en_revision' => 'En revisión legal',
        'revisado' => 'Revisado legalmente',
    ];

    /** @var array<string, string> */
    public const CAMBIO = [
        'fondo' => 'De fondo — todos vuelven a aceptar',
        'menor' => 'Menor — la aceptación anterior sigue valiendo',
    ];

    public const AUDIENCIAS = ['creator' => 'Creadores', 'client' => 'Clientes'];

    public static function codigo(): string
    {
        return (string) config('latam.terminos.creador', 'creator_terms');
    }

    /** La versión vigente de un documento, o `null` si no hay ninguna publicada. */
    public static function vigente(string $codigo): ?object
    {
        return DB::table('terms_versions')
            ->where('code', $codigo)
            ->whereNull('effective_to')
            ->whereNotNull('published_at')
            ->first();
    }

    /**
     * Todas las versiones de un documento, la más reciente arriba.
     *
     * @return Collection<int, \stdClass>
     */
    public static function versiones(string $codigo): Collection
    {
        return DB::table('terms_versions as tv')
            ->leftJoin('users as u', 'u.id', '=', 'tv.published_by_user_id')
            ->where('tv.code', $codigo)
            ->orderByRaw('tv.published_at IS NULL DESC')
            ->orderByDesc('tv.effective_from')
            ->orderByDesc('tv.id')
            ->get(['tv.id', 'tv.uuid', 'tv.version', 'tv.title', 'tv.audience',
                'tv.effective_from', 'tv.effective_to', 'tv.published_at',
                'tv.review_status', 'tv.review_note', 'tv.change_type',
                'tv.content_sha256', 'u.name as publicador',
                DB::raw('CHAR_LENGTH(tv.body) as largo'),
                DB::raw('(SELECT COUNT(*) FROM terms_acceptances ta '
                    .'WHERE ta.terms_version_id = tv.id) as aceptaciones')]);
    }

    public static function porUuid(string $uuid): object
    {
        $version = DB::table('terms_versions')->where('uuid', $uuid)->first();

        if ($version === null) {
            throw new RuntimeException('No existe esa version de los terminos.');
        }

        return $version;
    }

    /**
     * Las versiones cuya aceptación cuenta hoy.
     *
     * La vigente siempre; y hacia atrás, cada anterior mientras la que la
     * reemplazó se declaró **menor**. Un cambio de fondo corta la cadena.
     *
     * @return list<int>
     */
    public static function versionesQueValen(string $codigo): array
    {
        $vigente = self::vigente($codigo);

        if ($vigente === null) {
            return [];
        }

        $valen = [(int) $vigente->id];
        $actual = $vigente;

        // El tope no es decoracion: una cadena circular por un dato corrupto
        // colgaria la pantalla de activacion de todos los creadores.
        for ($vuelta = 0; $vuelta < 50; $vuelta++) {
            if ((string) ($actual->change_type ?? '') !== 'menor'
                || $actual->supersedes_version_id === null) {
                break;
            }

            $anterior = DB::table('terms_versions')
                ->where('id', $actual->supersedes_version_id)
                ->first(['id', 'change_type', 'supersedes_version_id']);

            if ($anterior === null) {
                break;
            }

            $valen[] = (int) $anterior->id;
            $actual = $anterior;
        }

        return $valen;
    }

    /** Crea un borrador. Se edita cuantas veces haga falta hasta publicarlo. */
    public static function crearBorrador(
        string $codigo,
        string $version,
        string $titulo,
        string $cuerpo,
        string $audiencia,
        int $autorId,
    ): string {
        if (trim($cuerpo) === '') {
            throw new RuntimeException('Unos terminos sin texto no se le pueden oponer a nadie.');
        }

        if (DB::table('terms_versions')->where('code', $codigo)->where('version', $version)->exists()) {
            throw new RuntimeException("Ya existe la version «{$version}» de «{$codigo}».");
        }

        $uuid = (string) Str::uuid();

        DB::table('terms_versions')->insert([
            'uuid' => $uuid,
            'audience' => $audiencia,
            'code' => $codigo,
            'version' => $version,
            'title' => mb_substr(trim($titulo), 0, 160),
            'body' => $cuerpo,
            'content_sha256' => hash('sha256', $cuerpo),
            // Un borrador no esta vigente; la fecha se fija al publicar. Se
            // guarda hoy porque la columna es NOT NULL desde la Fase 2.
            'effective_from' => now()->toDateString(),
            'review_status' => 'sin_revisar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'terms.draft_created', tipoEntidad: 'terms_version',
            idEntidad: (int) DB::table('terms_versions')->where('uuid', $uuid)->value('id'),
            cambios: ['version' => ['antes' => null, 'despues' => $version]],
        );

        return $uuid;
    }

    /** Guarda los cambios de un borrador. Una publicada no llega aquí. */
    public static function guardarBorrador(string $uuid, string $titulo, string $cuerpo, int $autorId): void
    {
        $version = self::porUuid($uuid);

        if ($version->published_at !== null) {
            throw new RuntimeException(
                'Esa version ya esta publicada y no se reescribe. Cree la siguiente a partir de ella.',
            );
        }

        if (trim($cuerpo) === '') {
            throw new RuntimeException('Unos terminos sin texto no se le pueden oponer a nadie.');
        }

        DB::table('terms_versions')->where('uuid', $uuid)->update([
            'title' => mb_substr(trim($titulo), 0, 160),
            'body' => $cuerpo,
            'content_sha256' => hash('sha256', $cuerpo),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'terms.draft_saved', tipoEntidad: 'terms_version',
            idEntidad: (int) $version->id,
            cambios: ['huella' => ['antes' => $version->content_sha256,
                'despues' => hash('sha256', $cuerpo)]],
        );
    }

    /**
     * Publica un borrador y cierra la versión anterior el día antes.
     *
     * `$cambio` es la declaración que decide si todo el mundo vuelve a aceptar.
     */
    public static function publicar(string $uuid, string $cambio, ?string $desde, int $autorId): void
    {
        $version = self::porUuid($uuid);

        if ($version->published_at !== null) {
            throw new RuntimeException('Esa version ya estaba publicada.');
        }

        if (!array_key_exists($cambio, self::CAMBIO)) {
            throw new RuntimeException('Hay que declarar si el cambio es de fondo o menor.');
        }

        $vigente = self::vigente((string) $version->code);
        $desde = $desde ?: now()->toDateString();

        // El orden de los argumentos importa y se equivoco a la primera: primero
        // la fecha de la NUEVA, despues la de la que releva.
        if ($vigente !== null && !Vigencia::puedeRelevar($desde, (string) $vigente->effective_from)) {
            throw new RuntimeException(
                "La version vigente empieza el {$vigente->effective_from}: la nueva no puede entrar antes.",
            );
        }

        DB::transaction(function () use ($version, $vigente, $cambio, $desde, $autorId): void {
            if ($vigente !== null) {
                DB::table('terms_versions')->where('id', $vigente->id)->update([
                    'effective_to' => Vigencia::cerrarElDiaAntesDe($desde),
                    'updated_at' => now(),
                ]);
            }

            DB::table('terms_versions')->where('id', $version->id)->update([
                'effective_from' => $desde,
                'published_at' => now(),
                'published_by_user_id' => $autorId,
                // La primera version no reemplaza a nadie, y entonces no hay
                // nada que declarar: `ck_terms_change_type` admite el nulo.
                'change_type' => $vigente === null ? null : $cambio,
                'supersedes_version_id' => $vigente?->id,
                'updated_at' => now(),
            ]);
        });

        Bitacora::registrar(
            accion: 'terms.published', tipoEntidad: 'terms_version',
            idEntidad: (int) $version->id,
            cambios: [
                'version' => ['antes' => $vigente->version ?? null, 'despues' => $version->version],
                'cambio' => ['antes' => null, 'despues' => $vigente === null ? 'primera' : $cambio],
            ],
        );
    }

    /** El estado de revisión legal. Es un dato sobre el texto, no el texto. */
    public static function marcarRevision(string $uuid, string $estado, ?string $nota, int $autorId): void
    {
        if (!array_key_exists($estado, self::REVISION)) {
            throw new RuntimeException('Estado de revision no valido.');
        }

        $version = self::porUuid($uuid);

        DB::table('terms_versions')->where('uuid', $uuid)->update([
            'review_status' => $estado,
            'review_note' => $nota === null ? null : mb_substr(trim($nota), 0, 255),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'terms.review_marked', tipoEntidad: 'terms_version',
            idEntidad: (int) $version->id,
            cambios: ['revision' => ['antes' => $version->review_status, 'despues' => $estado]],
        );
    }

    /**
     * Lo que hay que mirar de los términos, con su prioridad.
     *
     * **No bloquea nada**: informa. Es la pieza que alimenta el badge del menú,
     * y el criterio es el de `9.16`: una configuración se rellena con un valor
     * de partida y se avisa de que conviene revisarlo, no se convierte en una
     * puerta cerrada.
     *
     * @return list<array{nivel: string, texto: string}>
     */
    public static function avisos(): array
    {
        $avisos = [];
        $codigo = self::codigo();
        $vigente = self::vigente($codigo);

        if ($vigente === null) {
            $avisos[] = ['nivel' => 'rojo',
                'texto' => 'No hay ninguna versión publicada: ningún creador puede activarse.'];

            return $avisos;
        }

        if ((string) $vigente->review_status === 'sin_revisar') {
            $avisos[] = ['nivel' => 'rojo',
                'texto' => 'La versión vigente ('.$vigente->version.') no la ha revisado un abogado. '
                    .'Funciona, pero es el texto que se le opone a un creador si hay discusión.'];
        } elseif ((string) $vigente->review_status === 'en_revision') {
            $avisos[] = ['nivel' => 'ambar',
                'texto' => 'La versión vigente ('.$vigente->version.') está en revisión legal.'];
        }

        // Los marcadores del borrador base. Se buscan en el texto de verdad y
        // no en una lista escrita a mano: asi el aviso desaparece solo cuando
        // alguien los resuelve.
        $pendientes = preg_match_all('/\[REVISAR/', (string) $vigente->body);

        if ($pendientes > 0) {
            $avisos[] = ['nivel' => 'ambar',
                'texto' => "El texto vigente tiene {$pendientes} marcas «[REVISAR]» sin resolver."];
        }

        return $avisos;
    }
}
