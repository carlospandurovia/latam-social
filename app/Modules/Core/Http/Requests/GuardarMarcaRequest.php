<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use App\Shared\Auth\Permisos;
use App\Shared\Files\Almacen;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La identidad de la plataforma (9.17).
 *
 * `brand.manage` y nada más: quien toca esto cambia lo que ve TODO el mundo —el
 * equipo, los clientes y los creadores— en todas las pantallas a la vez, y
 * también en la de acceso, que se ve sin haber entrado.
 *
 * ### El `code` no está aquí, y es a propósito
 *
 * `tg_pb_code` lo impide en el motor. Aquí ni se pide: un campo que el motor va
 * a rechazar con un `45000` no debe existir en el formulario. El nombre visible
 * —`name`— se cambia cuanto se quiera.
 *
 * ### Las reglas espejan los CHECK, no los sustituyen
 *
 * `ck_pb_color`, `ck_pb_correo`, `ck_pb_web` y `ck_pb_tipografia` son quien
 * manda. Estas reglas existen para que el operador reciba un mensaje bajo el
 * campo y no un `45000` traducido a media pantalla.
 */
final class GuardarMarcaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->user()?->getAuthIdentifier();

        return $id !== null && Permisos::tiene((int) $id, 'brand.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $extensiones = implode(',', Almacen::extensiones());
        $maxKb = (int) config('latam.archivos.max_kb', 8192);
        $hex = ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'legal_footer' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255', 'regex:#^https?://#'],
            'support_email' => ['nullable', 'email:filter', 'max:255'],
            'primary_color' => $hex,
            'secondary_color' => $hex,
            // L-1: la primera parada del degradado. NULL = degradado de dos
            // colores, que sigue siendo legitimo.
            'gradient_from' => $hex,
            'gradient_angle' => ['nullable', 'integer', 'min:0', 'max:359'],
            'sidebar_color' => $hex,
            // Lo mismo que `ck_pb_tipografia`: lo que no sean letras, numeros y
            // espacios no llega a la URL del servidor de fuentes ni a la hoja
            // de estilo. Un nombre con comillas es una inyeccion, no una errata.
            'font_family' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9 ]{2,80}$/'],
            'display_font_family' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9 ]{2,80}$/'],
            // La lista de extensiones sale de `Almacen`, en un solo sitio: si
            // se repitiera aqui, algun dia el formulario admitiria un tipo que
            // `Almacen` rechaza y el operador se enteraria despues de subir.
            'logo' => ['nullable', 'file', 'mimes:'.$extensiones, 'max:'.$maxKb],
            'favicon' => ['nullable', 'file', 'mimes:'.$extensiones, 'max:'.$maxKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'La marca tiene que llamarse de alguna manera.',
            'website.regex' => 'La web tiene que empezar por http:// o https://.',
            'support_email.email' => 'El correo de soporte no parece un correo.',
            'font_family.regex' => 'La tipografía sólo admite letras, números y espacios.',
            'primary_color.regex' => 'El color de marca debe ser hexadecimal (#RRGGBB).',
            'secondary_color.regex' => 'El color secundario debe ser hexadecimal (#RRGGBB).',
            'sidebar_color.regex' => 'El color de la barra debe ser hexadecimal (#RRGGBB).',
            'gradient_from.regex' => 'El primer color del degradado debe ser hexadecimal (#RRGGBB).',
            'gradient_angle.max' => 'El ángulo del degradado va entre 0 y 359 grados.',
            'display_font_family.regex' => 'La tipografía de titulares sólo admite letras, números y espacios.',
        ];
    }
}
