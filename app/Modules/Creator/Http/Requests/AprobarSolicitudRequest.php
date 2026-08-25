<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Los datos que el revisor completa al aprobar una solicitud.
 *
 * La solicitud trae poco: nombre completo, correo, teléfono y país. La
 * **identidad** —nombre legal separado, fecha de nacimiento y documento— la
 * teclea el revisor a partir de lo que el creador envió.
 *
 * Que sea data entry manual no es pereza: es el punto donde una persona se hace
 * responsable de que el documento coincide con la persona. Por eso queda en la
 * bitácora con su nombre, y por eso `DEC-055` no deja editarla después desde una
 * pantalla de contacto: se captura una vez, con alguien detrás.
 */
final class AprobarSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('creator.approve') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'display_name' => ['required', 'string', 'max:120'],
            // `ck_creators_birth_date` exige > 1920-01-01; y una fecha futura
            // no es una fecha de nacimiento.
            'birth_date' => ['required', 'date_format:Y-m-d', 'after:1920-01-01', 'before:today'],
            'document_country_code' => ['required', 'string', 'size:2', Rule::exists('countries', 'iso2')],
            'document_type' => ['required', Rule::in([
                'DNI', 'CE', 'RUC', 'PASSPORT', 'CC', 'NIT', 'CURP', 'RFC', 'RUT', 'SSN', 'NIE', 'NIF', 'OTHER',
            ])],
            'document_number' => ['required', 'string', 'max:40'],
            'preferred_currency_code' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'payment_term_days' => ['required', 'integer', 'between:0,180'],
            // El revisor confirma que ya miró el aviso de duplicados. Es una
            // casilla, no una comprobación: la comprobación la hace el servidor.
            'confirma_revision' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => 'nombres',
            'last_name' => 'apellidos',
            'display_name' => 'nombre público',
            'birth_date' => 'fecha de nacimiento',
            'document_country_code' => 'país del documento',
            'document_type' => 'tipo de documento',
            'document_number' => 'número de documento',
            'preferred_currency_code' => 'moneda preferida',
            'payment_term_days' => 'plazo de pago',
            'confirma_revision' => 'confirmación de revisión',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirma_revision.accepted' => 'Confirma que revisaste la identidad y los posibles duplicados.',
            'birth_date.before' => 'La fecha de nacimiento no puede ser hoy ni futura.',
        ];
    }
}
