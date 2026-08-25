<?php

declare(strict_types=1);

namespace App\Modules\Client\Http\Requests;

use App\Modules\Client\Services\Contactos;
use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de un contacto del cliente (iteración 4.3).
 *
 * ### El correo NO se valida como único
 *
 * `contacts.contact_email` no tiene única en la base, y es a propósito: la
 * migración lo dice con todas las letras —«puede ser compartido
 * (`facturacion@cliente.com`)»— porque es un canal comercial, no una identidad
 * de acceso. Esa vive en `users.email`, que sí es única.
 *
 * Aquí no se añade una regla que la base no tenga. Una validación que solo
 * existe en el formulario es una regla que cualquier otro camino —una
 * importación, una orden de consola, un `INSERT` de mantenimiento— se salta sin
 * enterarse. Si algún día se decide que el correo repetido *dentro del mismo
 * cliente y tipo* es un error, el sitio donde ponerlo es el esquema (`Q-53`).
 * Mientras tanto, la ficha del cliente lo **avisa** y no lo impide.
 *
 * ### Y el cliente tampoco se elige
 *
 * `client_organization_id` sale de la ruta, nunca del formulario, y en la
 * edición no se toca (`DEC-077`). Un contacto que cambia de cliente reescribe
 * el histórico de con quién se habló en la empresa anterior.
 */
final class GuardarContactoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->user()?->getAuthIdentifier();

        return $id !== null && Permisos::tiene((int) $id, 'client.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:160'],
            // `max:255` es el ancho de la columna. Que `email` acepte lo que
            // acepta no lo decide esta capa: la columna es la que corta.
            'contact_email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:120'],
            // Las dos listas espejan `ck_contacts_type` y `ck_contacts_status`
            // literalmente. Si alguna cambia en el esquema y no aquí, el
            // formulario deja pasar un valor que la base rechaza con un 45000,
            // que es exactamente el error que el usuario no debe ver.
            'contact_type' => ['required', Rule::in(array_keys(Contactos::TIPOS))],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contact_email.email' => 'El correo de contacto no tiene forma de correo.',
            'contact_type.in' => 'Tipo de contacto no valido.',
            'status.in' => 'Estado de contacto no valido.',
        ];
    }
}
