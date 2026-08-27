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
 *
 * Eso se cobró antes de lo previsto: en `T-36`, una iteración después, el alta de
 * usuarios **internos** pasó a usar exactamente la misma pieza. La diferencia
 * entre las dos entradas son tres argumentos.
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
        return self::crear($email, $nombre, 'creator', 'creator', $idioma, $solicitanteId);
    }

    /**
     * Crea la cuenta de alguien del equipo y le manda su enlace (`T-36`).
     *
     * ### Lo que esto cierra
     *
     * `BR-SEC-004` es 🔴 y dice *«nunca se transmite una contraseña en texto
     * claro por ningún canal»*. Desde `5.9` se cumplía para los creadores y
     * **no para los usuarios internos**: `usuarios:crear` pedía la contraseña
     * por consola y alguien se la dictaba.
     *
     * Y son justo las cuentas donde más importa. La base **exige dos personas
     * distintas** para lo que toca dinero —`ck_ctp_segregation` para aprobar un
     * perfil fiscal, `ck_cpm_segregation` para verificar un medio de pago
     * (`DEC-044`, `BR-FIN-005`)—, y esa garantía se apoya en que dos `user_id`
     * distintos sean dos personas distintas. Si el administrador conoce la
     * credencial de la segunda, la separación de funciones es una fila en una
     * tabla y nada más.
     *
     * `must_change_password` era el parche: obligaba a cambiarla *después*, y
     * dejaba una ventana —de minutos o de meses— en la que dos personas conocían
     * la credencial. Ahora no hay ventana: la contraseña **nunca existe** hasta
     * que la escribe su dueño.
     *
     * @return array{usuarioId: ?int, motivo: ?string}
     */
    public static function paraInterno(
        string $email,
        string $nombre,
        string $rolCodigo,
        ?int $solicitanteId = null,
    ): array {
        return self::crear($email, $nombre, 'internal', $rolCodigo, 'es', $solicitanteId);
    }

    /**
     * La parte común: una cuenta sin contraseña utilizable, con su rol y su enlace.
     *
     * @return array{usuarioId: ?int, motivo: ?string}
     */
    private static function crear(
        string $email,
        string $nombre,
        string $tipo,
        string $rolCodigo,
        string $idioma,
        ?int $solicitanteId,
    ): array {
        $email = mb_strtolower(trim($email));

        // La misma comprobacion que hace `uq_users_email_active`, pero con
        // palabras. Sin esto el INSERT revienta con un 1062 dentro de la
        // transaccion de quien llama y se la lleva por delante.
        $ocupado = DB::table('users')
            ->where('email', $email)
            ->where('status', '!=', 'deactivated')
            ->first(['id', 'user_type']);

        if ($ocupado !== null) {
            return [
                'usuarioId' => null,
                'motivo' => $ocupado->user_type === $tipo
                    ? 'ya_tenia_cuenta'
                    : ($ocupado->user_type === 'internal'
                        ? 'correo_de_usuario_interno'
                        : 'correo_de_creador'),
            ];
        }

        $rolId = DB::table('roles')->where('code', $rolCodigo)->where('scope', $tipo)->value('id');

        if ($rolId === null) {
            // Se comprueba ANTES de crear nada. Una cuenta sin rol es una cuenta
            // que entra y no puede hacer nada, y el sintoma --pantallas en 403--
            // no apunta al alta.
            return ['usuarioId' => null, 'motivo' => 'rol_desconocido'];
        }

        $usuarioId = DB::transaction(function () use ($email, $nombre, $tipo, $idioma, $rolId): int {
            $id = (int) DB::table('users')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'name' => $nombre,
                'email' => $email,
                // Una contrasena que no conoce nadie, ni siquiera esta linea
                // dentro de un segundo. La cuenta es inaccesible hasta que su
                // dueno use el enlace.
                'password' => Hash::make(bin2hex(random_bytes(32))),
                'user_type' => $tipo,
                'status' => 'active',
                'locale' => $idioma,
                // Se queda puesto a proposito: si algun dia se repone una
                // contrasena a mano, la obligacion de cambiarla sigue viva. Al
                // usar el enlace se apaga, porque entonces la puso su dueno.
                'must_change_password' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('role_user')->insert([
                'user_id' => $id,
                'role_id' => $rolId,
                'assigned_at' => now(),
            ]);

            return $id;
        });

        Bitacora::registrar(
            accion: 'user.created',
            tipoEntidad: 'user',
            idEntidad: $usuarioId,
            cambios: [
                'user_type' => ['antes' => null, 'despues' => $tipo],
                'email' => ['antes' => null, 'despues' => $email],
                'rol' => ['antes' => null, 'despues' => $rolCodigo],
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
                .'No se le ha creado cuenta: seria la misma credencial para dos papeles distintos. '
                .'Pide otro correo.',
            'correo_de_creador' => 'Ese correo pertenece a un CREADOR. No se le ha creado cuenta interna: '
                .'seria la misma credencial para los dos lados del sistema, y la separacion de funciones '
                .'del dinero (BR-FIN-005) se apoya en que cada cuenta sea una persona en un papel.',
            'rol_desconocido' => 'Ese rol no existe. No se ha creado nada: una cuenta sin rol entra y no '
                .'puede hacer nada, y el sintoma --pantallas en 403-- no apunta al alta.',
            default => 'No se pudo crear la cuenta de acceso.',
        };
    }
}
