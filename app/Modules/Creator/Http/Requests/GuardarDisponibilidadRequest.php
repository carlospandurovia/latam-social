<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Qué acepta un creador y con cuánta antelación.
 *
 * Mismo modelo de vigencia que la tarifa: una declaración nueva cierra la
 * anterior el día antes. No se edita la vigente, porque entonces se perdería
 * desde cuándo aceptaba viajar.
 *
 * `travel_scope` es obligatorio **si** acepta viajar. La base lo exige con dos
 * restricciones separadas y no con una: con `travel_scope` NULL, un
 * `IN (...)` vale NULL, y un `CHECK` deja pasar lo que evalúa a NULL — solo
 * rechaza lo que evalúa a FALSE. Está explicado en el esquema.
 */
final class GuardarDisponibilidadRequest extends FormRequest
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
            'accepts_travel' => ['nullable', 'boolean'],
            'travel_scope' => ['nullable', 'required_if:accepts_travel,1', Rule::in(['local', 'national', 'international'])],
            'accepts_in_person' => ['nullable', 'boolean'],
            'accepts_product_only' => ['nullable', 'boolean'],
            'max_campaigns_per_month' => ['nullable', 'integer', 'min:1', 'max:200'],
            'min_lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:255'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'travel_scope.required_if' => 'Si acepta viajar, hay que decir hasta dónde.',
        ];
    }
}
