<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Certificados;
use App\Shared\Database\Choque;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Los certificados de firma, desde el admin (9.9c).
 *
 * ### `integration.manage` y no un permiso nuevo
 *
 * Es la misma persona que carga la credencial de SUNAT en `9.17d`: quien pone
 * con qué se conecta el sistema al exterior. Un permiso más para lo mismo sólo
 * añade un sitio donde olvidarse de darlo.
 *
 * ### El archivo no se guarda en disco
 *
 * Se lee del `UploadedFile` en memoria, se convierte a PEM y se guarda **cifrado
 * en la base**. Un `.pfx` en `storage/` es una clave privada en un archivo que
 * cualquier copia de seguridad se lleva en claro.
 */
final class CertificadosController
{
    /**
     * Los certificados viven DENTRO de Integraciones desde `9.17f`.
     *
     * La ruta se queda —los enlaces viejos y los favoritos siguen funcionando—
     * pero no pinta una segunda pantalla: dos puertas a lo mismo es lo que
     * `9.20` vino a quitar, y aquí la puerta es la pestaña de facturación
     * electrónica, donde el certificado está junto a lo demás que hace falta
     * para emitir.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('integraciones.index', ['p' => IntegracionesController::FEL]);
    }

    public function cargar(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'legal_entity_id' => ['required', 'integer', 'exists:legal_entities,id'],
            'environment' => ['required', 'in:'.implode(',', array_keys(Certificados::ENTORNOS))],
            // 2 MB de sobra: un certificado ronda los 3 KB. El limite existe
            // para que nadie suba una copia de seguridad por error.
            'archivo' => ['required', 'file', 'max:2048'],
            'clave' => ['nullable', 'string', 'max:255'],
        ]);

        $archivo = $peticion->file('archivo');

        if (!is_object($archivo) || !method_exists($archivo, 'get')) {
            return back()->with('aviso', 'No llegó ningún archivo.');
        }

        try {
            Certificados::cargar(
                (int) $datos['legal_entity_id'],
                (string) $datos['environment'],
                (string) $archivo->get(),
                isset($datos['clave']) ? (string) $datos['clave'] : null,
                (int) Auth::id(),
            );
        } catch (Throwable $e) {
            return back()->with('aviso', self::enPalabras($e));
        }

        return redirect()->route('certificados.index')
            ->with('exito', 'Certificado cargado. El anterior queda como reemplazado, no se borra.');
    }

    public function revocar(Request $peticion, string $uuid): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        try {
            Certificados::revocar($uuid, (string) $datos['motivo']);
        } catch (Throwable $e) {
            return back()->with('aviso', self::enPalabras($e));
        }

        return redirect()->route('certificados.index')
            ->with('exito', 'Certificado revocado, con el motivo escrito.');
    }

    private static function enPalabras(Throwable $e): string
    {
        return match (Choque::indice($e)) {
            'uq_cert_huella' => 'Ese mismo certificado ya está cargado para ese entorno.',
            'uq_cert_activo' => 'Esa sociedad ya tiene un certificado en uso para ese entorno.',
            default => $e->getMessage(),
        };
    }
}
