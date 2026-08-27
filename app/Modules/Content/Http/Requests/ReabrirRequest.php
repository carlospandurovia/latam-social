<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Requests;

use App\Modules\Content\Services\Revisiones;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El formulario de reapertura (8.2).
 *
 * El motivo es de **lista cerrada** —como los de rechazo de `7.6`— porque sirve
 * para contestar *«¿por qué se reabren las piezas?»* con un número: si el 70 % es
 * «se aprobó por error», el problema es la pantalla de revisión y no el cliente.
 *
 * La nota libre es opcional salvo cuando el motivo es «otro». «Otro» sin explicar
 * es la casilla que se marca para poder seguir, y entonces la lista deja de
 * contestar nada.
 */
final class ReabrirRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'in:'.implode(',', array_keys(Revisiones::MOTIVOS_REAPERTURA))],
            'nota' => ['nullable', 'string', 'max:500', 'required_if:motivo,other', 'min:10'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['motivo' => 'motivo', 'nota' => 'explicacion'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nota.required_if' => 'Si el motivo es «otro», hay que explicarlo.',
            'nota.min' => 'Explique el motivo: con menos de diez caracteres no se entiende.',
        ];
    }
}
