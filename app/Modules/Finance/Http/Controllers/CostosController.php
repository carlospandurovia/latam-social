<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Services\Costos;
use App\Shared\Files\Almacen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * El gasto de una campaña (9.10a).
 *
 * Vive en **Finance** y no en Campaign aunque la pantalla cuelgue de una
 * campaña: `campaign_costs` es una tabla de finanzas, y `deptrac` no deja que
 * Campaign conozca a Finance. Es la misma frontera por la que el devengo de
 * `9.4` viaja por evento.
 *
 * ### Lo que esta pantalla NO enseña
 *
 * Ni `revenue_amount` ni margen. Quien lleva una campaña carga sus gastos y ve
 * cuánto lleva gastado; **cuánto se gana** es otra pregunta y otro permiso
 * (`campaign.view_margin`, `DEC-181`). Ver el costo no es ver el margen: sin el
 * ingreso al lado, un total de gasto no dice nada de la rentabilidad.
 */
final class CostosController
{
    public function index(string $uuid): View
    {
        $campana = $this->campana($uuid);
        $campanaId = (int) $campana->id;

        return view('costos.index', [
            'campana' => $campana,
            'costos' => Costos::deUnaCampana($campanaId),
            'resumen' => Costos::resumen($campanaId),
            'creadores' => Costos::creadoresPorMoneda($campanaId),
            'tipos' => Costos::TIPOS,
            'monedas' => DB::table('currencies')->where('is_active', 1)
                ->orderBy('code')->get(['code']),
            'hoy' => now()->toDateString(),
        ]);
    }

    public function store(Request $peticion, string $uuid): RedirectResponse
    {
        $campanaId = (int) $this->campana($uuid)->id;

        $datos = $peticion->validate([
            'cost_type' => ['required', 'string', 'in:'.implode(',', array_keys(Costos::TIPOS))],
            'description' => ['required', 'string', 'min:3', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            // `before_or_equal:tomorrow` acompaña a `tg_cco_fecha`: la base lo
            // impide igual, y aquí se dice con palabras antes del 45000.
            'incurred_on' => ['required', 'date', 'before_or_equal:tomorrow'],
            'comprobante' => ['nullable', 'file', 'max:5120', 'mimes:'.implode(',', Almacen::extensiones())],
        ]);

        $archivoId = $peticion->hasFile('comprobante')
            ? Almacen::guardar($peticion->file('comprobante'), 'campaign_cost')
            : null;

        try {
            Costos::anotar(
                campanaId: $campanaId,
                tipo: (string) $datos['cost_type'],
                descripcion: (string) $datos['description'],
                monto: (float) $datos['amount'],
                moneda: mb_strtoupper((string) $datos['currency_code']),
                fecha: (string) $datos['incurred_on'],
                archivoId: $archivoId,
                autorId: (int) Auth::id(),
            );
        } catch (RuntimeException $e) {
            return redirect()->route('costos.index', $uuid)->with('aviso', $e->getMessage());
        }

        return redirect()->route('costos.index', $uuid)
            ->with('exito', 'Gasto anotado.');
    }

    public function anular(Request $peticion, string $uuid, int $costoId): RedirectResponse
    {
        $campanaId = (int) $this->campana($uuid)->id;

        $datos = $peticion->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        try {
            Costos::anular($costoId, (string) $datos['motivo'], (int) Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->route('costos.index', $uuid)->with('aviso', $e->getMessage());
        }

        return redirect()->route('costos.index', $uuid)
            ->with('exito', 'Gasto anulado. Sigue en la lista, tachado y con su motivo.');
    }

    private function campana(string $uuid): object
    {
        $campana = DB::table('campaigns as c')
            ->leftJoin('client_brands as b', 'b.id', '=', 'c.client_brand_id')
            ->where('c.uuid', $uuid)
            // Ni `revenue_amount` ni `creator_budget_amount`: esta pantalla no
            // los necesita y enumerar columnas es la frontera (DEC-172).
            ->first(['c.id', 'c.uuid', 'c.name', 'c.status', 'c.currency_code',
                'b.name as marca']);

        if ($campana === null) {
            throw new NotFoundHttpException('No existe esa campana.');
        }

        return $campana;
    }
}
