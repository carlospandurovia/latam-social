<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Shared\Audit\Bitacora;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Dar de alta la cuenta con la que alguien de fuera entra al sistema (`5.9`).
 *
 * ### Antes de esto, la contraseña se dictaba por teléfono
 *
 * `usuarios:crear` es un comando de consola pensado para el equipo interno, y su
 * flujo es: el administrador teclea una contraseña, la persona la usa, y
 * `must_change_password` la obliga a cambiarla. Funciona para cuatro personas
 * que se sientan en la misma oficina.
 *
 * No funciona para un creador en Bogotá al que se acaba de aprobar. Y sobre todo:
 * **hay un momento en el que dos personas conocen la credencial**, que es justo
 * lo que `T-23` existe para evitar. Con enlace, la contraseña la escribe su dueño
 * y nadie más la ha visto nunca.
 *
 * ### La cuenta nace con una contraseña que nadie conoce
 *
 * `password` es `NOT NULL` en el esquema de Laravel, así que hay que poner algo.
 * Se pone el hash de 32 bytes aleatorios que **no se guardan, no se muestran y
 * no se devuelven**: no existe fuera de esta línea. Es una cuenta a la que
 * literalmente no se puede entrar hasta que se usa el enlace, y eso es lo que
 * queremos.
 *
 * Alternativa descartada: `password` a `NULL` o a cadena vacía. Cualquiera de las
 * dos convierte «sin contraseña» en un estado que el resto del código tiene que
 * recordar comprobar, y el día que alguien se olvide, `Hash::check` contra una
 * cadena vacía es una discusión que no quiero tener.
 *
 * ### Por qué esto vive en Identity y no en Creator
 *
 * Porque la cuenta es de Identity. Creator sabe a quién hay que darle acceso;
 * Identity sabe qué es una cuenta. `deptrac.yaml` dice `Creator: [..., Identity]`
 * y no al revés, así que la llamada va en esa dirección — y el día que un usuario
 * de cliente necesite lo mismo (`F8`), la pieza ya está escrita.
 */
final class Cuentas
{
    /**
     * Crea la cuenta de un creador aprobado y le manda su enlace de alta.
     *
     * Devuelve el `id` del usuario, o `null` con el motivo cuando no se ha
     * podido: **aprobar a un creador no puede fallar porque su correo choque**.
     * Eso se cuenta y se resuelve aparte; lo que no se hace es tirar abajo la
     * aprobación, que es la decisión de negocio que ya se tomó.
     *
     * @return array{usuarioId: ?int, motivo: ?string}
     */
    public static function paraCreador(
        string $email,
        string $nombre,
        string $idioma = 'es',
        ?int $solicitanteId = null,
    ): array {
        $email = mb_strtolower(trim($email));

        // La misma comprobacion que hace `uq_users_email_active`, pero con
        // palabras. Sin esto el INSERT revienta con un 1062 dentro de la
        // transaccion de aprobacion y se lleva la aprobacion por delante.
        $ocupado = DB::table('users')
            ->where('email', $email)
            ->where('status', '!=', 'deactivated')
            ->first(['id', 'user_type']);

        if ($ocupado !== null) {
            return [
                'usuarioId' => null,
                'motivo' => $ocupado->user_type === 'creator'
                    ? 'ya_tenia_cuenta'
                    : 'correo_de_usuario_interno',
            ];
        }

        $usuarioId = DB::transaction(function () use ($email, $nombre, $idioma): int {
            $id = (int) DB::table('users')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'name' => $nombre,
                'email' => $email,
                // Una contrasena que no conoce nadie, ni siquiera esta linea
                // dentro de un segundo. La cuenta es inaccesible hasta que su
                // dueno use el enlace.
                'password' => Hash::make(bin2hex(random_bytes(32))),
                'user_type' => 'creator',
                'status' => 'active',
                'locale' => $idioma,
                // Se queda puesto a proposito: si algun dia se repone una
                // contrasena a mano, la obligacion de cambiarla sigue viva. Al
                // usar el enlace se apaga, porque entonces la puso su dueno.
                'must_change_password' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rolId = DB::table('roles')->where('code', 'creator')->where('scope', 'creator')->value('id');

            if ($rolId !== null) {
                DB::table('role_user')->insert([
                    'user_id' => $id,
                    'role_id' => $rolId,
                    'assigned_at' => now(),
                ]);
            }

            return $id;
        });

        Bitacora::registrar(
            accion: 'user.created',
            tipoEntidad: 'user',
            idEntidad: $usuarioId,
            cambios: [
                'user_type' => ['antes' => null, 'despues' => 'creator'],
                'email' => ['antes' => null, 'despues' => $email],
            ],
        );

        EnlacesDeContrasena::emitir($usuarioId, 'initial', $solicitanteId);

        return ['usuarioId' => $usuarioId, 'motivo' => null];
    }

    /**
     * Qué contarle al revisor cuando la cuenta no se ha podido crear.
     *
     * Un aviso que dice «no se pudo» y nada más obliga a abrir un ticket para
     * averiguar qué pasó. Cada motivo tiene una acción distinta detrás.
     */
    public static function explicacion(string $motivo): string
    {
        return match ($motivo) {
            'ya_tenia_cuenta' => 'Ese correo ya tenia una cuenta de creador, asi que no se ha creado otra. '
                .'Si es la misma persona, esta bien; si no, hay dos creadores compartiendo correo y hay que resolverlo.',
            'correo_de_usuario_interno' => 'Ese correo pertenece a un usuario INTERNO del sistema. '
                .'No se le ha creado cuenta de creador: seria la misma credencial para dos papeles distintos. '
                .'Pidele al creador otro correo.',
            default => 'No se pudo crear la cuenta de acceso del creador.',
        };
    }
}
