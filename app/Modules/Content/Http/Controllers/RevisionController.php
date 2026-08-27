<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Modules\Content\Http\Requests\ReabrirRequest;
use App\Modules\Content\Http\Requests\RevisarRequest;
use App\Modules\Content\Services\Entregables;
use App\Modules\Content\Services\Revisiones;
use App\Shared\Auth\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La cola de revisión y el veredicto (8.3).
 *
 * ### Bandeja global, no una por campaña
 *
 * Decisión de negocio (2026-08-26). Revisar es trabajo por lotes: alguien se
 * sienta y despacha lo que llegó, sea de la campaña que sea. Una cola por campaña
 * obliga a recorrer campañas para descubrir si hay algo esperando, y lo que se
 * descubre recorriendo se descubre tarde.
 *
 * El seguimiento de `7.7` ya contesta *«cómo va esta campaña»*. Esto contesta
 * *«qué me toca hoy»*, que es otra pregunta y otra pantalla.
 *
 * ### Lo más viejo primero, y no lo más urgente
 *
 * El orden es por fecha de entrega ascendente. Un creador que entregó hace seis
 * días y no sabe nada es el que está a punto de dejar de contestar los correos —y
 * ordenar por fecha límite pondría delante lo que vence pronto aunque acabe de
 * llegar, que es exactamente cómo una cola deja a alguien esperando para siempre.
 *
 * ### Tres permisos y no uno
 *
 * `content.review` abre la cola y pide cambios. `content.approve` da el visto
 * bueno —lo que deja el contenido listo para el cliente—. Y `content.extra_round`
 * autoriza una ronda por encima de las incluidas, que es una decisión de dinero:
 * o se le cobra al cliente o se come el margen.
 */
final class RevisionController
{
    public function index(): View
    {
        $filtros = [
            'campana' => request()->integer('campana') ?: null,
            'desde_dias' => request()->integer('dias') ?: null,
        ];

        $cola = Revisiones::cola($filtros);

        return view('revision.cola', [
            'cola' => $cola,
            'filtros' => $filtros,
            // Las campañas que TIENEN algo esperando, no todas. Un desplegable
            // con cuarenta campañas de las que treinta y ocho no tienen nada es
            // un desplegable que no ayuda a filtrar.
            'campanas' => DB::table('campaigns')
                ->whereIn('id', Revisiones::cola()->pluck('campana_id')->unique())
                ->orderBy('name')
                ->get(['id', 'name']),
            'puedeAprobar' => Permisos::tiene((int) Auth::id(), 'content.approve'),
        ]);
    }

    public function ver(string $uuid): View
    {
        $entregable = self::entregable($uuid);
        $version = Revisiones::ultimaVersion((int) $entregable->id);

        return view('revision.entregable', [
            'entregable' => $entregable,
            'version' => $version,
            'versiones' => Entregables::versiones((int) $entregable->id),
            'historial' => Revisiones::historial((int) $entregable->id),
            'rondas' => Revisiones::rondas($entregable),
            'lados' => Revisiones::LADOS,
            'facturacion' => Revisiones::FACTURACION,
            // 8.2: cuando está aprobado, la pantalla deja de ofrecer un veredicto
            // y ofrece la reapertura. Es la misma página porque es la misma
            // pregunta —«¿qué hago con esto?»— y la respuesta depende del estado.
            'aprobado' => (string) $entregable->status === 'approved',
            'motivos' => Revisiones::MOTIVOS_REAPERTURA,
            'puedeAprobar' => Permisos::tiene((int) Auth::id(), 'content.approve'),
            'puedeAutorizar' => Permisos::tiene((int) Auth::id(), 'content.extra_round'),
            'puedeReabrir' => Permisos::tiene((int) Auth::id(), 'content.reopen'),
        ]);
    }

    /**
     * Reabre un entregable aprobado.
     *
     * Acción propia y no una rama del veredicto: reabrir no es una opinión sobre
     * el contenido, es volver atrás sobre una decisión ya tomada, y tiene su
     * permiso, su motivo de lista cerrada y su fila en el historial.
     */
    public function reabrir(ReabrirRequest $peticion, string $uuid): RedirectResponse
    {
        $entregable = self::entregable($uuid);
        $usuarioId = (int) Auth::id();

        if (!Permisos::tiene($usuarioId, 'content.reopen')) {
            return back()->with('aviso',
                'Reabrir un entregable aprobado necesita su permiso: pidaselo a quien lleva la campana.');
        }

        /** @var array<string, mixed> $datos */
        $datos = $peticion->validated();
        $motivo = (string) $datos['motivo'];
        $nota = $datos['nota'] ?? null;

        $veto = Revisiones::vetoParaReabrir($entregable, $motivo, $nota);

        if ($veto !== null) {
            return back()->withInput()->with('aviso', $veto);
        }

        Revisiones::reabrir($entregable, $motivo, $nota, $usuarioId, $peticion->ip());

        return redirect()->route('revision.ver', $entregable->uuid)->with('exito',
            'Reabierto. La aprobacion anterior sigue en el historial y la pieza vuelve a la cola.');
    }

    public function revisar(RevisarRequest $peticion, string $uuid): RedirectResponse
    {
        $entregable = self::entregable($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $peticion->validated();
        $veredicto = (string) $datos['outcome'];
        $lado = (string) $datos['reviewer_side'];
        $usuarioId = (int) Auth::id();

        // Los permisos se comprueban AQUI y no sólo en la ruta: la ruta sabe
        // «puede entrar a revisar», y aprobar o autorizar un cargo son acciones
        // distintas que llegan por el mismo POST.
        if ($veredicto === Revisiones::APROBAR && !Permisos::tiene($usuarioId, 'content.approve')) {
            return back()->withInput()->with('aviso',
                'Puede pedir cambios, pero dar el visto bueno necesita el permiso de aprobacion.');
        }

        $rondaDeMas = Revisiones::consumeRonda($veredicto, $lado)
            && Revisiones::rondas($entregable)['agotadas'];

        if ($rondaDeMas && !Permisos::tiene($usuarioId, 'content.extra_round')) {
            return back()->withInput()->with('aviso',
                'Esta pieza ya gasto las rondas incluidas. Autorizar una mas es una decision '
                .'de facturacion y necesita su permiso: pidasela a quien lleva la campana.');
        }

        $motivos = Revisiones::vetoParaRevisar($entregable, $datos);

        if ($motivos !== []) {
            return back()->withInput()->with('aviso', implode(' ', $motivos));
        }

        $version = Revisiones::ultimaVersion((int) $entregable->id);

        if ($version === null) {
            return back()->with('aviso', 'Este entregable no tiene ninguna version que revisar.');
        }

        Revisiones::emitir($entregable, $version, $datos, $usuarioId, $peticion->ip());

        return redirect()->route('revision.cola')->with('exito', $veredicto === Revisiones::APROBAR
            ? 'Aprobado. El entregable queda listo.'
            : 'Correccion enviada. El creador ya lo sabe y puede subir una version nueva.');
    }

    // ------------------------------------------------------------------ apoyo

    private static function entregable(string $uuid): object
    {
        $entregable = Revisiones::entregable($uuid);

        if ($entregable === null) {
            // 404 y no 403: no se revela que exista (`BR-SEC-006`).
            throw new NotFoundHttpException('No existe ese entregable.');
        }

        return $entregable;
    }
}
