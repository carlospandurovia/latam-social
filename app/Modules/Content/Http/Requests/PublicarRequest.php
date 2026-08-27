<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El formulario del post publicado (8.6).
 *
 * `published_at` es opcional: lo normal es reportar el post **justo después** de
 * publicarlo, y pedir una fecha que casi siempre es «ahora» es pedirle a alguien
 * que teclee un dato que el sistema ya sabe. Cuando se reporta días después —o lo
 * mete el equipo por el creador— sí hace falta, y entonces se pone.
 */
final class PublicarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:500', 'starts_with:https://'],
            // `before_or_equal:now` y no sólo `date`: un post publicado mañana no
            // existe, y `ck_pub_published_no_futuro` lo rechazaría con un 3819.
            'published_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['url' => 'enlace del post', 'published_at' => 'fecha de publicacion'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'url.starts_with' => 'El enlace del post tiene que empezar por https://',
            'published_at.before_or_equal' => 'Un post no se puede haber publicado en el futuro.',
        ];
    }
}
