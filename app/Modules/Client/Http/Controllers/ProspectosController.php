<?php

declare(strict_types=1);

namespace App\Modules\Client\Http\Controllers;

use App\Modules\Client\Services\Prospectos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * La bandeja de contactos (9.21c).
 *
 * Es la hermana de `/backoffice/solicitudes`: mismo problema —alguien de fuera
 * dejó sus datos y alguien de dentro los atiende— y por eso la misma forma. Ver
 * los contactos es `client.view`; moverlos es `client.manage`, que es la misma
 * separación que ya tienen los clientes.
 */
final class ProspectosController
{
    public function index(Request $peticion): View
    {
        $estado = trim((string) $peticion->query('estado', 'new'));

        return view('prospectos.index', [
            'prospectos' => Prospectos::bandeja($estado)->withQueryString(),
            'estado' => $estado,
            'estados' => Prospectos::ESTADOS,
            'conteos' => Prospectos::conteos(),
            'clientes' => DB::table('client_organizations')
                ->where('status', 'active')->orderBy('commercial_name')
                ->get(['id', 'commercial_name']),
        ]);
    }

    public function mover(Request $peticion, string $uuid): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'estado' => ['required', 'string'],
            'nota' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            Prospectos::mover(
                $uuid,
                (string) $datos['estado'],
                isset($datos['nota']) ? (string) $datos['nota'] : null,
                (int) Auth::id(),
            );
        } catch (RuntimeException $e) {
            return back()->with('aviso', $e->getMessage());
        }

        return back()->with('exito', 'Contacto actualizado.');
    }

    public function convertir(Request $peticion, string $uuid): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'client_organization_id' => ['required', 'integer', 'exists:client_organizations,id'],
        ]);

        try {
            Prospectos::convertir($uuid, (int) $datos['client_organization_id'], (int) Auth::id());
        } catch (RuntimeException $e) {
            return back()->with('aviso', $e->getMessage());
        }

        return back()->with('exito', 'Contacto enlazado con su cliente.');
    }
}
