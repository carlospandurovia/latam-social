<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Services\Lotes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Los lotes de pago (9.6).
 *
 * Dos permisos distintos, y ésa es la iteración entera: `finance.payout.create`
 * arma; `finance.payout.approve` firma. Que sean dos no basta —la misma persona
 * podría tener los dos—, así que **quién firmó** lo comprueba la base:
 * `ck_pbatch_segregation` no admite que el aprobador sea el autor.
 */
final class LotesController
{
    public function index(Request $peticion): View
    {
        $entidadId = (int) $peticion->query('entidad', '0');
        $moneda = mb_strtoupper((string) $peticion->query('moneda', ''));

        return view('lotes.index', [
            'lotes' => DB::table('payout_batches as pb')
                ->join('legal_entities as le', 'le.id', '=', 'pb.legal_entity_id')
                ->leftJoin('users as a', 'a.id', '=', 'pb.created_by_user_id')
                ->leftJoin('users as f', 'f.id', '=', 'pb.approved_by_user_id')
                ->orderByDesc('pb.id')->limit(50)
                ->get(['pb.uuid', 'pb.code', 'pb.status', 'pb.currency_code',
                    'pb.approved_at', 'pb.executed_at',
                    'le.code as sociedad', 'a.name as autor', 'f.name as aprobador']),
            'estados' => Lotes::ESTADOS,
            'entidades' => DB::table('legal_entities')->where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'legal_name']),
            'monedas' => DB::table('currencies')->where('is_active', 1)
                ->orderBy('code')->get(['code']),
            'entidadId' => $entidadId,
            'moneda' => $moneda,
            // La vista previa: qué se pagaría si se armara ahora. Se enseña
            // ANTES de crear nada, porque un lote no se borra.
            'pagables' => $entidadId > 0 && $moneda !== ''
                ? Lotes::loQueSePodriaPagar($entidadId, $moneda)
                : collect(),
        ]);
    }

    public function store(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'legal_entity_id' => ['required', 'integer', 'exists:legal_entities,id'],
            'currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code'],
        ]);

        try {
            $uuid = Lotes::armar(
                (int) $datos['legal_entity_id'],
                mb_strtoupper((string) $datos['currency_code']),
                (int) Auth::id(),
            );
        } catch (RuntimeException $e) {
            return redirect()->route('lotes.index')->with('aviso', $e->getMessage());
        }

        return redirect()->route('lotes.show', $uuid)->with('exito', 'Lote armado. Falta que lo firme otra persona.');
    }

    public function show(string $uuid): View
    {
        $lote = $this->lote($uuid);

        return view('lotes.show', [
            'lote' => $lote,
            'estados' => Lotes::ESTADOS,
            'pagos' => DB::table('payouts as p')
                ->join('creators as cr', 'cr.id', '=', 'p.creator_id')
                ->where('p.payout_batch_id', $lote->id)
                ->orderBy('cr.display_name')
                ->get(['p.id', 'p.amount', 'p.currency_code', 'p.status',
                    'p.beneficiary_name_snapshot', 'p.account_masked_snapshot',
                    'cr.display_name as creador']),
            'sociedad' => DB::table('legal_entities')->where('id', $lote->legal_entity_id)
                ->value('legal_name'),
            // El veto se calcula para QUIEN MIRA: si es quien lo armó, la
            // pantalla lo dice antes de que pulse (`BR-FIN-005`).
            'veto' => Lotes::vetoParaAprobar($lote, (int) Auth::id()),
            'total' => DB::table('payouts')->where('payout_batch_id', $lote->id)
                ->where('status', '<>', 'cancelled')->sum('amount'),
        ]);
    }

    public function aprobar(string $uuid): RedirectResponse
    {
        $lote = $this->lote($uuid);

        try {
            Lotes::aprobar($lote, (int) Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->route('lotes.show', $uuid)->with('aviso', $e->getMessage());
        }

        return redirect()->route('lotes.show', $uuid)
            ->with('exito', 'Lote aprobado. Al ejecutarlo, el dinero sale.');
    }

    public function ejecutar(string $uuid): RedirectResponse
    {
        $lote = $this->lote($uuid);

        try {
            $pagos = Lotes::ejecutar($lote, (int) Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->route('lotes.show', $uuid)->with('aviso', $e->getMessage());
        }

        return redirect()->route('lotes.show', $uuid)
            ->with('exito', "Lote ejecutado: {$pagos} pagos enviados y anotados en el libro mayor.");
    }

    public function sacar(Request $peticion, string $uuid, int $pago): RedirectResponse
    {
        $this->lote($uuid);

        $motivo = $peticion->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:255'],
        ])['motivo'];

        try {
            Lotes::sacarDelLote($pago, (string) $motivo, (int) Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->route('lotes.show', $uuid)->with('aviso', $e->getMessage());
        }

        return redirect()->route('lotes.show', $uuid)
            ->with('exito', 'Pago sacado del lote. Sus devengos vuelven a la cola; el resto se paga igual.');
    }

    public function csv(string $uuid): Response
    {
        $lote = $this->lote($uuid);

        return response(Lotes::csv((int) $lote->id), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$lote->code.'.csv"',
        ]);
    }

    private function lote(string $uuid): object
    {
        $fila = DB::table('payout_batches')->where('uuid', $uuid)->first();

        if ($fila === null) {
            throw new NotFoundHttpException('No existe ese lote.');
        }

        return $fila;
    }
}
