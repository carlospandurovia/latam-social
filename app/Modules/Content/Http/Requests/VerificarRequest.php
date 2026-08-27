<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Requests;

use App\Modules\Content\Services\Evidencias;
use App\Shared\Files\Almacen;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El formulario de verificación (8.7).
 *
 * La captura es obligatoria **al verificar** y opcional al rechazar: dar algo
 * por bueno exige la prueba; decir que no había nada se apoya en ella pero no la
 * necesita —a veces lo que hay que archivar es un 404 y una frase—.
 */
final class VerificarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'veredicto' => ['required', 'string', 'in:'.Evidencias::VERIFICADA.','.Evidencias::RECHAZADA],
            'captura' => [
                'nullable', 'file', 'mimes:'.implode(',', Almacen::extensiones()), 'max:10240',
                'required_if:veredicto,'.Evidencias::VERIFICADA,
            ],
            'motivo' => [
                'nullable', 'string', 'in:'.implode(',', array_keys(Evidencias::MOTIVOS)),
                'required_if:veredicto,'.Evidencias::RECHAZADA,
            ],
            'nota' => ['nullable', 'string', 'max:200'],
            // El estado HTTP se anota si quien verifica lo tiene a mano. No
            // decide nada: sólo acompaña a la captura.
            'http_status' => ['nullable', 'integer', 'between:100,599'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'veredicto' => 'veredicto',
            'captura' => 'captura del post',
            'motivo' => 'motivo',
            'http_status' => 'estado HTTP',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'captura.required_if' => 'Para dar el post por verificado hay que subir su captura.',
            'motivo.required_if' => 'Diga por que se rechaza.',
        ];
    }
}
