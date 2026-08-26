<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Http\Requests;

use App\Modules\Campaign\Services\Campanas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta y edición de campaña (7.1).
 *
 * ### La marca tiene que ser DEL cliente
 *
 * `campaigns` apunta a `client_organization_id` y a `client_brand_id` por
 * separado, y **nada en el esquema obliga a que la marca pertenezca a ese
 * cliente**. Una foránea sólo comprueba que la marca exista.
 *
 * Eso no es un detalle: la campaña de Alicorp acabaría hecha para una marca de
 * la competencia, y la detección de conflictos de marca (`BR-CAMPAIGN-007`)
 * miraría las categorías equivocadas. Se comprueba aquí porque el par
 * (cliente, marca) llega del formulario y es donde se puede decir con palabras.
 *
 * ### `code` no se pide
 *
 * Lo deriva `Campanas::codigoLibre()` del nombre del cliente y el año. Es único
 * global y de 20 caracteres: pedirlo sería pedirle a un operador que resuelva
 * una colisión que no puede ver.
 *
 * ### «Gratis» e «ingreso cero» no son lo mismo (7.2)
 *
 * `revenue_amount = 0` responde a dos preguntas distintas con el mismo número:
 * *«esta campaña es un canje / una cortesía»* y *«todavía nadie le ha puesto
 * precio»*. De ahí sale el margen, así que confundirlas se paga después.
 *
 * `is_gratis` es la casilla que las separa, y aquí se comprueba **la
 * coherencia**: marcado obliga a cero. `ck_camp_revenue_declarado` lo repite en
 * la base, pero sólo a partir de `approved` —un borrador recién creado tiene
 * los dos campos a cero y ninguna de las dos respuestas dada todavía—, así que
 * si esto no lo dijera aquí, un «gratis con 5.000 soles» viviría tan tranquilo
 * en borrador y reventaría con un 45000 el día de la aprobación, lejos de la
 * pantalla donde se tecleó.
 */
final class GuardarCampanaRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:180'],
            'client_organization_id' => ['required', 'integer', 'exists:client_organizations,id'],
            'client_brand_id' => ['required', 'integer', Rule::exists('client_brands', 'id')
                ->where('client_organization_id', $this->input('client_organization_id'))],
            'objective' => ['required', Rule::in(array_keys(Campanas::OBJETIVOS))],
            'currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'revenue_amount' => ['required', 'numeric', 'min:0'],
            'is_gratis' => ['required', 'boolean'],
            // El techo de `BR-CAMPAIGN-005`. Sin `max`, porque un presupuesto
            // grande no es un error: lo que la regla vigila es que el COSTO
            // COMPROMETIDO no lo supere, no cuanto vale el techo.
            'creator_budget_amount' => ['required', 'numeric', 'min:0'],
            'included_revision_rounds' => ['required', 'integer', 'between:0,10'],
            'min_creator_age' => ['required', 'integer', 'between:0,99'],
            // `date_format` y no `date`: `'2026-2-1'` es una fecha valida para
            // `date` y una cadena que se compara mal para todo lo demas. Es la
            // leccion de 4.5, y aqui de esta fecha depende QUE SOCIEDAD factura.
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'publication_deadline' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'briefing' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_brand_id.exists' => 'Esa marca no es de ese cliente. Una campana se hace para una marca '
                .'del cliente que la paga: si no, el conflicto de marca se calcularia contra las '
                .'categorias equivocadas.',
            'ends_on.after_or_equal' => 'La campana no puede terminar antes de empezar.',
            'starts_on.date_format' => 'La fecha de inicio va con ceros: 2026-02-01, no 2026-2-1. '
                .'De esta fecha depende que sociedad factura la campana.',
            'ends_on.date_format' => 'La fecha de fin va con ceros: 2026-02-01, no 2026-2-1.',
            'publication_deadline.after_or_equal' => 'La fecha limite de publicacion no puede ser anterior al inicio.',
            'revenue_amount.gratis' => 'Una campana marcada como gratuita va con ingreso 0. Si el cliente '
                .'paga algo --aunque sea poco-- no es gratuita: quite la marca y ponga el importe.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'client_organization_id' => 'cliente',
            'client_brand_id' => 'marca',
            'objective' => 'objetivo',
            'currency_code' => 'moneda',
            'revenue_amount' => 'ingreso',
            'is_gratis' => 'campana gratuita',
            'creator_budget_amount' => 'presupuesto de creadores',
            'included_revision_rounds' => 'rondas incluidas',
            'min_creator_age' => 'edad minima',
            'starts_on' => 'fecha de inicio',
            'ends_on' => 'fecha de fin',
        ];
    }

    /**
     * Una casilla sin marcar no llega en el `POST`.
     *
     * Sin esto `is_gratis` sería `null` para *«no es gratis»* y el `boolean`
     * fallaría con «el campo es obligatorio» sobre una casilla que el operador
     * sí contestó —dejándola vacía—. Se normaliza a `0`/`1` y no a `false`/`true`
     * porque `update()` compara los valores contra la fila **como cadenas** para
     * anotar el cambio en la bitácora, y `(string) false` es `''`, que no se
     * parece a `'0'`: anotaría un cambio inventado en cada guardado.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['is_gratis' => $this->boolean('is_gratis') ? 1 : 0]);
    }

    /**
     * Gratuita e importe no pueden contradecirse.
     *
     * Va en `withValidator` y no en una regla del campo porque el error tiene
     * que salir **sobre el importe**, que es lo que hay que corregir, y porque
     * depende de dos campos a la vez.
     */
    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $v): void {
            if ((int) $this->input('is_gratis') === 1 && (float) $this->input('revenue_amount') > 0) {
                $v->errors()->add('revenue_amount', (string) $this->messages()['revenue_amount.gratis']);
            }
        });
    }

    /** El nombre comercial del cliente, para derivar el código. */
    public function nombreDelCliente(): string
    {
        return (string) DB::table('client_organizations')
            ->where('id', $this->input('client_organization_id'))
            ->value('commercial_name');
    }
}
