<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Terminos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Los términos, desde el admin (9.16).
 *
 * Se edita el borrador, se publica declarando si el cambio es de fondo o menor,
 * y se marca el estado de revisión legal. Nada de esto bloquea: el sistema
 * arranca con un texto sembrado y aquí se cambia.
 */
final class TerminosController
{
    public function index(Request $peticion): View
    {
        $codigo = (string) $peticion->query('codigo', Terminos::codigo());

        return view('terminos.index', [
            'codigo' => $codigo,
            'codigos' => DB::table('terms_versions')->distinct()->orderBy('code')->pluck('code'),
            'vigente' => Terminos::vigente($codigo),
            'versiones' => Terminos::versiones($codigo),
            'avisos' => Terminos::avisos(),
            'revision' => Terminos::REVISION,
            'cambios' => Terminos::CAMBIO,
            'audiencias' => Terminos::AUDIENCIAS,
        ]);
    }

    public function show(string $uuid): View
    {
        $version = $this->version($uuid);

        return view('terminos.show', [
            'version' => $version,
            'revision' => Terminos::REVISION,
            'cambios' => Terminos::CAMBIO,
            'esBorrador' => $version->published_at === null,
            'aceptaciones' => (int) DB::table('terms_acceptances')
                ->where('terms_version_id', $version->id)->count(),
        ]);
    }

    /** Crea un borrador, opcionalmente copiando el texto de otra versión. */
    public function store(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'code' => ['required', 'string', 'max:40'],
            'version' => ['required', 'string', 'max:20'],
            'title' => ['required', 'string', 'max:160'],
            'audience' => ['required', 'string', 'in:'.implode(',', array_keys(Terminos::AUDIENCIAS))],
            'desde_uuid' => ['nullable', 'uuid'],
            'body' => ['nullable', 'string'],
        ]);

        // Partir de la vigente es el caso normal: casi nadie escribe unos
        // terminos desde cero, se corrige el texto que hay.
        $cuerpo = (string) ($datos['body'] ?? '');

        if (($datos['desde_uuid'] ?? null) !== null) {
            $cuerpo = (string) $this->version((string) $datos['desde_uuid'])->body;
        }

        try {
            $uuid = Terminos::crearBorrador(
                codigo: (string) $datos['code'],
                version: (string) $datos['version'],
                titulo: (string) $datos['title'],
                cuerpo: $cuerpo,
                audiencia: (string) $datos['audience'],
                autorId: (int) Auth::id(),
            );
        } catch (RuntimeException $e) {
            return redirect()->route('terminos.index', ['codigo' => $datos['code']])
                ->with('aviso', $e->getMessage());
        }

        return redirect()->route('terminos.show', $uuid)
            ->with('exito', 'Borrador creado. Edítalo y publícalo cuando quieras.');
    }

    public function update(Request $peticion, string $uuid): RedirectResponse
    {
        $datos = $peticion->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string'],
        ]);

        try {
            Terminos::guardarBorrador($uuid, (string) $datos['title'],
                (string) $datos['body'], (int) Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->route('terminos.show', $uuid)->with('aviso', $e->getMessage());
        }

        return redirect()->route('terminos.show', $uuid)->with('exito', 'Borrador guardado.');
    }

    public function publicar(Request $peticion, string $uuid): RedirectResponse
    {
        $datos = $peticion->validate([
            'change_type' => ['required', 'string', 'in:'.implode(',', array_keys(Terminos::CAMBIO))],
            'desde' => ['nullable', 'date'],
            // 9.19: los plazos de Q-46. Se eligen AL PUBLICAR y despues son
            // inmutables: son parte de lo que se le comunico a la gente.
            'acceptance_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'readonly_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);

        try {
            Terminos::publicar($uuid, (string) $datos['change_type'],
                $datos['desde'] ?? null, (int) Auth::id(),
                isset($datos['acceptance_days']) ? (int) $datos['acceptance_days'] : null,
                isset($datos['readonly_days']) ? (int) $datos['readonly_days'] : null);
        } catch (RuntimeException $e) {
            return redirect()->route('terminos.show', $uuid)->with('aviso', $e->getMessage());
        }

        return redirect()->route('terminos.show', $uuid)->with('exito',
            (string) $datos['change_type'] === 'menor'
                ? 'Publicada como cambio menor: quien ya había aceptado sigue en regla.'
                : 'Publicada. Los creadores tendrán que aceptar esta versión.');
    }

    public function revision(Request $peticion, string $uuid): RedirectResponse
    {
        $datos = $peticion->validate([
            'review_status' => ['required', 'string', 'in:'.implode(',', array_keys(Terminos::REVISION))],
            'review_note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            Terminos::marcarRevision($uuid, (string) $datos['review_status'],
                $datos['review_note'] ?? null, (int) Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->route('terminos.show', $uuid)->with('aviso', $e->getMessage());
        }

        return redirect()->route('terminos.show', $uuid)->with('exito', 'Estado de revisión actualizado.');
    }

    private function version(string $uuid): object
    {
        try {
            return Terminos::porUuid($uuid);
        } catch (RuntimeException) {
            throw new NotFoundHttpException('No existe esa version de los terminos.');
        }
    }
}
