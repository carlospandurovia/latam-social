<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Modules\Content\Http\Requests\ComprobarPermanenciaRequest;
use App\Modules\Content\Services\Evidencias;
use App\Modules\Content\Services\Permanencia;
use App\Shared\Auth\Permisos;
use App\Shared\Files\Almacen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La bandeja de permanencia (8.8).
 *
 * ### No estrena permiso: es `content.verify`
 *
 * `8.7` le dio permiso propio a verificar porque de `verified` cuelga el pago.
 * Declarar un post caído es **el mismo acto en el otro sentido** —firmar si el
 * post está o no está, con dinero detrás— así que es la misma firma. Inventar
 * `content.permanence.resolve` habría creado un permiso que exactamente los
 * mismos dos roles tendrían, y un permiso que nadie distingue es ruido en la
 * matriz.
 *
 * Ver la bandeja entra por `content.deliverable.view`, como la cola de `8.7`;
 * las tres acciones se comprueban **dentro**, porque llegan por el mismo
 * formulario y esconder un botón no es una regla de autorización.
 */
final class PermanenciaController
{
    public function index(): View
    {
        $campanaId = request()->integer('campana') ?: null;
        $bandeja = Permanencia::bandeja($campanaId);

        return view('permanencia.bandeja', [
            'bandeja' => $bandeja,
            'desatendidas' => Permanencia::desatendidas()->count(),
            'campanaSeleccionada' => $campanaId,
            'campanas' => DB::table('campaigns')
                ->whereIn('id', Permanencia::bandeja()->pluck('campana_id')->unique())
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function ver(string $uuid): View
    {
        $publicacion = self::publicacion($uuid);

        return view('permanencia.publicacion', [
            'publicacion' => $publicacion,
            'comprobaciones' => Permanencia::comprobaciones((int) $publicacion->id),
            'motivos' => Permanencia::MOTIVOS,
            'diasRestantes' => Permanencia::diasRestantes($publicacion),
            'puedeFirmar' => Permisos::tiene((int) Auth::id(), 'content.verify'),
        ]);
    }

    public function comprobar(ComprobarPermanenciaRequest $peticion, string $uuid): RedirectResponse
    {
        $publicacion = self::publicacion($uuid);
        $usuarioId = (int) Auth::id();

        if (!Permisos::tiene($usuarioId, 'content.verify')) {
            return back()->with('aviso',
                'Comprobar la permanencia necesita el permiso de verificacion: de esto cuelga el pago.');
        }

        /** @var array<string, mixed> $datos */
        $datos = $peticion->validated();

        return match ((string) $datos['accion']) {
            ComprobarPermanenciaRequest::ANOTAR => self::anotar($peticion, $publicacion, $datos, $usuarioId),
            ComprobarPermanenciaRequest::CAIDA => self::caida($peticion, $publicacion, $datos, $usuarioId),
            default => self::reponer($peticion, $publicacion, $usuarioId),
        };
    }

    // ------------------------------------------------------------- acciones

    /**
     * Anota lo que se vio. **No cambia el estado de nada** (`DEC-146`).
     *
     * @param array<string, mixed> $datos
     */
    private static function anotar(
        ComprobarPermanenciaRequest $peticion,
        object $publicacion,
        array $datos,
        int $usuarioId,
    ): RedirectResponse {
        $viva = (string) $datos['viva'] === '1';

        // Una caída necesita decir QUÉ se vio: `ck_pc_caida_motivada` lo exige, y
        // se dice con palabras antes de que lo diga la base con un 1644.
        if (!$viva && ($datos['http_status'] ?? null) === null
            && mb_strlen(trim((string) ($datos['nota'] ?? ''))) < 5) {
            return back()->withInput()->with('aviso',
                'Si el post no estaba, escriba que vio o anote el estado HTTP. '
                .'«No estaba» sin nada detras no vale para parar un pago.');
        }

        if ($peticion->hasFile('captura')) {
            Evidencias::archivar((int) $publicacion->id, [
                'tipo' => 'screenshot',
                'file_id' => Almacen::guardar($peticion->file('captura'), 'publication_evidence'),
            ], $usuarioId);
        }

        Permanencia::anotar(
            publicacionId: (int) $publicacion->id,
            viva: $viva,
            origen: Permanencia::MANUAL,
            estadoHttp: isset($datos['http_status']) ? (int) $datos['http_status'] : null,
            nota: $datos['nota'] ?? null,
            usuarioId: $usuarioId,
        );

        return redirect()->route('permanencia.ver', $publicacion->uuid)->with('exito',
            $viva
                ? 'Anotado: el post sigue ahi.'
                : 'Anotado. Esto no para nada por si solo: si el post no vuelve, firme la caida.');
    }

    /**
     * Firma la caída: para el pago (`DEC-145`).
     *
     * @param array<string, mixed> $datos
     */
    private static function caida(
        ComprobarPermanenciaRequest $peticion,
        object $publicacion,
        array $datos,
        int $usuarioId,
    ): RedirectResponse {
        $motivos = Permanencia::vetoParaDarPorCaida($publicacion, $peticion->hasFile('captura'));

        if ($motivos !== []) {
            return back()->withInput()->with('aviso', implode(' ', $motivos));
        }

        // El archivo se guarda DESPUÉS del veto, como en 8.1 y 8.7: al revés
        // deja capturas huérfanas en el almacén cada vez que el veto rebota.
        Permanencia::darPorCaida(
            $publicacion,
            (string) $datos['motivo'],
            $datos['nota'] ?? null,
            Almacen::guardar($peticion->file('captura'), 'publication_evidence'),
            $usuarioId,
        );

        return redirect()->route('permanencia.bandeja')->with('exito',
            'Firmado. El pago de ese entregable queda parado y el creador ya lo sabe.');
    }

    /** Devuelve la publicación a vigilada: falso positivo, o repuesto. */
    private static function reponer(
        ComprobarPermanenciaRequest $peticion,
        object $publicacion,
        int $usuarioId,
    ): RedirectResponse {
        if ((string) $publicacion->status !== Permanencia::CAIDA) {
            return back()->with('aviso', 'Esa publicacion no esta dada por caida.');
        }

        Permanencia::reponer(
            $publicacion,
            Almacen::guardar($peticion->file('captura'), 'publication_evidence'),
            $usuarioId,
        );

        return redirect()->route('permanencia.bandeja')->with('exito',
            'Repuesta. Vuelve a estar vigilada y la fecha de permanencia no cambia.');
    }

    // ------------------------------------------------------------------ apoyo

    private static function publicacion(string $uuid): object
    {
        $publicacion = Permanencia::publicacion($uuid);

        if ($publicacion === null) {
            // 404 y no 403: `BR-SEC-006`.
            throw new NotFoundHttpException('No existe esa publicacion.');
        }

        return $publicacion;
    }
}
