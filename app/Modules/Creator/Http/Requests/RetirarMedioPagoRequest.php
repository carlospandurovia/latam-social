<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use App\Shared\Auth\Permisos;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Retirar un medio de pago, o dar por buena una cuenta compartida.
 *
 * Las dos son decisiones de una persona sobre a dónde va el dinero, y las dos
 * exigen motivo. Un medio de pago no se borra (`H-13`, `BR-FIN-008`): retirarlo
 * es la única forma de sacarlo de circulación, así que el motivo es lo único
 * que queda para explicar por qué dejó de valer.
 *
 * El motivo va a la bitácora, no a una columna: allí es inmutable y lleva el
 * nombre y el correo de quien lo escribió, congelados. Misma decisión que en el
 * rechazo del perfil fiscal (`H-04`).
 */
final class RetirarMedioPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->user()?->getAuthIdentifier();

        return $id !== null && Permisos::tiene((int) $id, 'creator.payment.verify');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:10', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.min' => 'Escribe un motivo que se pueda entender dentro de seis meses.',
        ];
    }
}
