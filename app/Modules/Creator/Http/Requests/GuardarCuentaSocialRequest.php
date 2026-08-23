<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dar de alta una cuenta social. Nace **sin verificar**.
 *
 * Que el creador diga que una cuenta es suya no la hace suya: eso es lo que
 * verifica `BR-CREATOR-003`, y por eso el alta y la verificación son dos actos
 * con dos permisos distintos.
 */
final class GuardarCuentaSocialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->user()?->getAuthIdentifier();

        return $id !== null && Permisos::tiene((int) $id, 'creator.manage');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'platform_id' => ['required', 'integer', Rule::exists('platforms', 'id')->where('is_active', 1)],
            // Sin arroba: se guarda el identificador, no cómo se escribe.
            'handle' => ['required', 'string', 'max:120', 'not_regex:/^@/'],
            'profile_url' => ['required', 'url', 'max:500'],
            'external_id' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'handle.not_regex' => 'Escribe el identificador sin la arroba.',
            'profile_url.url' => 'El enlace al perfil tiene que ser una URL completa, con https://.',
        ];
    }
}
