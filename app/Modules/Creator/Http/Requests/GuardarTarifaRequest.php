<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fijar la tarifa de un creador para un formato y una moneda.
 *
 * No hay «editar»: una tarifa nueva cierra la anterior el día antes y abre un
 * periodo nuevo. Así el histórico explica por qué se pagó lo que se pagó, que
 * es lo único para lo que sirve (`H-16`).
 *
 * `source` no tiene valor por omisión ni aquí ni en la base (`H-17`): quien
 * pone el precio dice de dónde sale. Y cero es un precio válido solo si se
 * declara gratuito (`DEC-068`).
 */
final class GuardarTarifaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->user()?->getAuthIdentifier();

        return $id !== null && Permisos::tiene((int) $id, 'creator.rate.manage');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'content_format_id' => ['required', 'integer', Rule::exists('content_formats', 'id')->where('is_active', 1)],
            'currency_code' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', 1)],
            'is_gratis' => ['nullable', 'boolean'],
            // `exclude_if` y no `required`: en una colaboración gratuita el
            // importe no se pide, y pedirlo obligaría a teclear un cero que la
            // base rechaza si no está declarada como gratuita.
            'amount' => ['exclude_if:is_gratis,1', 'required', 'numeric', 'gt:0', 'max:99999999'],
            'source' => ['required', Rule::in(['self_declared', 'negotiated', 'estimated'])],
            'valid_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.gt' => 'Una tarifa es mayor que cero. Si el trabajo es gratuito, márcalo como tal.',
            'source.required' => 'Di de dónde sale el precio: lo declaró el creador, se negoció, o lo estimamos nosotros.',
        ];
    }
}
