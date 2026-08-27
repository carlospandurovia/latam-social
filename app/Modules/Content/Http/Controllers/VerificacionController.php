<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Modules\Content\Http\Requests\VerificarRequest;
use App\Modules\Content\Services\Evidencias;
use App\Shared\Auth\Permisos;
use App\Shared\Files\Almacen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Comprobar que el post existe, y archivar la prueba (8.7).
 *
 * ### Tiene su propio permiso, y no es celo
 *
 * `content.verify`. De `verified` cuelga el pago —`BR-CONTENT-004` es 🔴— así que
 * es una firma con dinero detrás, y el mismo criterio que separó revisar de
 * aprobar en `8.3` la separa de revisar contenido. Finanzas **no** lo necesita:
 * paga contra lo verificado, no verifica.
 *
 * ### Lo que se archiva es una captura
 *
 * Instagram y TikTok responden igual a un post vivo que a un bloqueo, así que un
 * `http_status` no prueba nada. La pantalla lo dice con esas palabras, porque el
 * primer instinto de cualquiera es preguntarse por qué no se comprueba solo.
 */
final class VerificacionController
{
    public function index(): View
    {
        $campanaId = request()->integer('campana') ?: null;
        $cola = Evidencias::cola($campanaId);

        return view('verificacion.cola', [
            'cola' => $cola,
            'campanaSeleccionada' => $campanaId,
            'campanas' => DB::table('campaigns')
                ->whereIn('id', Evidencias::cola()->pluck('campana_id')->unique())
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function ver(string $uuid): View
    {
        $publicacion = self::publicacion($uuid);

        return view('verificacion.publicacion', [
            'publicacion' => $publicacion,
            'evidencias' => Evidencias::de((int) $publicacion->id),
            'motivos' => Evidencias::MOTIVOS,
            'puedeVerificar' => Permisos::tiene((int) Auth::id(), 'content.verify'),
        ]);
    }

    public function verificar(VerificarRequest $peticion, string $uuid): RedirectResponse
    {
        $publicacion = self::publicacion($uuid);
        $usuarioId = (int) Auth::id();

        // En el POST y no sólo en la ruta: los dos veredictos llegan por el mismo
        // formulario y esconder un botón no es una regla de autorización.
        if (!Permisos::tiene($usuarioId, 'content.verify')) {
            return back()->with('aviso',
                'Verificar una publicacion necesita su permiso: de esto cuelga el pago.');
        }

        /** @var array<string, mixed> $datos */
        $datos = $peticion->validated();
        $veredicto = (string) $datos['veredicto'];

        if ($veredicto === Evidencias::VERIFICADA) {
            $motivos = Evidencias::vetoParaVerificar($publicacion, $peticion->hasFile('captura') ? 1 : null);

            if ($motivos !== []) {
                return back()->withInput()->with('aviso', implode(' ', $motivos));
            }
        }

        // El archivo se guarda DESPUÉS del veto, como en 8.1: al revés dejaría
        // capturas huérfanas en el almacén cada vez que el veto rebota.
        $archivoId = $peticion->hasFile('captura')
            ? Almacen::guardar($peticion->file('captura'), 'publication_evidence')
            : null;

        if (($datos['http_status'] ?? null) !== null) {
            Evidencias::anotarSonda((int) $publicacion->id, (int) $datos['http_status'], $usuarioId);
        }

        if ($veredicto === Evidencias::VERIFICADA) {
            Evidencias::verificar($publicacion, $archivoId, $usuarioId);

            return redirect()->route('verificacion.cola')
                ->with('exito', 'Verificado. La captura queda archivada y no se borra.');
        }

        Evidencias::rechazar(
            $publicacion,
            (string) $datos['motivo'],
            $datos['nota'] ?? null,
            $archivoId,
            $usuarioId,
        );

        return redirect()->route('verificacion.cola')
            ->with('exito', 'Rechazada. El creador ya lo sabe y puede registrar otro enlace.');
    }

    // ------------------------------------------------------------------ apoyo

    private static function publicacion(string $uuid): object
    {
        $publicacion = Evidencias::publicacion($uuid);

        if ($publicacion === null) {
            // 404 y no 403: `BR-SEC-006`.
            throw new NotFoundHttpException('No existe esa publicacion.');
        }

        return $publicacion;
    }
}
