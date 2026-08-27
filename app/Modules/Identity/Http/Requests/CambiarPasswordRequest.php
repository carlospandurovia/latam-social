<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambiar la propia contraseña (`T-23`).
 *
 * ### Sin permiso, y a propósito
 *
 * `authorize()` sólo exige estar autenticado. Cambiar la propia contraseña no
 * puede depender de un permiso: si dependiera, un usuario al que se le han
 * revocado los permisos no podría cambiarla — y ese es justo el usuario al que
 * más urge.
 *
 * ### Se pide la contraseña ACTUAL aunque sea obligatorio cambiarla
 *
 * Parece redundante: si el sistema obliga a cambiarla, quien está delante ya
 * entró con ella. Pero «entró» y «sigue delante» no son lo mismo: una sesión
 * abierta y desatendida bastaría para dejar fuera al dueño de la cuenta. Pedir
 * la actual convierte eso en imposible sin conocerla.
 *
 * ### Y la nueva tiene que ser DISTINTA
 *
 * Es la regla que hace que esto sirva de algo. Sin ella, el usuario puede
 * teclear su contraseña temporal dos veces, `must_change_password` se pone a 0,
 * y **la contraseña que conoce el administrador que creó la cuenta sigue
 * siendo válida**. El requisito quedaría cumplido en la base de datos y sin
 * cumplir en la realidad.
 */
final class CambiarPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // El liston vive en `FijarPasswordRequest::fuerza()` y se llama desde
        // las dos pantallas que ponen una contrasena. Repetirlo aqui seria
        // garantizar que un dia se endurezca en una y no en la otra --y la que
        // se quedara floja seria la que no se mira--.
        $fuerza = FijarPasswordRequest::fuerza();

        return [
            // `current_password` compara contra el hash del usuario conectado;
            // nunca se compara nada en claro ni sale de aquí.
            'actual' => ['required', 'string', 'current_password'],
            'password' => ['required', 'confirmed', $fuerza, 'different:actual'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'actual.required' => 'Escribe tu contrasena actual.',
            'actual.current_password' => 'Esa no es tu contrasena actual.',
            'password.confirmed' => 'Las dos contrasenas nuevas no coinciden.',
            'password.different' => 'La nueva tiene que ser distinta de la actual. '
                .'Repetirla dejaria valida la que ya conoce quien te creo la cuenta.',
            'password.uncompromised' => 'Esa contrasena aparece en filtraciones publicas conocidas. Elige otra.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'actual' => 'contrasena actual',
            'password' => 'contrasena nueva',
        ];
    }
}
