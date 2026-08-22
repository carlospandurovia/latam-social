<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Qué se puede cambiar de un creador desde esta pantalla, y qué no.
 *
 * **Lo que NO está aquí es la parte importante.** No se editan:
 *
 * - `first_name`, `last_name`, `birth_date`, `document_type`, `document_number`,
 *   `document_country_code`: son la **identidad**. Cambiarlas no es corregir un
 *   dato, es decir que se trata de otra persona — o corregir un error que
 *   necesita evidencia y aprobación, como ya exige `BR-CREATOR-007` para lo
 *   fiscal. Además `uq_creators_identity` las usa como clave.
 * - `email`: es contacto y clave de unicidad (`uq_creators_email`). Cambiarlo
 *   exige verificar el nuevo, no teclearlo.
 * - `status`: las transiciones de estado tienen su propia tabla
 *   (`status_transitions`) y su propio flujo de aprobación. Un `<select>` con
 *   «blacklisted» dentro de un formulario de contacto es una mala idea.
 *
 * Queda lo que un operador corrige a diario sin que nadie tenga que aprobarlo:
 * contacto y preferencias comerciales.
 */
final class ActualizarCreadorRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La ruta ya lleva `permiso:creator.manage`. Se vuelve a comprobar aquí
        // a propósito: si alguien registra esta acción en otra ruta y olvida el
        // middleware, la petición sigue sin pasar.
        return $this->user()?->can('creator.manage') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'city' => ['nullable', 'string', 'max:100'],
            // Refleja `ck_creators_payment_term`. La base es la autoridad; esto
            // es para dar un mensaje decente antes de llegar a ella.
            'payment_term_days' => ['required', 'integer', 'between:0,180'],
            'preferred_currency_code' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'locale' => ['required', 'string', 'max:10', Rule::exists('languages', 'code')],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'display_name' => 'nombre público',
            'phone' => 'teléfono',
            'city' => 'ciudad',
            'payment_term_days' => 'plazo de pago',
            'preferred_currency_code' => 'moneda preferida',
            'locale' => 'idioma',
            'timezone' => 'zona horaria',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_term_days.between' => 'El plazo de pago va de 0 a 180 días.',
            'timezone.timezone' => 'Esa zona horaria no existe.',
        ];
    }
}
