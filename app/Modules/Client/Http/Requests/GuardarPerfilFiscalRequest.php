<?php

declare(strict_types=1);

namespace App\Modules\Client\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y corrección de la identidad fiscal de un cliente (iteración 4.4).
 *
 * ### Por qué `client.tax.manage` y no `client.manage`
 *
 * De estos campos salen la razón social y el documento que se **imprimen en una
 * factura**. Un permiso propio permite que alguien edite la ficha comercial del
 * cliente sin poder tocar eso.
 *
 * No sigue la simetría de `creator.tax.manage`, que vive sólo en `finance`: el
 * documento de un creador es dato **personal** sensible, y el de una empresa es
 * **público** —en Perú cualquiera consulta un RUC en SUNAT—. Aquí el riesgo no
 * es fuga, es error, así que el permiso lo tienen también las campañas, que son
 * quienes hablan con el cliente y tienen el dato.
 *
 * ### Qué NO se valida aquí
 *
 * El formato del documento por país (11 dígitos para un RUC peruano, 9 para un
 * NIT colombiano, dígito verificador…) **no se comprueba**. Sería fácil escribir
 * una tabla de expresiones regulares y sería una trampa: en cuanto una esté mal
 * o falte un país, el sistema rechaza un documento válido y no hay forma de
 * meterlo. Se valida lo que la base impone y punto (`Q-55`).
 */
final class GuardarPerfilFiscalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->user()?->getAuthIdentifier();

        return $id !== null && Permisos::tiene((int) $id, 'client.tax.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // En la corrección del vigente no se pide país ni fecha de inicio: son
        // la identidad de la serie. Cambiar el país movería la fila a otra
        // serie de periodos, y mover la fecha reescribiría desde cuándo se
        // factura así. Las dos cosas se hacen abriendo un periodo, no editando.
        $esCorreccion = $this->route('perfil') !== null;

        $reglas = [
            'legal_name' => ['required', 'string', 'min:2', 'max:200'],
            'tax_id_type' => ['required', 'string', 'max:20'],
            'tax_id_number' => ['required', 'string', 'max:40'],
            'address_line1' => ['required', 'string', 'max:180'],
            'address_line2' => ['nullable', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            // `ck_ctxp_term` exige 0..180 en la base. Se espeja aquí para que
            // el operador vea un mensaje y no un 45000.
            'payment_term_days' => ['required', 'integer', 'between:0,180'],
        ];

        if (!$esCorreccion) {
            $reglas['country_id'] = ['required', 'integer', Rule::exists('countries', 'id')];
            // `date_format` y no `date`: `date` acepta `2026-2-1`, y una fecha sin
            // ceros se compara mal contra una columna DATE. La normalizacion de
            // `Vigencia` es el cinturon; esto son los tirantes.
            $reglas['valid_from'] = ['required', 'date_format:Y-m-d'];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_term_days.between' => 'El plazo de pago debe estar entre 0 y 180 dias.',
            'billing_email.email' => 'El correo de facturacion no tiene forma de correo.',
            'valid_from.required' => 'Hace falta desde cuando rige esta identidad fiscal.',
        ];
    }
}
