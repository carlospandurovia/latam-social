<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedir un enlace de recuperación (`4.1`).
 *
 * ### La validación es deliberadamente floja
 *
 * Sólo se comprueba que parezca un correo. **No** se comprueba que exista
 * (`exists:users,email`), y eso no es un olvido: esa regla convertiría la
 * pantalla en un buscador de correos dados de alta. Alguien con una lista de
 * direcciones sabría en un rato cuáles son clientes nuestros — y eso es
 * información que no nos toca a nosotros regalar.
 *
 * Que el correo no exista se descubre después y no se cuenta: la respuesta es
 * la misma en los dos casos (decisión de negocio, 2026-08-26).
 */
final class PedirEnlaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Eso no parece un correo.',
        ];
    }
}
