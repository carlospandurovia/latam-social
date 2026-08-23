<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use App\Shared\Auth\Permisos;
use App\Shared\Files\Almacen;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El revisor registra la aceptación de términos que el creador dio fuera del
 * sistema (DEC-059).
 *
 * `channel` NO admite `portal`: esta pantalla la usa un operador interno, y en
 * el portal la aceptación la da el propio creador con su sesión. Si se
 * permitiera aquí, un operador podría dejar constancia de una aceptación «del
 * portal» que el creador nunca hizo, y sin evidencia adjunta —porque
 * `ck_terms_acceptances_backing` solo exime al canal `portal`—.
 *
 * Es la misma regla, dicha dos veces: aquí para dar un mensaje claro, y en la
 * base para que sea cierta aunque alguien escriba por otro camino.
 */
final class RegistrarAceptacionRequest extends FormRequest
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
        $kb = (int) config('latam.archivos.max_kb', 8192);
        // La lista de tipos vive en `Almacen`, que es quien de verdad la impone.
        $tipos = implode(',', Almacen::extensiones());

        return [
            'channel' => ['required', Rule::in(['email', 'whatsapp', 'paper', 'phone'])],
            'evidencia' => ['required', 'file', 'max:'.$kb, 'mimes:'.$tipos],
            // No se puede haber aceptado mañana.
            'accepted_at' => ['required', 'date', 'before_or_equal:now'],
            'evidence_note' => ['nullable', 'string', 'max:255'],
            'confirma_revision' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'evidencia.required' => 'Adjunta el correo, la captura o el documento donde consta la aceptación.',
            'accepted_at.before_or_equal' => 'La fecha de aceptación no puede estar en el futuro.',
            'confirma_revision.accepted' => 'Confirma que la evidencia adjunta corresponde a este creador.',
        ];
    }
}
