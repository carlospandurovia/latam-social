<?php

declare(strict_types=1);

namespace App\Modules\Client\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una marca (iteración 4.2).
 *
 * El **slug no se pide**: lo deriva `Marcas::slugUnico()` del nombre. Es único
 * globalmente y quien da de alta una marca no tiene por qué saber qué slugs hay
 * cogidos en otros clientes.
 */
final class GuardarMarcaRequest extends FormRequest
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
        // El nombre es único DENTRO del cliente (`uq_cb_name`), no globalmente:
        // dos clientes distintos sí pueden tener una marca «Natura».
        $clienteId = (int) DB::table('client_organizations')
            ->where('uuid', $this->route('uuid'))->value('id');

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:120',
                Rule::unique('client_brands', 'name')
                    ->where('client_organization_id', $clienteId)
                    ->ignore($this->route('marca'), 'uuid'),
            ],
            'website' => ['nullable', 'url', 'max:255'],
            'status' => ['required', Rule::in(['active', 'paused', 'archived'])],
            'categorias' => ['array'],
            'categorias.*' => ['integer', Rule::exists('categories', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Este cliente ya tiene una marca con ese nombre.',
        ];
    }
}
