<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un periodo en el que el creador no está disponible.
 *
 * **No se rechaza aunque pise una campaña que ya aceptó** (`DEC-070`). Si se
 * opera o viaja, el bloqueo es un hecho y el sistema no lo va a cambiar
 * discutiendo. Lo que hace el controlador es decir qué campañas quedan dentro,
 * para que alguien hable con él hoy y no cuando falte el entregable.
 */
final class GuardarBloqueoRequest extends FormRequest
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
            'starts_on' => ['required', 'date'],
            // `ck_creator_blackouts_dates` lo exige igual; aqui se dice con
            // palabras en vez de con un error 3819.
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => 'Un bloqueo no puede terminar antes de empezar.',
        ];
    }
}
