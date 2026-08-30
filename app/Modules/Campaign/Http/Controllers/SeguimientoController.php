<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Http\Controllers;

use App\Modules\Campaign\Services\Seguimiento;
use App\Shared\Auth\Permisos;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * El panel de seguimiento de una campaña (7.7).
 *
 * ### Por qué es una pantalla aparte de la ficha
 *
 * `/campanas/{uuid}` contesta *«qué es esta campaña»*: sus datos, su brief, sus
 * mercados, quién la factura. Se abre cuando se está **montando**.
 *
 * Esto contesta *«cómo va»*, y se abre cuando ya está montada — que según el
 * roadmap es la pantalla más usada del sistema. Mezclarlas daría una página que
 * hace las dos cosas a medias y en la que hay que buscar.
 *
 * ### El margen ya no vive aquí (9.10)
 *
 * Esta pantalla enseñaba un «margen» que era el ingreso menos lo comprometido
 * con creadores **y nada más**: no restaba el producto, ni los envíos, ni la
 * producción. Salía más alto de lo que era, y desde `9.10a` esos gastos ya se
 * pueden anotar, así que el número habría empeorado con cada uno.
 *
 * El margen completo vive en Finance —donde están las dos mitades— y aquí queda
 * **el enlace**, para quien pueda verlo. Lo demás —presupuesto, comprometido,
 * disponible— sí lo ve quien puede ver la campaña: sin esos tres números no se
 * decide a quién invitar (decisión de negocio, 2026-08-26).
 */
final class SeguimientoController
{
    public function __invoke(string $uuid): View
    {
        $campana = DB::table('campaigns as c')
            ->leftJoin('client_organizations as o', 'o.id', '=', 'c.client_organization_id')
            ->leftJoin('client_brands as b', 'b.id', '=', 'c.client_brand_id')
            ->where('c.uuid', $uuid)
            ->first(['c.*', 'o.commercial_name as cliente', 'b.name as marca']);

        if ($campana === null) {
            throw new NotFoundHttpException('No existe esa campana.');
        }

        return view('campanas.seguimiento', [
            'campana' => $campana,
            'embudo' => Seguimiento::embudo((int) $campana->id),
            'nombresEmbudo' => Seguimiento::EMBUDO,
            'nombresSalidas' => Seguimiento::SALIDAS,
            'cupos' => Seguimiento::cupos((int) $campana->id),
            'dinero' => Seguimiento::dinero($campana),
            'alertas' => Seguimiento::alertas($campana),
            'participantes' => Seguimiento::participantes((int) $campana->id),
            'dias' => Seguimiento::diasHastaArranque($campana),
            // 9.10: un booleano y NO el numero. La pantalla ofrece el enlace a
            // la rentabilidad; el margen se calcula alli, con las dos mitades.
            // Aqui ya no llega ningun importe interno que un `@if` mal puesto
            // pueda ensenar (`BR-SEC-001`, rojo).
            'verMargen' => Permisos::tiene((int) Auth::id(), 'campaign.view_margin'),
            // 8.1: si esta persona puede ver los entregables, el panel le ofrece
            // el enlace a esa pantalla. **El conteo NO se calcula aquí**:
            // `deptrac.yaml` dice `Campaign: [..., Creator, Client]` y Content
            // no está en la lista. Es la misma frontera que impide llamar a
            // Communication, y la salida es la misma: no llamarse.
            'verEntregables' => Permisos::tiene((int) Auth::id(), 'content.deliverable.view'),
        ]);
    }
}
