<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Shared\Audit\Bitacora;
use App\Shared\Files\Vigilante;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La única puerta por la que sale un archivo (9.15).
 *
 * ### El permiso se mira en CADA petición
 *
 * No hay URL firmada. Una URL firmada sigue funcionando cuando se reenvía y
 * cuando a esa persona ya se le ha quitado el acceso — y aquí lo que sale son
 * documentos de identidad y comprobantes bancarios. Con una ruta propia, quitar
 * un permiso surte efecto en la siguiente petición y no queda ningún enlace
 * circulando por ahí.
 *
 * ### `file.view` no dice qué archivo, dice que se puede pedir uno
 *
 * El middleware deja pasar a quien tiene cuenta y rol interno o de creador; **de
 * qué archivo se trata lo decide el `Vigilante`**, con la regla que registró el
 * módulo dueño de esa clase de archivo. Los dos escalones hacen falta: sin el
 * permiso, esta ruta se quedaría fuera del muro de `9.14b`; sin el `Vigilante`,
 * cualquier creador abriría el documento de identidad de otro.
 */
final class ArchivosController
{
    public function __invoke(string $uuid): StreamedResponse
    {
        try {
            $archivo = Vigilante::porUuid($uuid);
        } catch (RuntimeException) {
            throw new NotFoundHttpException('No existe ese archivo.');
        }

        // Un archivo purgado no se sirve aunque siga en el disco: la fila dice
        // que ya no debe existir, y la fila manda.
        if ($archivo->purged_at !== null) {
            throw new NotFoundHttpException('Ese archivo ya no esta disponible.');
        }

        if (!Vigilante::puedeVer($archivo, (int) Auth::id())) {
            // 403 y no 404. Quien llega aqui ya esta autenticado y ya paso el
            // permiso de la ruta: decirle «no existe» seria mentirle sobre algo
            // que si existe, y no le oculta nada que no sepa.
            throw new AccessDeniedHttpException('Ese archivo no es suyo.');
        }

        if (!Storage::disk((string) $archivo->disk)->exists((string) $archivo->path)) {
            // La fila existe y el archivo no: es la «evidencia fantasma» que
            // `Almacen` intenta impedir al guardar. Se dice, no se disimula.
            throw new NotFoundHttpException(
                'La fila del archivo existe pero el archivo no esta en el disco.',
            );
        }

        if (Vigilante::esSensible((string) $archivo->purpose)) {
            Bitacora::registrar(
                accion: 'file.viewed',
                tipoEntidad: 'file',
                idEntidad: (int) $archivo->id,
                cambios: ['proposito' => ['antes' => null, 'despues' => (string) $archivo->purpose]],
            );
        }

        return Storage::disk((string) $archivo->disk)->response(
            (string) $archivo->path,
            (string) $archivo->original_name,
            [
                'Content-Type' => (string) $archivo->mime_type,
                // Que el navegador no lo guarde: un documento de identidad en la
                // cache de un equipo compartido sobrevive al cierre de sesion.
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                // Un HTML o un SVG subido como «evidencia» no se ejecuta.
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
