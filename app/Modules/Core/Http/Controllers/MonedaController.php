<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Cambio;
use App\Modules\Core\Services\Consolidacion;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * La moneda en la que habla el panel (`D-12`).
 *
 * ### Detrás de `fx.manage`
 *
 * El mismo permiso que declara qué fuente publica cada tipo de cambio, y por el
 * mismo motivo: quien decide de dónde sale la tasa es quien decide en qué moneda
 * se presenta el total. Lo tienen `admin` y `finance`.
 *
 * ### Guardar es confirmar
 *
 * La fila nace sembrada con `PEN` y **sin confirmar**, así que el panel funciona
 * desde el primer día y la configuración avisa en ámbar. Pulsar guardar aquí es
 * lo que dice «sí, ésta es la moneda del negocio» y apaga el aviso. No hay
 * ningún estado en el que el sistema se pare a esperar: `DEC-190`.
 */
final class MonedaController
{
    public function index(): View
    {
        $fila = Consolidacion::fila();
        $ajustes = Consolidacion::ajustes();

        return view('moneda.index', [
            'ajustes' => $ajustes,
            'avisos' => Consolidacion::avisos(),
            'monedas' => Consolidacion::monedas(),
            'lados' => Cambio::LADOS,
            'partida' => Consolidacion::PARTIDA,
            // Ya formateada: una plantilla que llama a `Carbon::parse` es
            // logica en la plantilla, y `docs/08` la prohibe.
            'confirmado' => $fila?->confirmed_at === null
                ? null
                : CarbonImmutable::parse((string) $fila->confirmed_at)->format('d/m/Y H:i'),
        ]);
    }

    public function update(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            // Contra el catalogo, no contra una lista escrita aqui: anadir una
            // moneda es sembrar una fila, no desplegar.
            'base_currency_code' => ['required', 'string', 'size:3',
                Rule::exists('currencies', 'code')->where('is_active', 1)],
            'income_rate_side' => ['required', Rule::in(array_keys(Cambio::LADOS))],
            'expense_rate_side' => ['required', Rule::in(array_keys(Cambio::LADOS))],
        ], [
            'base_currency_code.exists' => 'Esa moneda no está en el catálogo o está apagada. '
                .'El panel no puede consolidar en una moneda que no existe.',
        ]);

        $moneda = mb_strtoupper((string) $datos['base_currency_code']);

        Consolidacion::guardar([
            'base_currency_code' => $moneda,
            'income_rate_side' => (string) $datos['income_rate_side'],
            'expense_rate_side' => (string) $datos['expense_rate_side'],
        ], Auth::id() === null ? null : (int) Auth::id());

        return back()->with('mensaje', sprintf(
            'El panel consolida en %s: lo que entra al tipo de %s y lo que sale al de %s.',
            $moneda,
            mb_strtolower(Cambio::LADOS[$datos['income_rate_side']] ?? (string) $datos['income_rate_side']),
            mb_strtolower(Cambio::LADOS[$datos['expense_rate_side']] ?? (string) $datos['expense_rate_side']),
        ));
    }
}
