<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Requests;

use App\Modules\Content\Services\Permanencia;
use App\Shared\Files\Almacen;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El formulario de la bandeja de permanencia (8.8).
 *
 * Tres acciones por el mismo POST, como los tres veredictos de `8.3`:
 *
 * | Acción | Qué es | Qué exige |
 * |---|---|---|
 * | `anotar` | *«miré y esto es lo que vi»* | decir si estaba o no; si no estaba, una nota o un estado HTTP |
 * | `caida` | la firma que para el pago | motivo de la lista **y** captura |
 * | `reponer` | era un falso positivo, o ya está repuesto | captura |
 *
 * La captura es obligatoria en las dos que **cambian el estado** y opcional en la
 * que sólo observa. Es el mismo criterio de `8.7`: lo que mueve dinero lleva
 * prueba archivada; mirar, no.
 */
final class ComprobarPermanenciaRequest extends FormRequest
{
    public const ANOTAR = 'anotar';

    public const CAIDA = 'caida';

    public const REPONER = 'reponer';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $acciones = implode(',', [self::ANOTAR, self::CAIDA, self::REPONER]);

        return [
            'accion' => ['required', 'string', 'in:'.$acciones],
            // Sólo la lee `anotar`. `in:0,1` y no `boolean` porque llega de un
            // select y hay que distinguir «no vino» de «vino un 0».
            'viva' => ['nullable', 'in:0,1', 'required_if:accion,'.self::ANOTAR],
            'captura' => [
                'nullable', 'file', 'mimes:'.implode(',', Almacen::extensiones()), 'max:10240',
                'required_if:accion,'.self::CAIDA,
                'required_if:accion,'.self::REPONER,
            ],
            'motivo' => [
                'nullable', 'string', 'in:'.implode(',', array_keys(Permanencia::MOTIVOS)),
                'required_if:accion,'.self::CAIDA,
            ],
            'nota' => ['nullable', 'string', 'max:200'],
            // Se anota si quien mira lo tiene a mano. No decide nada.
            'http_status' => ['nullable', 'integer', 'between:100,599'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'accion' => 'accion',
            'viva' => 'que vio',
            'captura' => 'captura de pantalla',
            'motivo' => 'motivo',
            'http_status' => 'estado HTTP',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'viva.required_if' => 'Diga si el post seguia ahi o no.',
            'captura.required_if' => 'Suba la captura de lo que ve ahora.',
            'motivo.required_if' => 'Diga por que se da el post por caido.',
        ];
    }
}
