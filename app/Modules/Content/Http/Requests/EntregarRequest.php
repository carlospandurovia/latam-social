<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Requests;

use App\Shared\Files\Almacen;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Mandar una versión de un entregable (8.1).
 *
 * ### Lo que se valida aquí y lo que NO
 *
 * Aquí: la **forma** —que el enlace parezca un enlace, que la imagen sea una
 * imagen, que el texto quepa—. Lo que el brief exige —los hashtags y las
 * menciones— lo comprueba `Entregables::vetoParaEntregar()`, porque depende de
 * **otra fila** y una `FormRequest` que la va a buscar es una consulta escondida
 * en un sitio donde nadie la espera.
 *
 * ### El enlace tiene que ser `https://`
 *
 * `url` a secas admite `http://` y también `javascript:` en algunas versiones.
 * Ninguno de los dos vale: esta URL se pinta en una pantalla interna donde
 * alguien la va a pulsar. La base lo exige además con `ck_dv_url_https`.
 */
final class EntregarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'external_url' => ['nullable', 'string', 'max:500', 'url', 'starts_with:https://'],
            // Imagen de referencia, opcional. El video NO se sube: hoy `Almacen`
            // admite PDF e imagenes, y ampliarlo a mp4 es una decision de coste
            // --el almacen y la factura de S3-- que no toca en esta iteracion.
            // La lista sale de `Almacen`, que es quien de verdad decide: si un
            // dia se admite un formato mas, se admite en un sitio.
            'archivo' => ['nullable', 'file', 'max:10240', 'mimes:'.implode(',', Almacen::extensiones())],
            'caption' => ['nullable', 'string', 'max:5000'],
            'creator_notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'external_url.url' => 'Eso no parece un enlace.',
            'external_url.starts_with' => 'El enlace tiene que empezar por https://',
            'archivo.max' => 'La imagen no puede pasar de 10 MB.',
            'archivo.mimes' => 'Solo se admiten imagenes o PDF. El video va por enlace.',
            'caption.max' => 'El texto no puede pasar de 5.000 caracteres.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'external_url' => 'enlace',
            'archivo' => 'imagen',
            'caption' => 'texto de la publicacion',
            'creator_notes' => 'nota para el equipo',
        ];
    }
}
