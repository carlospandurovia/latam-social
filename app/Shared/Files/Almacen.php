<?php

declare(strict_types=1);

namespace App\Shared\Files;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda un archivo subido y deja constancia de él en `files`.
 *
 * La tabla `files` existía desde la iteración 2.6 y hasta aquí nadie escribía
 * en ella: las claves foráneas de tutela, identidad y evidencias apuntaban a
 * una tabla vacía. Esta clase es la única puerta de entrada.
 *
 * Cuatro decisiones que no son evidentes:
 *
 * 1. **El nombre que envía el navegador no toca el disco.** La ruta se compone
 *    con un UUID y una extensión de una lista cerrada. Un `original_name` con
 *    `../` o con un `.php` dentro deja de ser un problema porque nunca se usa
 *    para construir la ruta: se guarda como dato, para poder enseñárselo al
 *    operador, y nada más.
 *
 * 2. **El tipo se detecta, no se cree.** `getClientMimeType()` es lo que dice
 *    el navegador, y el navegador lo dice quien quiera. `getMimeType()` lo
 *    deduce del contenido real del archivo. Es la diferencia entre validar y
 *    preguntarle al atacante de qué tipo es su archivo.
 *
 * 3. **La huella se calcula del archivo ya guardado**, no del temporal. Así
 *    prueba lo que hay en disco, que es lo que alguien va a leer dentro de tres
 *    años cuando pregunte si el documento se cambió.
 *
 * 4. **No se deduplica.** Dos creadores pueden subir el mismo PDF y salen dos
 *    filas. El índice `ix_files_checksum` está para DETECTAR que eso pasó
 *    —señal de fraude que alguien mira—, no para fusionarlas: fusionarlas haría
 *    que purgar los datos de un creador (BR-CREATOR-009) borrase la evidencia
 *    de otro.
 */
final class Almacen
{
    /** Extensiones permitidas y el tipo real que debe tener cada una. */
    private const TIPOS = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];

    /**
     * @param string $proposito Para qué se sube. Va a `files.purpose` y es
     *                          lo que permite saber después qué se puede
     *                          purgar y qué hay que conservar.
     * @return int El id de la fila de `files`.
     *
     * @throws \RuntimeException si el contenido real no corresponde a la extensión.
     */
    public static function guardar(UploadedFile $archivo, string $proposito): int
    {
        $extension = mb_strtolower($archivo->extension() ?: '');
        $tipoReal = (string) $archivo->getMimeType();

        // La comprobación de verdad. La regla de validación `mimes:` de Laravel
        // hace esto mismo, pero se puede olvidar en un formulario nuevo; aquí
        // no hay forma de saltárselo.
        if (!isset(self::TIPOS[$extension]) || !in_array($tipoReal, self::TIPOS[$extension], true)) {
            throw new \RuntimeException('Tipo de archivo no admitido.');
        }

        $disco = (string) config('latam.archivos.disco', 'local');
        $uuid = (string) Str::uuid();
        // Ruta compuesta solo con valores que controlamos nosotros.
        $ruta = $proposito.'/'.now()->format('Y/m').'/'.$uuid.'.'.$extension;

        $guardado = Storage::disk($disco)->putFileAs(dirname($ruta), $archivo, basename($ruta));

        // Si el disco falla y no se mira, queda una fila en `files` apuntando a
        // un archivo que no existe: una evidencia fantasma que nadie descubre
        // hasta que alguien la va a abrir, meses despues.
        if ($guardado === false) {
            throw new \RuntimeException('No se pudo guardar el archivo en el disco «'.$disco.'».');
        }

        $contenido = Storage::disk($disco)->get($ruta);

        return (int) DB::table('files')->insertGetId([
            'uuid' => $uuid,
            'disk' => $disco,
            'path' => $ruta,
            // Se recorta y se limpia: es un dato para mostrar, no una ruta.
            'original_name' => mb_substr(basename($archivo->getClientOriginalName()), 0, 255),
            'mime_type' => $tipoReal,
            'size_bytes' => Storage::disk($disco)->size($ruta),
            'checksum_sha256' => hash('sha256', (string) $contenido),
            // Nada de lo que se sube aquí es público: son documentos de
            // identidad y evidencias legales.
            'visibility' => 'private',
            'purpose' => $proposito,
            'uploaded_by_user_id' => Auth::user()?->getAuthIdentifier(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Las extensiones admitidas, para las reglas `mimes:` de los formularios.
     *
     * Existe para que la lista viva en UN sitio. Repetirla en cada formulario
     * garantiza que algun dia uno admita un tipo que esta clase rechaza, y el
     * usuario reciba «tipo de archivo no admitido» despues de subir 8 MB.
     *
     * @return list<string>
     */
    public static function extensiones(): array
    {
        return array_keys(self::TIPOS);
    }
}
