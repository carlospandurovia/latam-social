<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una sociedad del grupo (iteración 4.5).
 *
 * `legal_entity.manage` y nada más: dar de alta una sociedad es constituir una
 * empresa dentro del sistema. De ella salen la numeración de comprobantes
 * (`BR-LE-007`), el emisor de cada factura (`BR-LE-005`) y las cuentas de cobro
 * (`BR-LE-006`).
 *
 * ### El `code` no se edita
 *
 * Es el identificador corto con el que la sociedad aparece en cada mensaje del
 * sistema —«CTS-PE factura a Perú desde…»— y `uq_le_code` lo hace único.
 * Cambiarlo reescribe lo que significaban los mensajes ya emitidos, así que se
 * pide al crear y después es de sólo lectura.
 */
final class GuardarEntidadLegalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->user()?->getAuthIdentifier();

        return $id !== null && Permisos::tiene((int) $id, 'legal_entity.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $uuid = $this->route('uuid');

        $reglas = [
            'legal_name' => ['required', 'string', 'min:2', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:160'],
            'tax_id_type' => ['required', 'string', 'max:20'],
            'address_line1' => ['required', 'string', 'max:180'],
            'address_line2' => ['nullable', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            // 9.17c: la FORMA viene del pais, no de aqui. Ver `reglaDeLocalidad()`.
            'tax_location_code' => $this->reglaDeLocalidad(),
            'establishment_code' => ['nullable', 'string', 'max:10', 'regex:/^[0-9A-Za-z]{1,10}$/'],
            'default_currency_code' => ['required', Rule::exists('currencies', 'code')],
            'timezone' => ['required', 'string', 'max:64'],
            'legal_representative' => ['nullable', 'string', 'max:160'],
            'incorporated_on' => ['nullable', 'date_format:Y-m-d'],
            // `uq_le_taxid` es `(country_id, tax_id_type, tax_id_number)`: la
            // misma empresa no puede estar dos veces. Se espeja aquí para que
            // salga un mensaje y no un `1062`.
            'tax_id_number' => [
                'required', 'string', 'max:40',
                Rule::unique('legal_entities', 'tax_id_number')
                    ->where(fn ($q) => $q
                        ->where('country_id', $this->paisDeLaSociedad())
                        ->where('tax_id_type', (string) $this->input('tax_id_type')))
                    ->ignore($uuid, 'uuid'),
            ],
        ];

        if ($uuid === null) {
            // Sólo al crear: el código identifica a la sociedad en cada mensaje
            // ya emitido, y el país es el de su constitución.
            $reglas['code'] = ['required', 'string', 'max:30', Rule::unique('legal_entities', 'code')];
            $reglas['country_id'] = ['required', 'integer', Rule::exists('countries', 'id')];
        }

        return $reglas;
    }

    /**
     * El país al que pertenece la sociedad.
     *
     * En el alta viene en el formulario. **En la edición no**: el país de
     * constitución no se toca, así que ni se pide ni llega en el `PUT`.
     *
     * Leerlo de la petición sin más daba `(int) null === 0`, la regla buscaba
     * `country_id = 0`, no encontraba nunca nada y **la unicidad quedaba
     * desactivada en toda edición**. Poner en la segunda sociedad peruana el
     * documento de la primera pasaba la validación y salía como
     * `1062 Duplicate entry ... uq_le_taxid`, crudo. El mensaje traducido que
     * hay debajo no se emitía jamás al editar.
     */
    private function paisDeLaSociedad(): int
    {
        $uuid = $this->route('uuid');

        if ($uuid === null) {
            return (int) $this->input('country_id');
        }

        return (int) DB::table('legal_entities')->where('uuid', $uuid)->value('country_id');
    }

    /**
     * La regla del código de localidad, **leída del país** (9.17c).
     *
     * En Perú es el ubigeo del INEI —seis dígitos—; en Colombia el código DANE
     * —cinco—; en México no hay equivalente. Escribir aquí `regex:/^[0-9]{6}$/`
     * sería la regla de un país aplicada a los seis, y rechazaría un código
     * colombiano correcto (`DEC-190`).
     *
     * Así que el patrón sale de `countries.tax_location_pattern`. Si el país no
     * declara ninguno, sólo queda la forma general: no se puede exigir una forma
     * que nadie ha declarado.
     *
     * Espeja `tg_le_localidad_ins`/`_upd`, que es quien manda. Existe para que
     * el operador reciba un mensaje bajo el campo y no un `45000`.
     *
     * @return list<string>
     */
    private function reglaDeLocalidad(): array
    {
        $reglas = ['nullable', 'string', 'max:12', 'regex:/^[0-9A-Za-z]{2,12}$/'];

        $patron = DB::table('countries')->where('id', $this->paisDeLaSociedad())
            ->value('tax_location_pattern');

        if ($patron !== null && $patron !== '') {
            // El patron viene de la BASE y se mete en un delimitador de PCRE.
            // `ck_countries_localidad` no comprueba su contenido --no puede: es
            // una expresion regular--, asi que se escapa el delimitador. Un
            // patron con una `/` dentro romperia la regla y, peor, podria
            // cambiar su significado.
            $reglas[] = 'regex:/'.str_replace('/', '\\/', (string) $patron).'/';
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya hay una sociedad con ese codigo.',
            'tax_id_number.unique' => 'Ya hay una sociedad con ese documento en ese pais.',
            'default_currency_code.exists' => 'Esa moneda no esta en el catalogo.',
            'tax_location_code.regex' => 'Ese codigo de localidad no tiene la forma que exige el pais de la sociedad.',
            'establishment_code.regex' => 'El codigo de establecimiento son letras y numeros, hasta 10.',
        ];
    }
}
