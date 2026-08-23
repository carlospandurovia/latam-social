<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Requests;

use App\Shared\Auth\Permisos;
use App\Shared\Files\Almacen;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Marcar una identidad como verificada, con el documento adjunto (DEC-058).
 *
 * La casilla `confirma_cotejo` no es burocracia: es lo que convierte el clic en
 * una declaración de la persona que lo hace. La bitácora guardará su nombre y su
 * correo congelados, así que quien marque esto está firmando.
 */
final class VerificarIdentidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El middleware de la ruta ya lo comprueba. Se repite aquí porque una
        // ruta se puede mover de grupo por descuido y este objeto viaja con la
        // acción, no con la ruta.
        $id = $this->user()?->getAuthIdentifier();

        return $id !== null && Permisos::tiene((int) $id, 'creator.verify');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $kb = (int) config('latam.archivos.max_kb', 8192);
        // La lista de tipos vive en `Almacen`, que es quien de verdad la impone.
        $tipos = implode(',', Almacen::extensiones());

        return [
            'documento' => ['required', 'file', 'max:'.$kb, 'mimes:'.$tipos],
            'confirma_cotejo' => ['accepted'],
            'nota' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'documento.required' => 'Hay que adjuntar el documento de identidad cotejado.',
            'documento.mimes' => 'El documento debe ser un PDF o una imagen (jpg, png, webp).',
            'confirma_cotejo.accepted' => 'Confirma que cotejaste el documento contra los datos del creador.',
        ];
    }
}
