<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Impuestos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Las tasas de impuesto, desde el admin (9.9a).
 *
 * ### `pricing.manage` y no `legal_entity.manage`
 *
 * Quien pone una tasa de impuesto es quien lleva finanzas, no quien administra
 * sociedades: es la misma persona que fija la política de precios en `9.18`, y
 * ese permiso ya lo tienen admin y finanzas.
 *
 * ### Aquí no se edita una tasa: se publica la siguiente
 *
 * A propósito, y es la misma forma que los términos de `9.16`. Corregir el 18 %
 * de una fila que ya explicó el impuesto de cien facturas es reescribir el
 * pasado; lo que se hace es **cerrar la que rige y abrir la nueva desde el día
 * que toque**, que es lo que de verdad pasa cuando un país sube un impuesto.
 */
final class ImpuestosController
{
    public function index(): View
    {
        return view('impuestos.index', [
            'tasas' => Impuestos::todas(),
            'paises' => DB::table('countries')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name', 'iso2']),
            'avisos' => Impuestos::avisos(),
        ]);
    }

    public function publicar(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z][A-Za-z0-9_]{1,19}$/'],
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'rate' => ['required', 'numeric', 'min:0', 'lt:100'],
            'official_code' => ['nullable', 'string', 'max:10'],
            'valid_from' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            // 9.9b: la segunda mitad de la misma decision. Marcarla aqui
            // evita que alguien publique la tasa y olvide decir que es la
            // que va en la factura --con lo que se emitiria sin impuesto--.
            'es_de_venta' => ['nullable', 'boolean'],
        ]);

        try {
            Impuestos::publicar($datos);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('aviso', $e->getMessage());
        }

        return redirect()->route('impuestos.index')
            ->with('exito', 'Tasa publicada. La anterior queda cerrada el día antes.');
    }
}
