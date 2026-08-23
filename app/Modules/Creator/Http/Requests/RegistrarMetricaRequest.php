<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Capturar un snapshot de métricas (`BR-CREATOR-005`).
 *
 * No se pregunta por el estado de coherencia: lo calcula `CoherenciaMetrica`.
 * Si lo eligiera quien teclea, marcaría «limpio» siempre y volveríamos a tener
 * el cero que mentía (`H-06`).
 */
final class RegistrarMetricaRequest extends FormRequest
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
            'source' => ['required', Rule::in(['self_declared', 'api', 'manual_review', 'import'])],
            'captured_at' => ['required', 'date', 'before_or_equal:now'],
            'followers' => ['nullable', 'integer', 'min:0'],
            'following' => ['nullable', 'integer', 'min:0'],
            'posts_count' => ['nullable', 'integer', 'min:0'],
            'avg_views' => ['nullable', 'integer', 'min:0'],
            'avg_likes' => ['nullable', 'integer', 'min:0'],
            'avg_comments' => ['nullable', 'integer', 'min:0'],
            // `ck_sas_engagement` ya lo impone en la base; aquí es para dar un
            // mensaje en vez de un error 45000.
            'engagement_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'captured_at.before_or_equal' => 'No se puede capturar una métrica del futuro.',
            'engagement_rate.max' => 'El engagement se expresa en porcentaje: no puede pasar de 100.',
        ];
    }
}
