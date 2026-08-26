<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un mercado de la campaña: en qué país se ejecuta y con cuántos creadores (7.3).
 *
 * ### `target_creators` puede no estar, pero no puede ser cero
 *
 * Vacío significa **«sin cupo fijado»**, que es legítimo: muchas campañas
 * empiezan sin número y lo cierran al ver quién acepta. Un cero no significa
 * nada — *«esta campaña corre en Colombia con cero creadores»* no es un
 * objetivo, es un mercado que no debería estar en la lista.
 *
 * Es distinto del cero de `revenue_amount` (7.2), y por eso se resuelve
 * distinto: allí la columna era `NOT NULL DEFAULT 0` y hubo que **añadir**
 * `is_gratis` para poder declarar el cero; aquí `NULL` ya dice «no fijado», así
 * que lo único que hacía falta era prohibir el cero. `ck_cm_target` lo impone en
 * la base.
 *
 * ### El país no tiene que estar cubierto
 *
 * Decisión de negocio (2026-08-25): la cobertura de facturación resuelve por el
 * país del **cliente** (`BR-LE-003`), no por el del mercado. Un cliente peruano
 * puede pagar una campaña que corre en Colombia sin que el grupo tenga sociedad
 * allí. Cómo se le paga a un creador colombiano es otra pregunta, y está abierta
 * como `Q-40`.
 */
final class GuardarMercadoRequest extends FormRequest
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
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            // 999 y no `unsigned` a secas: `target_creators` es un SMALLINT y
            // una campana con 40.000 creadores no es un objetivo ambicioso, es
            // un dedazo. El tope de la pantalla lo dice antes que el motor.
            'target_creators' => ['nullable', 'integer', 'between:1,999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_creators.between' => 'Un mercado con cero creadores no es un objetivo: dejelo en '
                .'blanco si todavia no hay numero, o quite el mercado si la campana no corre ahi.',
            'country_id.exists' => 'Ese pais no esta en el catalogo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'country_id' => 'pais',
            'target_creators' => 'creadores objetivo',
        ];
    }

    /** Un campo numérico vacío llega como `''`, que no es ni entero ni nulo. */
    protected function prepareForValidation(): void
    {
        if ($this->input('target_creators') === '') {
            $this->merge(['target_creators' => null]);
        }
    }
}
