<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Capturar un perfil tributario. **No aprobarlo**: nace en `pending`.
 *
 * Aquí no se pregunta por la retención a propósito. `DEC-048` la deja en
 * `pending_review` y `ck_ctp_withholding_decided` impide aprobar sin decidirla;
 * quien conoce la norma es quien aprueba, no quien teclea el RUC. Si este
 * formulario ofreciera el campo, la decisión la tomaría la persona equivocada
 * y el control se convertiría en un trámite.
 */
final class GuardarPerfilFiscalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->user()?->getAuthIdentifier();

        return $id !== null && Permisos::tiene((int) $id, 'creator.tax.manage');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->where('is_active', 1)],
            'tax_regime_code' => ['required', 'string', 'max:30'],
            'tax_id_type' => ['required', 'string', 'max:20'],
            // BR-CREATOR-013: no existe el pago informal. Un perfil sin número
            // fiscal no se puede aprobar, así que tampoco tiene sentido
            // capturarlo a medias y descubrirlo al final.
            'tax_id_number' => ['required', 'string', 'max:40'],
            'issued_document_type' => ['required', Rule::in(['recibo_honorarios', 'factura', 'invoice', 'none'])],
            'holder_type' => ['required', Rule::in(['creator', 'guardian'])],
            'holder_guardian_id' => ['nullable', 'integer', 'required_if:holder_type,guardian'],
            'valid_from' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tax_id_number.required' => 'Sin número fiscal no hay perfil: BR-CREATOR-013 no admite el pago informal.',
            'holder_guardian_id.required_if' => 'Si el titular es el tutor, hay que decir cuál.',
        ];
    }
}
