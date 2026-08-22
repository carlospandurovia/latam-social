<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreadoresController
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $consulta = DB::table('creators as cr')
            ->join('countries as p', 'p.id', '=', 'cr.country_id')
            ->select([
                'cr.uuid', 'cr.display_name', 'cr.email', 'cr.status',
                'cr.document_type', 'cr.document_number', 'cr.payment_term_days',
                'p.name as pais',
                DB::raw('TIMESTAMPDIFF(YEAR, cr.birth_date, CURDATE()) as edad'),
            ])
            ->orderBy('cr.display_name');

        if ($q !== '') {
            // Parámetros ligados, nunca concatenación (docs/08).
            $consulta->where(function ($w) use ($q): void {
                $like = '%'.$q.'%';
                $w->where('cr.display_name', 'like', $like)
                    ->orWhere('cr.email', 'like', $like)
                    ->orWhere('cr.document_number', 'like', $like);
            });
        }

        return view('creadores.index', [
            'creadores' => $consulta->paginate(25),
            'q' => $q,
        ]);
    }

    public function show(string $uuid): View
    {
        $creador = DB::table('creators as cr')
            ->join('countries as p', 'p.id', '=', 'cr.country_id')
            ->where('cr.uuid', $uuid)
            ->select([
                'cr.*', 'p.name as pais',
                DB::raw('TIMESTAMPDIFF(YEAR, cr.birth_date, CURDATE()) as edad'),
            ])
            ->first();

        if ($creador === null) {
            throw new NotFoundHttpException('Creador no encontrado.');
        }

        return view('creadores.show', [
            'creador' => $creador,
            'tutores' => DB::table('creator_guardians')
                ->where('creator_id', $creador->id)
                ->orderByDesc('status')
                ->get(),
            // Los seguidores salen del ÚLTIMO snapshot, nunca de una columna:
            // BR-CREATOR-005 prohíbe que un valor nuevo sobrescriba al anterior,
            // y por eso `creators` no tiene followers_count (docs 2.3 §4).
            'cuentas' => DB::table('social_accounts as sa')
                ->join('platforms as pl', 'pl.id', '=', 'sa.platform_id')
                ->leftJoin('social_account_snapshots as sn', function ($j): void {
                    $j->on('sn.social_account_id', '=', 'sa.id')
                        ->whereRaw('sn.id = (SELECT MAX(id) FROM social_account_snapshots WHERE social_account_id = sa.id)');
                })
                ->where('sa.creator_id', $creador->id)
                ->orderByDesc('sa.is_primary')
                ->get([
                    'sa.handle', 'sa.verification_status', 'pl.name as red',
                    'sn.followers', 'sn.captured_at',
                ]),
            'tarifas' => DB::table('creator_rates as r')
                ->join('content_formats as f', 'f.id', '=', 'r.content_format_id')
                ->join('platforms as pl', 'pl.id', '=', 'f.platform_id')
                ->where('r.creator_id', $creador->id)
                ->whereNull('r.valid_to')
                ->orderBy('pl.name')
                ->get(['pl.name as red', 'f.code as formato', 'r.currency_code', 'r.amount', 'r.valid_from']),
        ]);
    }
}
