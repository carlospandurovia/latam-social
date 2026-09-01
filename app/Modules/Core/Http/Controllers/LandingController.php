<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Landing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * El texto de la portada, desde el admin (9.21b).
 *
 * `brand.manage` y no un permiso nuevo: quien decide cómo nos llamamos y de qué
 * color somos decide también qué dice la portada. Es el mismo trabajo, y un
 * permiso más para lo mismo sólo añade un sitio donde olvidarse de darlo.
 */
final class LandingController
{
    public function index(): View
    {
        return view('landing.index', [
            'paginas' => Landing::todas(),
            'tipos' => Landing::TIPOS_DE_BLOQUE,
            'avisos' => Landing::avisos(),
        ]);
    }

    public function update(Request $peticion, int $pagina): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            // Los mínimos son los de la base (`ck_lp_titular`, `ck_lp_boton`):
            // pedirlos aquí es lo que convierte un `45000` en una frase junto al
            // campo que hay que corregir.
            'headline' => ['required', 'string', 'min:10', 'max:160'],
            'subheadline' => ['nullable', 'string', 'max:320'],
            'cta_label' => ['required', 'string', 'min:2', 'max:60'],
            'cta_url' => ['nullable', 'string', 'max:255', 'regex:#^(https://|/)#'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:180'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        try {
            Landing::guardar($pagina, $datos);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('aviso', $e->getMessage());
        }

        return redirect()->route('landing.index')->with('exito', 'Portada guardada.');
    }

    public function guardarBloque(Request $peticion, int $pagina): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'id' => ['nullable', 'integer', 'exists:landing_blocks,id'],
            'kind' => ['required', 'in:'.implode(',', array_keys(Landing::TIPOS_DE_BLOQUE))],
            'heading' => ['required', 'string', 'min:3', 'max:120'],
            'body' => ['nullable', 'string', 'max:600'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_visible' => ['nullable', 'boolean'],
        ]);

        Landing::guardarBloque($pagina, isset($datos['id']) ? (int) $datos['id'] : null, $datos);

        return redirect()->route('landing.index')->with('exito', 'Bloque guardado.');
    }

    public function borrarBloque(int $pagina, int $bloque): RedirectResponse
    {
        Landing::borrarBloque($pagina, $bloque);

        return redirect()->route('landing.index')->with('exito', 'Bloque quitado.');
    }
}
