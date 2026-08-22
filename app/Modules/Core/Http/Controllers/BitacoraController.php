<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * La bitácora, para quien tenga `audit.view`.
 *
 * Dos decisiones sobre el rendimiento, porque esta tabla **solo crece** y una
 * pantalla lenta acaba siendo una pantalla que nadie abre:
 *
 * 1. **Se ordena por `id`, no por `occurred_at`.** La clave primaria ya es
 *    monótona con la inserción, así que recorrerla al revés sale gratis y sin
 *    `filesort`. Ordenar por `occurred_at` obligaría a un índice extra solo para
 *    el caso más común. Además `id` no empata nunca, y `occurred_at` sí:
 *    dos entradas del mismo milisegundo saldrían en orden arbitrario.
 *
 * 2. **Los filtros van sobre columnas indexadas** (`entity_type`, `actor_user_id`,
 *    `occurred_at`). El de texto libre sobre `action` es el único que no, y por
 *    eso es prefijo (`like 'x%'`) y no contención: un `%x%` no usa índice y en
 *    una tabla de auditoría eso se nota el día que hay millones de filas.
 */
final class BitacoraController
{
    public function __invoke(Request $request): View
    {
        $tipo = trim((string) $request->query('tipo', ''));
        $actor = (int) $request->query('actor', 0);
        $accion = trim((string) $request->query('accion', ''));
        $desde = trim((string) $request->query('desde', ''));
        $hasta = trim((string) $request->query('hasta', ''));

        $consulta = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
            ->select([
                'a.id', 'a.action', 'a.entity_type', 'a.entity_id',
                'a.changes', 'a.occurred_at', 'a.actor_label',
                'u.name as actor_actual',
            ])
            ->orderByDesc('a.id');

        if ($tipo !== '') {
            $consulta->where('a.entity_type', $tipo);
        }
        if ($actor > 0) {
            $consulta->where('a.actor_user_id', $actor);
        }
        if ($accion !== '') {
            // Prefijo, no contención: `like '%x%'` no usa índice.
            $consulta->where('a.action', 'like', $accion.'%');
        }
        if ($desde !== '') {
            $consulta->where('a.occurred_at', '>=', $desde.' 00:00:00');
        }
        if ($hasta !== '') {
            $consulta->where('a.occurred_at', '<=', $hasta.' 23:59:59');
        }

        return view('bitacora.index', [
            'entradas' => $consulta->paginate(50)->withQueryString(),
            'tipos' => DB::table('audit_logs')->distinct()->orderBy('entity_type')->pluck('entity_type'),
            'actores' => DB::table('audit_logs as a')
                ->join('users as u', 'u.id', '=', 'a.actor_user_id')
                ->distinct()
                ->orderBy('u.name')
                ->pluck('u.name', 'u.id'),
            'filtros' => compact('tipo', 'actor', 'accion', 'desde', 'hasta'),
        ]);
    }
}
