<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Controllers;

use App\Modules\Core\Services\Terminos;
use App\Modules\Creator\Services\Reaceptacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Los términos del creador, desde su lado (9.19).
 *
 * Es la pantalla a la que lleva el muro y la única que se puede abrir cuando ya
 * no queda nada más. Enseña **el texto entero** —no un resumen ni un enlace— y
 * un botón. Aceptar algo que no se ve en la misma pantalla no es aceptar.
 *
 * ### Sin `permiso:`
 *
 * `creator.portal` abre el portal, y quien está bloqueado por no aceptar sigue
 * teniéndolo. Poner el permiso aquí sería redundante y, peor, dejaría fuera al
 * creador cuyo rol todavía no se le ha asignado del todo — que es exactamente
 * quien no puede quedarse sin salida. La autorización es tener una ficha de
 * creador atada a esta sesión, y eso se comprueba abajo.
 */
final class MisTerminosController
{
    public function index(): View
    {
        $creadorId = $this->creador();
        $estado = Reaceptacion::de($creadorId);

        return view('terminos.mios', [
            'estado' => $estado,
            // La vigente aunque este al dia: entrar a releer lo que se acepto
            // es un derecho, no un caso raro.
            'version' => $estado['version'] ?? Terminos::vigente(Terminos::codigo()),
            'aceptadaEl' => DB::table('terms_acceptances')
                ->where('subject_type', 'creator')->where('subject_id', $creadorId)
                ->whereIn('terms_version_id', Terminos::versionesQueValen(Terminos::codigo()))
                ->orderByDesc('accepted_at')->value('accepted_at'),
        ]);
    }

    public function aceptar(Request $peticion): RedirectResponse
    {
        Terminos::aceptar(
            $this->creador(),
            $peticion->ip(),
            $peticion->userAgent(),
        );

        return redirect()->route('panel')->with(
            'mensaje',
            'Gracias: queda constancia de que aceptaste los términos vigentes.',
        );
    }

    /**
     * La ficha de creador de esta sesión.
     *
     * `creators.user_id` es lo que ata una cuenta a una ficha desde `5.9`. Sin
     * ficha no hay términos que aceptar y no hay pantalla que enseñar.
     */
    private function creador(): int
    {
        $id = DB::table('creators')
            ->where('user_id', Auth::user()?->getAuthIdentifier())
            ->value('id');

        if ($id === null) {
            throw new NotFoundHttpException('Esta cuenta no tiene ficha de creador.');
        }

        return (int) $id;
    }
}
