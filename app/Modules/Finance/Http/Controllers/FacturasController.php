<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Services\Facturas;
use App\Shared\Auth\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Comprobantes, desde el admin (9.9b).
 *
 * ### Dos permisos y no uno
 *
 * `finance.view` para mirar y `finance.invoice.issue` para emitir. Los dos ya
 * existían desde la Fase 3: quien concilia cobros necesita ver qué se facturó, y
 * no por eso tiene que poder emitir a nombre de la sociedad.
 *
 * ### Los errores salen con palabras, no con un 45000
 *
 * Todo lo que puede fallar aquí —una campaña sin sociedad que la facture, un
 * cliente sin perfil fiscal vigente, un país sin impuesto de venta declarado—
 * son estados normales de una instalación a medio configurar. Cada uno vuelve a
 * la pantalla con la frase que dice **dónde se arregla**, que es lo que
 * `DEC-190` pide en lugar de un bloqueo.
 */
final class FacturasController
{
    public function index(Request $peticion): View
    {
        return view('facturas.index', [
            'facturas' => Facturas::listado([
                'estado' => (string) $peticion->query('estado', ''),
            ]),
            'estados' => Facturas::ESTADOS,
            'estado' => (string) $peticion->query('estado', ''),
            'avisos' => Facturas::avisos(),
            'facturables' => self::campanasFacturables(),
            'puedeEmitir' => Permisos::tiene((int) Auth::id(), 'finance.invoice.issue'),
        ]);
    }

    public function ver(string $uuid): View
    {
        $factura = Facturas::ver($uuid);

        if ($factura === null) {
            abort(404);
        }

        return view('facturas.ver', [
            'factura' => $factura,
            'lineas' => Facturas::lineas((int) $factura->id),
            'series' => Facturas::seriesDe((int) $factura->legal_entity_id),
            'estados' => Facturas::ESTADOS,
            'regimenes' => Facturas::REGIMENES,
            'puedeEmitir' => Permisos::tiene((int) Auth::id(), 'finance.invoice.issue'),
        ]);
    }

    public function borrador(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'campaign_id' => ['required', 'integer', 'exists:campaigns,id'],
        ]);

        try {
            $uuid = Facturas::borrador((int) $datos['campaign_id']);
        } catch (Throwable $e) {
            return back()->with('aviso', self::enPalabras($e));
        }

        return redirect()->route('facturas.ver', ['uuid' => $uuid])
            ->with('exito', 'Borrador abierto. Todavía no gasta número: eso pasa al emitir.');
    }

    public function guardarLinea(Request $peticion, string $uuid): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'id' => ['nullable', 'integer', 'exists:invoice_lines,id'],
            'description' => ['required', 'string', 'min:3', 'max:300'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
        ]);

        try {
            Facturas::guardarLinea(
                $uuid,
                isset($datos['id']) ? (int) $datos['id'] : null,
                $datos,
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('aviso', self::enPalabras($e));
        }

        return redirect()->route('facturas.ver', ['uuid' => $uuid])
            ->with('exito', 'Línea guardada. El total se rehizo con ella.');
    }

    public function borrarLinea(string $uuid, int $linea): RedirectResponse
    {
        try {
            Facturas::borrarLinea($uuid, $linea);
        } catch (Throwable $e) {
            return back()->with('aviso', self::enPalabras($e));
        }

        return redirect()->route('facturas.ver', ['uuid' => $uuid])->with('exito', 'Línea quitada.');
    }

    public function descartar(string $uuid): RedirectResponse
    {
        try {
            Facturas::descartar($uuid);
        } catch (Throwable $e) {
            return back()->with('aviso', self::enPalabras($e));
        }

        return redirect()->route('facturas.index')
            ->with('exito', 'Borrador descartado. No gastó número, así que no deja hueco.');
    }

    public function emitir(Request $peticion, string $uuid): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'document_series_id' => ['required', 'integer', 'exists:document_series,id'],
        ]);

        try {
            $completo = Facturas::emitir($uuid, (int) $datos['document_series_id'], (int) Auth::id());
        } catch (Throwable $e) {
            return back()->with('aviso', self::enPalabras($e));
        }

        return redirect()->route('facturas.ver', ['uuid' => $uuid])
            ->with('exito', 'Emitida con el número '.$completo.'. A partir de aquí no se corrige: se anula.');
    }

    public function anular(Request $peticion, string $uuid): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        try {
            Facturas::anular($uuid, (string) $datos['motivo']);
        } catch (RuntimeException $e) {
            return back()->with('aviso', $e->getMessage());
        }

        return redirect()->route('facturas.ver', ['uuid' => $uuid])
            ->with('exito', 'Anulada, con el motivo escrito. El número sigue siendo suyo.');
    }

    /**
     * Las campañas que se pueden facturar hoy.
     *
     * Terminadas o cerradas, con importe, sin canje y sin comprobante vivo. No
     * se ofrece una campaña en curso: facturar antes de terminar es una decisión
     * comercial que hoy nadie ha pedido, y ofrecerla en el desplegable sería
     * darla por buena sin que nadie la haya decidido (`Q-65`).
     *
     * @return Collection<int, \stdClass>
     */
    private static function campanasFacturables(): Collection
    {
        return DB::table('campaigns as c')
            ->whereIn('c.status', ['completed', 'closed'])
            ->where('c.revenue_amount', '>', 0)
            ->where('c.is_gratis', 0)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('invoices as i')
                ->whereColumn('i.campaign_id', 'c.id')
                ->where('i.status', '<>', 'voided'))
            ->orderBy('c.code')
            ->get(['c.id', 'c.code', 'c.name', 'c.currency_code', 'c.revenue_amount']);
    }

    private static function enPalabras(Throwable $e): string
    {
        $mensaje = $e->getMessage();

        // Los tres 45000 de `9.9b` que un operador puede provocar sin querer.
        // Traducirlos aqui es la diferencia entre una frase y un volcado de SQL.
        return match (true) {
            str_contains($mensaje, 'no suman el total') => 'Las líneas no suman el total de la factura. Vuelva a guardar una línea para rehacerlo.',
            str_contains($mensaje, 'sin lineas') => 'Una factura sin líneas no dice qué se cobra: añada al menos una.',
            str_contains($mensaje, 'no se corrige') => 'Esa factura ya está emitida: no se corrige, se anula y se emite otra.',
            default => $mensaje,
        };
    }
}
