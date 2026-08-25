<?php

declare(strict_types=1);

namespace App\Modules\Client\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de un cliente (iteración 4.1).
 */
final class GuardarClienteRequest extends FormRequest
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
        $uuid = $this->route('uuid');

        return [
            'commercial_name' => ['required', 'string', 'min:2', 'max:160'],
            // El código es la referencia con la que el cliente aparece en una
            // factura y en el ERP: mayúsculas, sin espacios, y único.
            'client_code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/',
                Rule::unique('client_organizations', 'client_code')
                    ->ignore($uuid, 'uuid'),
            ],
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->where('is_active', 1)],
            'website' => ['nullable', 'url', 'max:255'],
            'industry_category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'status' => ['required', Rule::in(['prospect', 'active', 'inactive', 'blacklisted'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_code.regex' => 'El codigo va en mayusculas, sin espacios ni acentos: aparece en la factura.',
            'country_id.exists' => 'Ese pais no esta activo en el catalogo.',
        ];
    }
}
