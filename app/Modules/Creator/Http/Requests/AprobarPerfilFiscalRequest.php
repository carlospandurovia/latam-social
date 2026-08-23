<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aprobar un perfil tributario **decidiendo la retención** (`DEC-048`).
 *
 * `pending_review` no está entre los valores admitidos, y no es un descuido:
 * este formulario es el momento en que alguien tiene que responder `Q-40`. Si
 * la respuesta no se sabe todavía, el perfil se queda sin aprobar y el creador
 * sin activar — que es exactamente el bloqueo visible que `DEC-048` buscaba, en
 * lugar de un pago silencioso sin retención.
 */
final class AprobarPerfilFiscalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->user()?->getAuthIdentifier();

        return $id !== null && Permisos::tiene((int) $id, 'creator.tax.approve');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'withholding_status' => ['required', Rule::in(['not_applicable', 'applies'])],
            // `ck_ctp_rate_required`: si se retiene, hay tasa Y hay norma.
            'withholding_rate' => ['required_if:withholding_status,applies', 'nullable', 'numeric', 'gt:0', 'max:100'],
            // Mínimo 10 caracteres: «sí» no es una norma. La tasa sin la norma
            // que la sustenta es un número sin padre, y dentro de tres años
            // nadie sabrá si salió de la ley o de una suposición.
            'withholding_basis' => ['required_if:withholding_status,applies', 'nullable', 'string', 'min:10', 'max:160'],
            'confirma_revision' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'withholding_status.required' => 'Hay que decidir si se retiene. No aprobarlo es una opción; dejarlo sin decidir, no.',
            'withholding_rate.required_if' => 'Si se retiene, hay que decir con qué tasa.',
            'withholding_basis.required_if' => 'Cita la norma que sustenta la tasa (Q-40 sigue abierta: escribe también «por confirmar» si es el caso).',
            'confirma_revision.accepted' => 'Confirma que revisaste el documento fiscal del creador.',
        ];
    }
}
