<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Integraciones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Las credenciales de cada API, desde el admin (9.17d).
 *
 * ### El secreto entra y no vuelve a salir
 *
 * El formulario de credencial es de **escritura**: se teclea el valor nuevo y se
 * guarda. En pantalla sólo se ven los cuatro últimos, quién la puso y cuándo.
 * No hay ninguna acción que devuelva el valor, y eso no es una omisión: es lo
 * que hace que enseñar esta pantalla a alguien no sea entregarle las claves.
 *
 * ### `integration.manage` ya existía
 *
 * Desde `9.2`, para la credencial de la fuente de tipos de cambio. Es el mismo
 * trabajo —quién puede tocar las llaves de las APIs— así que se reutiliza en vez
 * de inventar el segundo permiso para lo mismo.
 */
final class IntegracionesController
{
    public function index(): View
    {
        return view('integraciones.index', [
            'conexiones' => Integraciones::conexiones(),
            'proveedores' => Integraciones::proveedores(),
            'sociedades' => DB::table('legal_entities')->where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'legal_name']),
            'entornos' => Integraciones::ENTORNOS,
            'estados' => Integraciones::ESTADOS,
            'clases' => Integraciones::CLASES,
            // Por conexion, que credenciales VIVAS tiene. Nunca su valor.
            'credenciales' => Integraciones::conexiones()
                ->mapWithKeys(fn (object $c): array => [
                    (int) $c->id => Integraciones::estado((int) $c->id),
                ])->all(),
        ]);
    }

    public function store(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate($this->reglas());

        try {
            Integraciones::guardarConexion(null, $datos, (int) Auth::id());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('aviso', $e->getMessage());
        }

        return redirect()->route('integraciones.index')
            ->with('exito', 'Conexión guardada. Ahora ponle sus credenciales.');
    }

    public function update(Request $peticion, string $uuid): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate($this->reglas());

        try {
            Integraciones::guardarConexion($uuid, $datos, (int) Auth::id());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('aviso', $e->getMessage());
        }

        return redirect()->route('integraciones.index')->with('exito', 'Conexión actualizada.');
    }

    /** Guarda un secreto: revoca el anterior y crea la versión siguiente. */
    public function credencial(Request $peticion, string $uuid): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys(Integraciones::CLASES))],
            'secreto' => ['required', 'string', 'min:4', 'max:500'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $conexion = Integraciones::porUuid($uuid);

        try {
            Integraciones::guardarSecreto(
                (int) $conexion->id,
                (string) $datos['kind'],
                (string) $datos['secreto'],
                (int) Auth::id(),
                (string) ($datos['motivo'] ?? 'Rotacion desde el admin.'),
            );
        } catch (RuntimeException $e) {
            return back()->with('aviso', $e->getMessage());
        }

        return redirect()->route('integraciones.index')->with(
            'exito',
            'Credencial guardada. La anterior queda revocada, y en pantalla sólo se ven '
            .'sus cuatro últimos: no se puede volver a leer.',
        );
    }

    /** @return array<string, mixed> */
    private function reglas(): array
    {
        return [
            'integration_provider_id' => ['required', 'integer', 'exists:integration_providers,id'],
            'legal_entity_id' => ['nullable', 'integer', 'exists:legal_entities,id'],
            'name' => ['required', 'string', 'max:120'],
            'environment' => ['required', 'in:'.implode(',', array_keys(Integraciones::ENTORNOS))],
            // `https` y no `url`: `ck_iconn_url` exige https en una conexion
            // activa, y una regla que admite `http` aqui deja que el `45000`
            // salga despues sin explicar nada.
            'base_url' => ['nullable', 'string', 'max:255', 'regex:#^https://#'],
            'username' => ['nullable', 'string', 'max:120'],
            'status' => ['required', 'in:'.implode(',', array_keys(Integraciones::ESTADOS))],
        ];
    }
}
