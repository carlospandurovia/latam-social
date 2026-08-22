<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Controllers;

use App\Modules\Creator\Http\Requests\ActualizarCreadorRequest;
use App\Shared\Audit\Bitacora;
use Illuminate\Http\RedirectResponse;
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

    /** @var list<string> Lo único que esta pantalla puede tocar. */
    private const EDITABLES = [
        'display_name', 'phone', 'city',
        'payment_term_days', 'preferred_currency_code', 'locale', 'timezone',
    ];

    public function edit(string $uuid): View
    {
        $creador = $this->porUuid($uuid);

        return view('creadores.editar', [
            'creador' => $creador,
            'monedas' => DB::table('currencies')->where('is_active', 1)->orderBy('code')->get(['code', 'name']),
            'idiomas' => DB::table('languages')->where('is_active', 1)->orderBy('name')->get(['code', 'name']),
        ]);
    }

    public function update(ActualizarCreadorRequest $request, string $uuid): RedirectResponse
    {
        $creador = $this->porUuid($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        $antes = [];
        foreach (self::EDITABLES as $campo) {
            $antes[$campo] = $creador->{$campo} ?? null;
        }

        $cambios = Bitacora::diferencias($antes, $datos);

        // Sin cambios no se escribe ni en la tabla ni en la bitácora. Una
        // entrada de auditoría que dice «no cambió nada» es ruido donde luego
        // nadie encuentra lo que sí cambió.
        if ($cambios === []) {
            return redirect()
                ->route('creadores.show', $uuid)
                ->with('aviso', 'No había nada que cambiar.');
        }

        // `WHERE id = ?` con el id ya resuelto: nunca una subconsulta sobre
        // `creators`, que es la tabla que se está modificando (DEC-052).
        DB::table('creators')
            ->where('id', $creador->id)
            ->update($datos + ['updated_at' => now()]);

        Bitacora::registrar(
            accion: 'creator.updated',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: $cambios,
        );

        return redirect()
            ->route('creadores.show', $uuid)
            ->with('exito', 'Creador actualizado. El cambio quedó en la bitácora.');
    }

    private function porUuid(string $uuid): object
    {
        $creador = DB::table('creators')->where('uuid', $uuid)->first();

        if ($creador === null) {
            throw new NotFoundHttpException('Creador no encontrado.');
        }

        return $creador;
    }
}
