<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un requisito del brief: qué formato, cuántos, para cuándo y cuánto dura (7.2).
 *
 * ### Los cuatro números y por qué cada uno tiene tope
 *
 * | Campo | Qué es | Tope y por qué |
 * |---|---|---|
 * | `quantity` | cuántas piezas de ese formato | 1–999. Cero no es un requisito, es no pedirlo |
 * | `deadline_offset_days` | días desde que arranca la campaña para entregar | 0–365 |
 * | `permanence_days` | cuánto debe seguir publicado | 0–3650 |
 *
 * `quantity >= 1` lo impone además `ck_creq_quantity` en la base. Aquí se repite
 * a propósito: la base protege el dato y esta clase protege al operador de un
 * `4025` que nombra una restricción en vez de decirle que un cero no pide nada.
 *
 * Los otros dos **no** tienen `CHECK`, y el tope de aquí es lo único que hay.
 * Queda anotado como `T-33`: un `permanence_days` de 100.000 entra hoy por
 * cualquier `UPDATE` de mantenimiento, y con él un plazo que nadie puede
 * cumplir.
 */
final class GuardarRequisitoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content_format_id' => ['required', 'integer', 'exists:content_formats,id'],
            'quantity' => ['required', 'integer', 'between:1,999'],
            'deadline_offset_days' => ['required', 'integer', 'between:0,365'],
            'permanence_days' => ['required', 'integer', 'between:0,3650'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.between' => 'Al menos una pieza: pedir cero de un formato no es un requisito, '
                .'es no pedirlo. Si no lo quiere, quite la fila.',
            'content_format_id.exists' => 'Ese formato no existe en el catalogo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'content_format_id' => 'formato',
            'quantity' => 'cantidad',
            'deadline_offset_days' => 'plazo de entrega',
            'permanence_days' => 'permanencia',
            'notes' => 'notas',
        ];
    }
}
