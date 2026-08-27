<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Requests;

use App\Modules\Content\Services\Aprobaciones;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que el cliente contesta desde el enlace (8.5).
 *
 * El comentario es **obligatorio al pedir cambios** y opcional al aprobar, por
 * lo mismo que `ck_cvw_comments` en `8.3`: un «cambiadlo» sin texto le llega al
 * creador como «hazlo otra vez» y garantiza una vuelta más — que es justo lo que
 * las rondas cuentan.
 *
 * Diez caracteres, el mismo mínimo que la base, para que el aviso llegue con
 * palabras antes que con un `3819`.
 */
final class ResponderAprobacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización es el token, y la comprueba el servicio.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'respuesta' => ['required', 'string', 'in:'.Aprobaciones::APROBADA.','.Aprobaciones::CAMBIOS],
            'comentario' => [
                'nullable', 'string', 'max:2000',
                'required_if:respuesta,'.Aprobaciones::CAMBIOS,
                // El mínimo SÓLO al pedir cambios. Un «perfecto» de ocho letras
                // al aprobar es una respuesta perfectamente válida, y exigirle
                // diez caracteres a quien está diciendo que sí es una traba
                // inventada. La base opina lo mismo: `ck_apl_cambios` sólo mira
                // la corrección.
                Rule::when(
                    fn (): bool => $this->input('respuesta') === Aprobaciones::CAMBIOS,
                    ['min:10'],
                ),
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['respuesta' => 'respuesta', 'comentario' => 'comentario'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'comentario.required_if' => 'Digales que hay que cambiar: sin eso, la correccion llega como «hazlo otra vez».',
            'comentario.min' => 'Escriba un poco mas: con menos de diez caracteres no se entiende que hay que cambiar.',
        ];
    }
}
