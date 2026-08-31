<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Files\Almacen;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * La identidad de la plataforma (9.17).
 *
 * ### Lo que había antes
 *
 * «LATAM Social» estaba en el `<title>`, en la barra lateral y en la pantalla de
 * acceso; el favicon era un archivo del repositorio; el color de marca eran dos
 * clases de CSS. Para poner otro nombre había que editar Blade y desplegar, lo
 * cual es lo contrario de una plataforma white label (`DEC-190`).
 *
 * `platform_brands` guardaba todo eso desde 2.10 y **nadie la leía**. Esta clase
 * es la que la lee.
 *
 * ### Nunca devuelve nada a medias
 *
 * `datos()` siempre entrega el juego completo. Si no hay tabla —durante una
 * migración—, si no hay fila —primer arranque— o si un campo está vacío, se cae
 * al respaldo. Esto no es amabilidad: es la regla de `DEC-190` llevada al
 * código. Una configuración que falta **no bloquea**; produce un valor de
 * partida y un aviso con prioridad, y quien opera decide cuándo lo atiende.
 *
 * ### Los colores se desinfectan aunque el motor ya los valide
 *
 * `ck_pb_color`, `ck_pb_color2` y `ck_pb_barra` obligan a `#RRGGBB`, y aun así
 * `color()` vuelve a comprobarlo antes de escribirlo en la hoja de estilo. El
 * valor viaja de la base a un `<style>` de **todas** las pantallas: si algún día
 * entra por una vía que no pasa por el CHECK —una carga masiva, una restauración
 * de copia, un motor sin CHECK nativo mal configurado—, lo que se escribe ahí es
 * CSS, y CSS ajeno en todas las pantallas es una inyección. Dos cerraduras
 * cuestan una expresión regular.
 *
 * ### El logotipo se sirve por una puerta propia
 *
 * Y no por la de `9.15`. La pantalla de acceso la ve **quien no ha entrado**, así
 * que el `permiso:file.view` que protege `/archivos/{uuid}` no puede aplicarse:
 * dejaría el logotipo fuera justo de la única pantalla que ve todo el mundo. El
 * logotipo de una empresa está en su web; no es un secreto. Por eso hay una
 * puerta pública que **sólo sabe servir dos archivos** —el logotipo y el favicon
 * de la marca por defecto— y que no admite un identificador: no se puede pedir
 * otra cosa por ella (`DEC-201`).
 */
final class Marca
{
    /** El propósito con el que se guardan el logotipo y el favicon en `files`. */
    public const PROPOSITO = 'platform_brand';

    /**
     * Lo que se ve mientras no haya nada configurado.
     *
     * No es «la marca del producto»: es el valor de partida de una instalación
     * recién creada, y por eso está aquí y no en la base. La base la escribe el
     * sembrador; esto es lo que sostiene la pantalla el minuto anterior.
     */
    private const RESPALDO = [
        'nombre' => 'LATAM Social',
        'lema' => null,
        'color' => '#7C3AED',
        'color2' => '#22D3EE',
        'barra' => '#070A2B',
        'tipografia' => 'Plus Jakarta Sans',
        'pieLegal' => null,
        'web' => null,
        'correoSoporte' => null,
    ];

    /** @var array<string, mixed>|null */
    private static ?array $memoria = null;

    /**
     * La fila de la marca por defecto, o `null` si todavía no hay ninguna.
     *
     * `default_gate` + `uq_pb_default` garantizan que como mucho hay una, así
     * que esto no tiene que desempatar nada. Si nadie la marcó —una base
     * anterior a esta iteración—, se cae a la primera activa antes que a
     * ninguna: enseñar la marca que hay es mejor que enseñar el respaldo.
     */
    public static function actual(): ?object
    {
        if (!Schema::hasTable('platform_brands')) {
            return null;
        }

        return DB::table('platform_brands')->where('is_default', 1)->first()
            ?? DB::table('platform_brands')->where('is_active', 1)->orderBy('id')->first();
    }

    /**
     * Todo lo que una plantilla necesita para vestirse, completo siempre.
     *
     * @return array<string, mixed>
     */
    public static function datos(): array
    {
        if (self::$memoria !== null) {
            return self::$memoria;
        }

        $fila = self::actual();

        $datos = [
            'nombre' => self::texto($fila?->name) ?? self::RESPALDO['nombre'],
            'lema' => self::texto($fila->tagline ?? null),
            'color' => self::color($fila->primary_color ?? null, self::RESPALDO['color']),
            'color2' => self::color($fila->secondary_color ?? null, self::RESPALDO['color2']),
            'barra' => self::color($fila->sidebar_color ?? null, self::RESPALDO['barra']),
            'tipografia' => self::tipografia($fila->font_family ?? null),
            'pieLegal' => self::texto($fila->legal_footer ?? null),
            'web' => self::texto($fila->website ?? null),
            'correoSoporte' => self::texto($fila->support_email ?? null),
            // `null` significa «no hay logotipo subido», y la plantilla dibuja
            // entonces el cuadrado con el degradado. No se inventa una ruta a un
            // archivo que no existe: una imagen rota es peor que un cuadro.
            'logo' => self::hayArchivo($fila->logo_file_id ?? null) ? route('marca.logo') : null,
            'favicon' => self::rutaFavicon($fila),
            'configurada' => $fila !== null,
        ];

        self::$memoria = $datos;

        return $datos;
    }

    /** Sólo para las pruebas y para después de guardar: vuelve a leer la base. */
    public static function olvidar(): void
    {
        self::$memoria = null;
    }

    /**
     * Lo que falta por configurar, con prioridad y sin bloquear nada.
     *
     * Es el criterio de `DEC-190` hecho pantalla: *«no me digas que algo es un
     * stopper; ponme prioridades y un badge en rojo o amarillo según la
     * importancia»*. Rojo es lo que un tercero va a ver mal —el nombre, el
     * correo al que escribe un creador cuando algo falla—; ámbar es lo que
     * conviene y todavía se sostiene con el valor de partida.
     *
     * @return list<array{nivel: string, texto: string}>
     */
    public static function avisos(): array
    {
        $fila = self::actual();

        if ($fila === null) {
            return [['nivel' => 'rojo',
                'texto' => 'No hay ninguna marca configurada: la plataforma se está enseñando con '
                    .'los valores de partida. Rellene esta pantalla y guarde.']];
        }

        $avisos = [];

        if (self::hayArchivo($fila->logo_file_id) === false) {
            $avisos[] = ['nivel' => 'rojo',
                'texto' => 'No hay logotipo. En la barra lateral y en la pantalla de acceso sale un '
                    .'cuadrado de color en su sitio, y eso lo ve todo el que entra.'];
        }

        if (self::texto($fila->support_email) === null) {
            $avisos[] = ['nivel' => 'rojo',
                'texto' => 'No hay correo de soporte. Es la dirección a la que escribe un creador '
                    .'cuando algo le falla, y hoy no hay ninguna que darle.'];
        }

        if (self::hayArchivo($fila->favicon_file_id) === false) {
            $avisos[] = ['nivel' => 'ambar',
                'texto' => 'No hay favicon propio: en la pestaña del navegador sale el del logotipo '
                    .'si lo hay, y si no el que trae el repositorio.'];
        }

        if (self::texto($fila->legal_footer) === null) {
            $avisos[] = ['nivel' => 'ambar',
                'texto' => 'No hay pie legal. Es la línea que acompaña a la marca en los correos y '
                    .'en los documentos que se le mandan a un creador.'];
        }

        if (self::texto($fila->website) === null) {
            $avisos[] = ['nivel' => 'ambar',
                'texto' => 'No hay dirección web. Sale como enlace en los correos, y sin ella el '
                    .'correo no lleva a ningún sitio.'];
        }

        return $avisos;
    }

    /**
     * Guarda la marca. Crea la fila si es el primer arranque.
     *
     * Devuelve el número de campos que cambiaron, para que quien llama pueda
     * decir «no cambió nada» en vez de fingir que guardó algo.
     *
     * @param array<string, mixed> $datos
     */
    public static function guardar(array $datos, ?UploadedFile $logo = null, ?UploadedFile $favicon = null): int
    {
        $fila = self::actual();

        if ($fila === null) {
            // Primer arranque sin sembrador. Se crea con el codigo de partida y
            // ya marcada por defecto: `uq_pb_default` deja pasar la primera.
            $codigo = (string) config('latam.marca.codigo', 'latam_social');
            DB::table('platform_brands')->insert([
                'uuid' => (string) Str::uuid(),
                'code' => $codigo,
                'name' => (string) ($datos['name'] ?? self::RESPALDO['nombre']),
                'is_active' => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $fila = self::actual();
        }

        /** @var object $fila */
        $cambios = [];

        // Los archivos primero: si `Almacen` rechaza el tipo real, no se ha
        // escrito todavia nada en la marca y el formulario vuelve entero.
        if ($logo !== null) {
            $datos['logo_file_id'] = Almacen::guardar($logo, self::PROPOSITO);
        }

        if ($favicon !== null) {
            $datos['favicon_file_id'] = Almacen::guardar($favicon, self::PROPOSITO);
        }

        foreach ($datos as $campo => $valor) {
            if ((string) ($fila->{$campo} ?? '') !== (string) ($valor ?? '')) {
                $cambios[$campo] = ['antes' => $fila->{$campo} ?? null, 'despues' => $valor];
            }
        }

        if ($cambios === []) {
            return 0;
        }

        DB::table('platform_brands')->where('id', $fila->id)
            ->update($datos + ['updated_at' => now()]);

        // El archivo anterior NO se borra ni se marca para purgar. `files` es
        // una tabla de la que cuelgan documentos de identidad y comprobantes;
        // un borrado por «ya no lo uso» abre esa puerta para todo lo demas. Un
        // logotipo viejo ocupa 40 KB.
        Bitacora::registrar(
            accion: 'platform_brand.updated',
            tipoEntidad: 'platform_brand',
            idEntidad: (int) $fila->id,
            cambios: $cambios,
        );

        self::olvidar();

        return count($cambios);
    }

    /**
     * El archivo del logotipo o del favicon de la marca por defecto.
     *
     * Es lo único que la puerta pública sabe servir, y por eso recibe una
     * palabra de una lista cerrada y no un identificador: por aquí no se puede
     * pedir el documento de identidad de nadie.
     */
    public static function archivo(string $cual): ?object
    {
        $fila = self::actual();

        $id = match ($cual) {
            'logo' => $fila?->logo_file_id,
            // La pestana no se queda sin icono por no haber subido uno: si no
            // hay favicon propio se sirve el logotipo, y `datos()` ya se cayo
            // al del repositorio si tampoco habia logotipo.
            'favicon' => $fila->favicon_file_id ?? $fila?->logo_file_id,
            default => null,
        };

        if ($id === null) {
            return null;
        }

        return DB::table('files')->where('id', $id)->whereNull('purged_at')
            ->first(['id', 'uuid', 'disk', 'path', 'mime_type', 'original_name']);
    }

    // ------------------------------------------------------------------ apoyo

    private static function rutaFavicon(?object $fila): string
    {
        if (self::hayArchivo($fila->favicon_file_id ?? null)
            || self::hayArchivo($fila->logo_file_id ?? null)) {
            return route('marca.favicon');
        }

        return asset('img/brand/favicon.svg');
    }

    private static function hayArchivo(mixed $id): bool
    {
        if ($id === null || !Schema::hasTable('files')) {
            return false;
        }

        return DB::table('files')->where('id', (int) $id)->whereNull('purged_at')->exists();
    }

    /** Un texto que en la base está vacío es un texto que no hay. */
    private static function texto(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : $texto;
    }

    /** Un color que no es `#RRGGBB` no se escribe en la hoja de estilo. */
    private static function color(mixed $valor, string $respaldo): string
    {
        $color = (string) ($valor ?? '');

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : $respaldo;
    }

    /** Igual: lo que no sean letras, números y espacios no llega a la URL. */
    private static function tipografia(mixed $valor): string
    {
        $familia = trim((string) ($valor ?? ''));

        return preg_match('/^[A-Za-z0-9 ]{2,80}$/', $familia) === 1
            ? $familia
            : (string) self::RESPALDO['tipografia'];
    }
}
