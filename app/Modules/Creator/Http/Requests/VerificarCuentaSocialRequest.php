<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Verificar la propiedad de una cuenta social (`H-05`).
 *
 * `oauth` no está en la lista **a propósito**: no está implementado. Ofrecerlo
 * en un desplegable dejaría que alguien marcara una cuenta como verificada por
 * la plataforma cuando la plataforma no ha dicho nada. Volverá cuando exista la
 * integración de verdad.
 */
final class VerificarCuentaSocialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->user()?->getAuthIdentifier();

        return $id !== null && Permisos::tiene((int) $id, 'creator.verify');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'verification_method' => ['required', Rule::in(['bio_code', 'dm_challenge', 'post_mention', 'manual_review'])],
            'nota' => ['nullable', 'string', 'max:255'],
            'confirma_comprobacion' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'verification_method.required' => 'Di cómo comprobaste que la cuenta es suya.',
            'confirma_comprobacion.accepted' => 'Confirma que hiciste la comprobación tú.',
        ];
    }
}
