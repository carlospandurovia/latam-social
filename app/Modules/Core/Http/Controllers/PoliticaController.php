<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Politica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

/**
 * La política de precios, desde el admin (9.18).
 *
 * Se publica una versión nueva; la anterior se cierra **el día antes** y queda
 * en el historial, porque es la que explica cómo se pactó cada compromiso de
 * entonces. Editar la vigente no existe: cambiar un umbral es una decisión con
 * fecha.
 */
final class PoliticaController
{
    public function index(): View
    {
        $datos = Politica::datos();

        return view('politica.index', [
            'datos' => $datos,
            'vigente' => Politica::vigente(),
            'versiones' => Politica::versiones(),
            'avisos' => Politica::avisos(),
            'bases' => Politica::BASES,
            'hoy' => now()->toDateString(),
            // El ejemplo de 100 con los numeros de hoy. Se ensena SIEMPRE y con
            // una cifra redonda: un umbral en abstracto no se discute, y «100
            // te cuesta 141,84» si.
            'ejemplo' => $datos['tasa'] > 0 ? Politica::desglose(100.0) : null,
        ]);
    }

    public function store(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'withholding_rate' => ['required', 'numeric', 'min:0', 'max:99.9999'],
            'min_margin_pct' => ['required', 'numeric', 'min:0', 'max:99.9999'],
            'margin_basis' => ['required', 'in:'.implode(',', array_keys(Politica::BASES))],
            'note' => ['nullable', 'string', 'max:255'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            Politica::publicar(
                $datos,
                (string) $datos['valid_from'],
                Auth::id() === null ? null : (int) Auth::id(),
            );
        } catch (RuntimeException $e) {
            // El veto de fechas y el de la division por cero salen con palabras
            // y no como un `45000` a media pantalla.
            return back()->withInput()->with('aviso', $e->getMessage());
        }

        return redirect()->route('politica.index')->with(
            'exito',
            sprintf(
                'Política publicada: retención %s %%, umbral %s %% sobre el %s, desde el %s.',
                rtrim(rtrim(number_format((float) $datos['withholding_rate'], 4, ',', ''), '0'), ','),
                rtrim(rtrim(number_format((float) $datos['min_margin_pct'], 4, ',', ''), '0'), ','),
                $datos['margin_basis'] === Politica::INGRESO ? 'ingreso' : 'costo',
                $datos['valid_from'],
            ),
        );
    }
}
