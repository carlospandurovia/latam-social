<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Landing;
use App\Modules\Core\Services\Reemplazos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * El texto de la portada, desde el admin (9.21b, L-3).
 *
 * `brand.manage` y no un permiso nuevo: quien decide cómo nos llamamos y de qué
 * color somos decide también qué dice la portada. Es el mismo trabajo, y un
 * permiso más para lo mismo sólo añade un sitio donde olvidarse de darlo.
 *
 * ### Por qué un bloque se dirige por su sección y no por su página
 *
 * Desde `L-3` la ruta de un bloque lleva la página **y** la sección, y aquí se
 * comprueba que esa sección es de esa página antes de tocar nada. Sin esa
 * comprobación, quien administra una marca podría mover un bloque a una sección
 * de otra escribiendo un número en el formulario. Hoy sólo hay una marca; el día
 * que haya dos, esto ya estaría hecho.
 */
final class LandingController
{
    public function index(): View
    {
        return view('landing.index', [
            'paginas' => Landing::todas(),
            'layouts' => Landing::LAYOUTS,
            'iconos' => Landing::ICONOS,
            // L-4: lo que se puede escribir entre llaves. Se ensena en la
            // pantalla y no en un manual: un marcador que nadie sabe que existe
            // acaba siendo una razon social escrita a mano dentro del texto.
            'marcadores' => Reemplazos::CATALOGO,
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
            'form_heading' => ['nullable', 'string', 'min:3', 'max:120'],
            'form_intro' => ['nullable', 'string', 'max:320'],
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

    // ------------------------------------------------------------- secciones

    public function guardarSeccion(Request $peticion, int $pagina): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'id' => ['nullable', 'integer', 'exists:landing_sections,id'],
            // Se pide el ancla en texto libre y `Landing::ancla()` la normaliza:
            // quien escribe «Cómo funciona» obtiene `como-funciona` sin tener
            // que saber la regla. La base la comprueba igualmente.
            'code' => ['required', 'string', 'max:60'],
            'layout' => ['required', 'in:'.implode(',', array_keys(Landing::LAYOUTS))],
            'eyebrow' => ['nullable', 'string', 'max:60'],
            'title' => ['nullable', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:320'],
            'cta_label' => ['nullable', 'string', 'max:60'],
            'cta_url' => ['nullable', 'string', 'max:255', 'regex:#^(https://|/|\#)#'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_visible' => ['nullable', 'boolean'],
            'show_in_nav' => ['nullable', 'boolean'],
        ]);

        // La regla de `ck_ls_menu`, dicha aqui como frase. La base la impone
        // igual; esto es para que llegue como un aviso al lado del campo y no
        // como un numero de error.
        if (($datos['show_in_nav'] ?? false) && trim((string) ($datos['title'] ?? '')) === '') {
            return back()->withInput()->with(
                'aviso',
                'Para que la franja salga en el menú necesita un encabezado: es lo que se lee en él.',
            );
        }

        if (($datos['cta_url'] ?? '') !== '' && trim((string) ($datos['cta_label'] ?? '')) === '') {
            return back()->withInput()->with(
                'aviso',
                'El botón de la franja lleva a algún sitio pero no dice nada. Ponle un rótulo.',
            );
        }

        $seccionId = isset($datos['id']) ? (int) $datos['id'] : null;

        if ($seccionId !== null && Landing::seccionDe($pagina, $seccionId) === null) {
            return back()->with('aviso', 'Esa franja no es de esta portada.');
        }

        Landing::guardarSeccion($pagina, $seccionId, $datos);

        return redirect()->route('landing.index')->with('exito', 'Franja guardada.');
    }

    public function borrarSeccion(int $pagina, int $seccion): RedirectResponse
    {
        Landing::borrarSeccion($pagina, $seccion);

        return redirect()->route('landing.index')->with('exito', 'Franja quitada, con sus bloques.');
    }

    // --------------------------------------------------------------- bloques

    public function guardarBloque(Request $peticion, int $pagina, int $seccion): RedirectResponse
    {
        if (Landing::seccionDe($pagina, $seccion) === null) {
            return back()->with('aviso', 'Esa franja no es de esta portada.');
        }

        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'id' => ['nullable', 'integer', 'exists:landing_blocks,id'],
            'heading' => ['required', 'string', 'min:3', 'max:120'],
            'body' => ['nullable', 'string', 'max:600'],
            // Sin `in:` a proposito: la base tampoco los encierra. Un nombre que
            // no conocemos pinta el icono generico, como las redes del pie.
            'icon' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'cta_label' => ['nullable', 'string', 'max:60'],
            'cta_url' => ['nullable', 'string', 'max:255', 'regex:#^(https://|/|\#)#'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_visible' => ['nullable', 'boolean'],
        ]);

        Landing::guardarBloque($seccion, isset($datos['id']) ? (int) $datos['id'] : null, $datos);

        return redirect()->route('landing.index')->with('exito', 'Bloque guardado.');
    }

    public function borrarBloque(int $pagina, int $seccion, int $bloque): RedirectResponse
    {
        if (Landing::seccionDe($pagina, $seccion) === null) {
            return back()->with('aviso', 'Esa franja no es de esta portada.');
        }

        Landing::borrarBloque($seccion, $bloque);

        return redirect()->route('landing.index')->with('exito', 'Bloque quitado.');
    }
}
