<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
 * `deadline_offset_days` y `permanence_days` tienen desde 7.3 sus propios
 * `ck_creq_deadline` y `ck_creq_permanence` en la base (`T-33`, resuelta): hasta
 * entonces el tope de aquí era lo único que había, y un `permanence_days` de
 * 100.000 entraba por cualquier `UPDATE` de mantenimiento.
 *
 * ### El ámbito: general o de un mercado (7.3)
 *
 * `campaign_market_id` vacío significa **«todos los mercados»** — el `NULL` con
 * significado de `N-03`, la única excepción consciente del modelo. Y cuando
 * viene con valor, tiene que ser un mercado **de esta campaña**: la foránea
 * compuesta lo impide en el esquema desde 7.3, pero un `1452` habla de una
 * restricción y aquí se puede decir con palabras.
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
            'campaign_market_id' => ['nullable', 'integer', Rule::exists('campaign_markets', 'id')
                ->where('campaign_id', $this->route('uuid') === null ? 0 : $this->campanaId())],
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
            'campaign_market_id.exists' => 'Ese mercado no es de esta campana. Un requisito solo puede '
                .'apuntar a un pais que la campana declare, y desde 7.3 el esquema tampoco lo permite.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'content_format_id' => 'formato',
            'campaign_market_id' => 'mercado',
            'quantity' => 'cantidad',
            'deadline_offset_days' => 'plazo de entrega',
            'permanence_days' => 'permanencia',
            'notes' => 'notas',
        ];
    }

    /**
     * Una casilla de selección vacía llega como `''`, no como `null`.
     *
     * Y `''` no es un entero ni es nulo: `nullable` no lo salva y el mensaje que
     * sale habla de un formato de número sobre un desplegable donde el operador
     * eligió «todos los mercados». Se normaliza antes de validar.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('campaign_market_id') === '') {
            $this->merge(['campaign_market_id' => null]);
        }
    }

    /** El id de la campaña de la ruta. La ruta lleva el uuid; la foránea, el id. */
    private function campanaId(): int
    {
        return (int) DB::table('campaigns')
            ->where('uuid', $this->route('uuid'))->value('id');
    }
}
