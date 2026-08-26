<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Poner una contraseña desde un enlace (`5.9`, `4.1`).
 *
 * ### El mismo listón que `CambiarPasswordRequest`, y por qué se repite
 *
 * 12 caracteres, letras, números y símbolos, más la comprobación de filtraciones
 * cuando está encendida. Es exactamente la misma exigencia, y **no se hereda de
 * `CambiarPasswordRequest`**: aquella pide además la contraseña actual y exige
 * que la nueva sea distinta, y las dos reglas son imposibles aquí — quien llega
 * por un enlace es justamente quien no sabe su contraseña, y una cuenta recién
 * creada no tiene ninguna que su dueño conozca.
 *
 * Heredar y desactivar dos reglas dejaría una clase que dice una cosa y hace
 * otra. Lo que sí se comparte —el listón— vive en `fuerza()` y se llama desde
 * las dos.
 *
 * ### Sin `authorize()` de verdad
 *
 * Aquí no hay usuario conectado: la autorización **es el token**, y la comprueba
 * el controlador contra la base antes de tocar nada. Una `FormRequest` no puede
 * hacerlo porque el token vive en la sesión y validarlo dos veces —una aquí y
 * otra allí— sería dos sitios donde equivocarse.
 */
final class FijarPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', self::fuerza()],
        ];
    }

    /**
     * El listón de contraseña del proyecto, en un solo sitio.
     */
    public static function fuerza(): Password
    {
        $fuerza = Password::min(12)->letters()->numbers()->symbols();

        // Falla en ABIERTO: sin salida a internet, Laravel da la contrasena por
        // buena. Por eso no es la defensa —la defensa son los 12 caracteres y la
        // mezcla— y por eso se puede apagar. Ver `CambiarPasswordRequest`.
        if ((bool) config('latam.seguridad.comprobar_filtraciones', true)) {
            $fuerza = $fuerza->uncompromised();
        }

        return $fuerza;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => 'Escribe la contrasena que quieres usar.',
            'password.confirmed' => 'Las dos contrasenas no coinciden.',
            'password.uncompromised' => 'Esa contrasena aparece en filtraciones publicas conocidas. Elige otra.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['password' => 'contrasena'];
    }
}
