<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Requests;

use App\Modules\Content\Services\Revisiones;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El formulario del veredicto (8.3).
 *
 * El mínimo de diez caracteres del comentario está aquí **y** en
 * `ck_cvw_comments` **y** en `Revisiones::vetoParaRevisar()`, y los tres hacen
 * falta: aquí sale el mensaje junto al campo, en el servicio sale junto a los
 * demás motivos, y en la base es lo que impide la fila venga de donde venga —de
 * un comando, de un import o de la pantalla de mañana—.
 */
final class RevisarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', 'in:'.Revisiones::APROBAR.','.Revisiones::CAMBIOS],
            'reviewer_side' => ['required', 'string', 'in:'.implode(',', array_keys(Revisiones::LADOS))],
            // `required_if` y no `required`: una aprobación no necesita texto, y
            // exigirlo llevaría a que alguien escriba «ok» para poder seguir.
            'comments' => ['nullable', 'string', 'max:5000', 'required_if:outcome,'.Revisiones::CAMBIOS, 'min:10'],
            'billing_decision' => ['nullable', 'string', 'in:'.implode(',', array_keys(Revisiones::FACTURACION))],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'outcome' => 'veredicto',
            'reviewer_side' => 'de parte de quien',
            'comments' => 'comentario',
            'billing_decision' => 'decision de facturacion',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'comments.required_if' => 'Para pedir cambios hay que decir cuales.',
            'comments.min' => 'Diga que tiene que cambiar: con menos de diez caracteres no se entiende.',
        ];
    }
}
