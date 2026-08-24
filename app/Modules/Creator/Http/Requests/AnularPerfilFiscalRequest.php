<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Anular el perfil fiscal vigente (iteración 3.11, `T-15`).
 *
 * Permiso propio, no `creator.tax.approve`: quien aprueba a diario no debe poder
 * borrar del histórico por descuido. Y el motivo no es opcional — anular
 * reescribe el histórico del que sale la retención practicada, y un histórico
 * que se puede cambiar sin dejar constancia de por qué no es un histórico.
 */
final class AnularPerfilFiscalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->user()?->getAuthIdentifier();

        return $id !== null && Permisos::tiene((int) $id, 'creator.tax.annul');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // El mínimo de 10 es el mismo que el de `rejection_note`: obliga a
            // escribir una frase, no una palabra. «error» no le explica nada a
            // quien lea el expediente dentro de dos años.
            'annulment_reason' => ['required', 'string', 'min:10', 'max:255'],
            'confirma' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'annulment_reason.required' => 'Diga por qué se anula: queda en el expediente y es lo único que explica el hueco.',
            'annulment_reason.min' => 'El motivo tiene que ser una frase, no una palabra.',
            'confirma.accepted' => 'Confirme que entiende que el creador se queda sin perfil fiscal vigente.',
        ];
    }
}
