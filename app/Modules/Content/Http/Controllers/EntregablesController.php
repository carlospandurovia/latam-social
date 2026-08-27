<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Modules\Content\Http\Requests\PublicarRequest;
use App\Modules\Content\Services\Entregables;
use App\Modules\Content\Services\Publicaciones;
use App\Modules\Content\Services\Revisiones;
use App\Shared\Audit\Bitacora;
use App\Shared\Auth\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Los entregables de una campaña, vistos por dentro (8.1).
 *
 * ### Por qué esta pantalla es de Content y no del panel de seguimiento
 *
 * No es organización: es el grafo. `deptrac.yaml` dice `Campaign: [Framework,
 * Shared, Core, Identity, Creator, Client]` — **Content no está**, y sí al revés.
 * El panel de `7.7` no puede contar entregables porque no puede conocerlos.
 *
 * La primera versión intentó hacerlo desde `SeguimientoController` y CI lo habría
 * rechazado con razón. La salida no fue relajar la regla: fue darle a los
 * entregables su pantalla, que además hacía falta igualmente — el panel de
 * seguimiento habla de personas y de dinero, y esto habla de **trabajo**.
 *
 * ### Y por qué hay un botón de «generar»
 *
 * Los entregables se crean solos al aceptar, por evento. Si ese evento falla
 * —`GenerarEntregables` lo registra y sigue— el creador queda aceptado y sin nada
 * que entregar. Esto es la salida de emergencia, y es visible: la pantalla
 * **avisa** de las participaciones aceptadas que no tienen ninguno.
 */
final class EntregablesController
{
    public function index(string $uuid): View
    {
        $campana = self::campana($uuid);

        $participaciones = DB::table('campaign_creators as cc')
            ->join('creators as c', 'c.id', '=', 'cc.creator_id')
            ->where('cc.campaign_id', $campana->id)
            ->whereNotNull('cc.accepted_at')
            ->orderBy('c.display_name')
            ->get(['cc.id', 'cc.status', 'c.display_name', 'c.uuid as creador_uuid']);

        return view('entregas.campana', [
            'campana' => $campana,
            'avance' => Entregables::avance((int) $campana->id),
            // 8.3: las rondas cobradas de mas que todavia nadie facturo. No hay
            // tabla de cargos al cliente --`Q-57`-- asi que esta lista ES el
            // registro, y va aqui para que no se facture la campana sin verla.
            'cargos' => Revisiones::cargosPendientes((int) $campana->id),
            'puedePublicar' => Permisos::tiene((int) Auth::id(), 'content.publication.manage'),
            'participaciones' => $participaciones->map(function (object $p): object {
                $p->entregables = Entregables::de((int) $p->id)->map(function (object $e): object {
                    $e->versiones = Entregables::versiones((int) $e->id);
                    $e->publicaciones = Publicaciones::de((int) $e->id);

                    return $e;
                });

                return $p;
            }),
        ]);
    }

    /**
     * Crea a mano los entregables que el evento no creó.
     *
     * Idempotente: si ya los tiene, no hace nada y lo dice. Pulsarlo dos veces
     * no puede duplicar el trabajo de un creador.
     */
    public function generar(string $uuid, int $participacion): RedirectResponse
    {
        $campana = self::campana($uuid);

        // El par (campana, participacion), no solo el id: la participacion puede
        // existir y ser de otra campana.
        $suya = DB::table('campaign_creators')
            ->where('id', $participacion)
            ->where('campaign_id', $campana->id)
            ->exists();

        if (!$suya) {
            throw new NotFoundHttpException('Esa participacion no es de esa campana.');
        }

        $creados = Entregables::generarPara($participacion);

        if ($creados === 0) {
            return back()->with('aviso', 'No se creo ninguno: o ya los tenia, o el brief de su '
                .'mercado no pide nada. Revise el brief de la campana.');
        }

        Bitacora::registrar(
            accion: 'deliverable.generated_manually',
            tipoEntidad: 'campaign_creator',
            idEntidad: $participacion,
            cambios: ['entregables' => ['antes' => 0, 'despues' => $creados]],
        );

        return back()->with('exito', "{$creados} entregable(s) creado(s).");
    }

    /**
     * El equipo registra el post por el creador (8.6).
     *
     * Existe porque el caso real existe: el enlace llega por WhatsApp y el creador
     * no entra a pegarlo. Pasa por el **mismo** servicio y el mismo veto que su
     * portal —sólo cambia quién firma la fila— porque una segunda puerta con sus
     * propias reglas es una segunda puerta con sus propios agujeros.
     */
    public function publicar(PublicarRequest $peticion, string $uuid, int $entregableId): RedirectResponse
    {
        $campana = self::campana($uuid);

        $entregable = DB::table('deliverables as d')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->where('d.id', $entregableId)
            ->where('cc.campaign_id', $campana->id)
            ->first(['d.id', 'd.uuid', 'd.status', 'd.approved_at', 'd.approved_version_id']);

        if ($entregable === null) {
            throw new NotFoundHttpException('No existe ese entregable en esta campana.');
        }

        /** @var array<string, mixed> $datos */
        $datos = $peticion->validated();
        $cuando = $datos['published_at'] ?? null;

        $motivos = Publicaciones::vetoParaPublicar($entregable, (string) $datos['url'], $cuando);

        if ($motivos !== []) {
            return back()->withInput()->with('aviso', implode(' ', $motivos));
        }

        Publicaciones::reportar(
            $entregable, (string) $datos['url'], $cuando, (int) Auth::id(), $peticion->ip(),
        );

        return back()->with('exito', 'Post registrado.');
    }

    private static function campana(string $uuid): object
    {
        $campana = DB::table('campaigns as c')
            ->leftJoin('client_brands as b', 'b.id', '=', 'c.client_brand_id')
            ->where('c.uuid', $uuid)
            ->first(['c.id', 'c.uuid', 'c.name', 'c.code', 'b.name as marca']);

        if ($campana === null) {
            throw new NotFoundHttpException('No existe esa campana.');
        }

        return $campana;
    }
}
