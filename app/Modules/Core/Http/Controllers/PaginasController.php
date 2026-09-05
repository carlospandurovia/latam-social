<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Paginas;
use App\Modules\Core\Services\Reemplazos;
use App\Shared\Texto\Marcado;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Las páginas del sitio, desde el admin y desde la calle (L-2b).
 *
 * `ver()` es pública —es la política de privacidad— y **no acepta ningún
 * identificador**: recibe una dirección y devuelve lo publicado bajo ella o un
 * 404. Lo demás va detrás de `brand.manage`.
 */
final class PaginasController
{
    // -------------------------------------------------------------- la calle

    public function ver(string $slug): View
    {
        $pagina = Paginas::publica($slug);

        if ($pagina === null) {
            // 404 y no un redirect al acceso: una direccion que no existe no
            // existe, y mandar a alguien a una pantalla de entrada desde un
            // enlace a la politica de privacidad es peor que decirle que no
            // esta. Aqui, ademas, un buscador tiene que ver el 404.
            throw new NotFoundHttpException;
        }

        return view('publico.pagina', [
            'pagina' => $pagina,
            'marca' => Marca::datos(),
            'esDeCreadores' => false,
        ]);
    }

    // -------------------------------------------------------------- el admin

    public function index(): View
    {
        return view('paginas.index', [
            'paginas' => Paginas::todas(),
            'avisos' => Paginas::avisos(),
            'reservadas' => Paginas::reservadas(),
        ]);
    }

    public function editar(string $uuid): View
    {
        $pagina = Paginas::porUuid($uuid);
        $borrador = Paginas::borrador((int) $pagina->id);
        $vigente = Paginas::conVigente((string) $pagina->slug);
        $texto = (string) ($borrador->body_markdown ?? $vigente->body_markdown ?? '');

        return view('paginas.editar', [
            'pagina' => $pagina,
            'borrador' => $borrador,
            'vigente' => $vigente,
            'texto' => $texto,
            'historial' => Paginas::historial((int) $pagina->id),
            'catalogo' => Reemplazos::CATALOGO,
            'valores' => Reemplazos::valores(),
            'sinResolver' => Reemplazos::sinResolver($texto, [
                'pagina.titulo' => (string) $pagina->title,
                'pagina.vigente_desde' => 'hoy',
            ]),
            // La vista previa con los marcadores YA sustituidos: el editor
            // ensena lo que va a ver un visitante, no el codigo fuente.
            'vistaPrevia' => Marcado::aHtml(Reemplazos::aplicar($texto, [
                'pagina.titulo' => (string) $pagina->title,
                'pagina.vigente_desde' => 'hoy',
            ])),
            'revisiones' => Paginas::REVISION,
        ]);
    }

    public function guardar(Request $peticion, ?string $uuid = null): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]([a-z0-9-]{0,58}[a-z0-9])?$/',
                // La lista sale del enrutador, no de aqui: una escrita a mano se
                // queda vieja el dia que se anade una ruta.
                'not_in:'.implode(',', Paginas::reservadas())],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:180'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'show_in_footer' => ['nullable', 'boolean'],
        ], [
            'slug.regex' => 'La dirección va en minúsculas, con guiones y sin acentos.',
            'slug.not_in' => 'Esa dirección ya la usa el sistema: una página con ese nombre taparía '
                .'una pantalla que existe, y dejaría de abrirse.',
        ]);

        $datos['sort_order'] = (int) ($datos['sort_order'] ?? 100);
        $datos['show_in_footer'] = (bool) ($datos['show_in_footer'] ?? false);

        foreach (['meta_title', 'meta_description'] as $campo) {
            if (trim((string) ($datos[$campo] ?? '')) === '') {
                $datos[$campo] = null;
            }
        }

        $uuid = Paginas::guardar($uuid, $datos, (int) Auth::id());

        return redirect()->route('paginas.editar', ['uuid' => $uuid])
            ->with('mensaje', 'Página guardada.');
    }

    public function guardarTexto(Request $peticion, string $uuid): RedirectResponse
    {
        $datos = $peticion->validate([
            'body_markdown' => ['required', 'string', 'min:20'],
        ], [
            'body_markdown.min' => 'El texto de la página no puede estar prácticamente vacío.',
        ]);

        Paginas::guardarBorrador($uuid, (string) $datos['body_markdown']);

        return back()->with('mensaje', 'Borrador guardado. Todavía no se ve: hay que publicarlo.');
    }

    public function publicar(Request $peticion, string $uuid): RedirectResponse
    {
        $datos = $peticion->validate([
            'effective_from' => ['required', 'date'],
        ]);

        try {
            Paginas::publicar($uuid, (string) $datos['effective_from'], (int) Auth::id());
        } catch (RuntimeException $e) {
            return back()->with('aviso', $e->getMessage());
        }

        return back()->with('mensaje', 'Publicada. Ya se ve en el sitio.');
    }

    public function revisar(Request $peticion, string $uuid): RedirectResponse
    {
        $datos = $peticion->validate([
            'review_status' => ['required', 'in:sin_revisar,en_revision,revisado'],
            'review_note' => ['nullable', 'string', 'max:255'],
        ]);

        $datos['review_note'] = trim((string) ($datos['review_note'] ?? '')) ?: null;

        Paginas::marcarRevision($uuid, $datos);

        return back()->with('mensaje', 'Anotada la revisión jurídica.');
    }

    public function borrar(string $uuid): RedirectResponse
    {
        try {
            Paginas::borrar($uuid);
        } catch (RuntimeException $e) {
            return back()->with('aviso', $e->getMessage());
        }

        return redirect()->route('paginas.index')->with('mensaje', 'Página borrada.');
    }
}
