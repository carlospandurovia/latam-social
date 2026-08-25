<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use App\Shared\Auth\Permisos;
use App\Shared\Crypto\CuentaBancaria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un medio de pago. Nace **sin verificar** y **sin fecha de
 * elegibilidad**.
 *
 * No hay formulario de edición y no es un olvido: la cuenta es inmutable
 * (`DEC-066`, `H-12`). Cambiar de cuenta es dar de alta otra y retirar la
 * anterior.
 *
 * El número de cuenta llega aquí en claro —no hay otra forma— y sale de aquí
 * cifrado: el controlador no lo guarda tal cual en ningún sitio, y lo que se
 * indexa es un HMAC, no el número. Ver `App\Shared\Crypto\CuentaBancaria`.
 */
final class GuardarMedioPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->user()?->getAuthIdentifier();

        return $id !== null && Permisos::tiene((int) $id, 'creator.payment.manage');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'method_type' => ['required', Rule::in(['bank_account', 'wallet', 'paypal', 'other'])],
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->where('is_active', 1)],
            'currency_code' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', 1)],
            'bank_name' => ['nullable', 'string', 'max:80'],
            'account_type' => ['nullable', Rule::in(['savings', 'checking', 'other'])],

            // Lo único que no se guarda tal como llega. Se admiten guiones y
            // espacios porque la gente los teclea; `CuentaBancaria::normalizar`
            // los quita antes de cifrar y de calcular la huella, para que
            // `0021-2345` y `00212345` sean la misma cuenta a efectos de
            // duplicados.
            // `min:6` y el patron miran el numero CRUDO, asi que `"- - - -"` los
            // pasaba y normalizaba a cadena VACIA: mascara `****`, y todas las
            // cuentas vacias con la misma huella, o sea `pending_review` entre
            // creadores que no tienen nada que ver. La regla de cierre pregunta
            // por lo normalizado, que es lo que de verdad se guarda.
            'account_number' => [
                'required', 'string', 'min:6', 'max:40', 'regex:/^[A-Za-z0-9 \-]+$/',
                function (string $atributo, mixed $valor, \Closure $falla): void {
                    if (!CuentaBancaria::tieneNumero((string) $valor)) {
                        $falla('El numero de cuenta no tiene ni una letra ni un digito.');
                    }
                },
            ],

            // BR-CREATOR-010: al menor se le paga a nombre del tutor. Que el
            // tutor sea de ESTE creador y siga activo lo comprueba el
            // controlador; aquí solo se exige que venga si hace falta.
            'owner_type' => ['required', Rule::in(['creator', 'guardian'])],
            'owner_guardian_id' => ['nullable', 'required_if:owner_type,guardian', 'integer'],

            'holder_name' => ['required', 'string', 'max:160'],
            'holder_document_type' => ['required', 'string', 'max:20'],
            'holder_document_number' => ['required', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_number.regex' => 'El número de cuenta solo admite letras, números, espacios y guiones.',
            'owner_guardian_id.required_if' => 'Si la cuenta es del tutor, hay que decir de qué tutor (BR-CREATOR-010).',
        ];
    }
}
