<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Http\Requests\GuardarMarcaRequest;
use App\Modules\Core\Services\Marca;
use App\Shared\Files\Almacen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La identidad de la plataforma, desde el admin (9.17).
 *
 * Nombre, lema, logotipo, favicon, colores, tipografía, web, correo de soporte y
 * pie legal. Nada de esto vuelve a estar escrito en una plantilla.
 *
 * ### Dos puertas y no una
 *
 * `index`/`update` son de administración y van detrás de `brand.manage`.
 * `logo`/`favicon` **no llevan permiso ni sesión**: la pantalla de acceso la ve
 * quien todavía no ha entrado, y ahí es donde más se nota la marca. Son las
 * únicas dos rutas abiertas de esta iteración y están en
 * `tools/pruebas/RUTAS-ABIERTAS` con su motivo escrito, como exige `9.14b`.
 *
 * Lo que las hace seguras no es un permiso: es que **no aceptan un
 * identificador**. `logo()` sirve el logotipo de la marca por defecto y nada
 * más; no hay forma de pedirle otro archivo, ni siquiera otro logotipo.
 */
final class MarcaController
{
    public function index(): View
    {
        return view('marca.index', [
            // `marca` es SIEMPRE el juego completo de `datos()`, el mismo que
            // el compositor pone en las plantillas: dos formas distintas de la
            // misma variable en la misma pagina es como se llega a un
            // `$marca['logo']` sobre un objeto. `fila` es lo que hay guardado
            // de verdad, que es lo que el formulario tiene que reflejar --con
            // sus huecos-- y no lo que se esta enseñando en su lugar.
            'marca' => Marca::datos(),
            'fila' => Marca::actual(),
            'avisos' => Marca::avisos(),
            'extensiones' => Almacen::extensiones(),
            'maxKb' => (int) config('latam.archivos.max_kb', 8192),
        ]);
    }

    public function update(GuardarMarcaRequest $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validated();

        // Los archivos no son campos de la tabla: van por `Almacen`, que
        // comprueba el contenido real y no lo que diga el navegador.
        unset($datos['logo'], $datos['favicon']);

        // Un campo que se deja en blanco se guarda como NULL y no como ''. Los
        // CHECK de correo y de web admiten NULL --«no configurado»-- y rechazan
        // una cadena vacia, que no es lo mismo y no significa nada.
        foreach (['tagline', 'legal_footer', 'website', 'support_email',
            'primary_color', 'secondary_color', 'sidebar_color', 'font_family'] as $campo) {
            if (trim((string) ($datos[$campo] ?? '')) === '') {
                $datos[$campo] = null;
            }
        }

        try {
            $cambios = Marca::guardar(
                $datos,
                $peticion->file('logo') instanceof UploadedFile
                    ? $peticion->file('logo') : null,
                $peticion->file('favicon') instanceof UploadedFile
                    ? $peticion->file('favicon') : null,
            );
        } catch (\RuntimeException $e) {
            // `Almacen` rechaza por CONTENIDO real. La regla `mimes:` del
            // formulario mira la extension, asi que un `.png` que por dentro no
            // lo es pasa la validacion y muere aqui. Se cuenta, no se cae.
            return back()->withInput()->with('aviso', $e->getMessage());
        }

        if ($cambios === 0) {
            return redirect()->route('marca.index')->with('aviso', 'No cambió nada.');
        }

        return redirect()->route('marca.index')->with(
            'exito',
            $cambios === 1
                ? 'Marca guardada: un campo cambiado.'
                : "Marca guardada: {$cambios} campos cambiados.",
        );
    }

    /** El logotipo de la marca por defecto. Sin sesión: sale en la pantalla de acceso. */
    public function logo(): StreamedResponse
    {
        return $this->servir('logo');
    }

    /** El icono de la pestaña. Si no hay uno propio, se sirve el logotipo. */
    public function favicon(): StreamedResponse
    {
        return $this->servir('favicon');
    }

    private function servir(string $cual): StreamedResponse
    {
        $archivo = Marca::archivo($cual);

        if ($archivo === null) {
            throw new NotFoundHttpException('La marca no tiene ese archivo.');
        }

        if (!Storage::disk((string) $archivo->disk)->exists((string) $archivo->path)) {
            // Misma «evidencia fantasma» que en 9.15: la fila existe y el
            // archivo no. Se dice; no se sirve un cuerpo vacio con un 200.
            throw new NotFoundHttpException('El archivo de la marca no esta en el disco.');
        }

        return Storage::disk((string) $archivo->disk)->response(
            (string) $archivo->path,
            (string) $archivo->original_name,
            [
                'Content-Type' => (string) $archivo->mime_type,
                // Publico y con caducidad corta: el logotipo se cambia dos veces
                // en la vida del producto, pero cuando se cambia nadie quiere
                // esperar un dia a verlo. El `ETag` es el uuid del archivo, asi
                // que subir otro invalida la cache sola.
                'Cache-Control' => 'public, max-age=300',
                'ETag' => '"'.$archivo->uuid.'"',
                // Aunque solo se admiten imagenes, la cabecera se pone igual:
                // el dia que `Almacen` acepte SVG esta linea ya esta puesta.
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
