<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A qué dirección se le manda la pieza al cliente (8.5).
 *
 * El correo se escribe a mano y **no se elige de una lista de contactos** a
 * propósito. `contacts` existe desde `4.3`, pero quien aprueba una pieza no es
 * necesariamente el contacto de facturación ni el comercial: es quien la marca
 * diga esta semana. Atarlo al catálogo obligaría a dar de alta un contacto para
 * mandar un enlace, y eso convierte un clic en un trámite.
 *
 * Lo que sí queda es **a qué dirección salió este enlace**, en la propia fila
 * (`approval_links.sent_to`), que es la pregunta que alguien va a hacer después.
 */
final class PedirAprobacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'correo' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['correo' => 'correo del cliente'];
    }
}
