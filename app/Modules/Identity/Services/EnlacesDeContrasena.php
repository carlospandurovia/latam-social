<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Eventos\CorreoPedido;
use App\Shared\Eventos\Eventos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * El enlace de un solo uso con el que se pone una contraseña (`5.9` + `4.1`).
 *
 * ### Una sola pieza para dos casos
 *
 * `5.9` —el creador aprobado que estrena cuenta— y `4.1` —quien olvidó su
 * contraseña— parecen dos cosas y son la misma con dos relojes distintos:
 *
 * | Propósito | Dura | Por qué |
 * |---|---|---|
 * | `initial` | **72 h** | llega sin avisar; puede caer un viernes por la noche |
 * | `reset` | **1 h** | lo pide alguien que está delante de la pantalla ahora |
 *
 * Decisión de negocio (2026-08-26, `DEC-113`). Construirlas por separado habría
 * significado escribir dos veces la parte difícil —el token, la caducidad, el un
 * solo uso— y equivocarse en una de las dos.
 *
 * ### El token: 32 bytes del generador criptográfico, y sólo su huella se guarda
 *
 * `random_bytes(32)` en hexadecimal. No `Str::random()`, no `uniqid()`, no el
 * `uuid` de la fila: un token que se pueda adivinar es una cuenta que se puede
 * robar sin tocar la contraseña.
 *
 * Lo que entra en la tabla es `hash('sha256', $token)`. Buscar por token es
 * buscar por su huella, que es una consulta por índice único igual de rápida.
 * **Nadie con acceso de lectura a la base puede entrar en ninguna cuenta.**
 *
 * ### Emitir mata al anterior
 *
 * Decisión de negocio (2026-08-26). Quien pide un enlace nuevo suele hacerlo
 * porque sospecha del anterior; dejarlo vivo sería dejar abierta justo la puerta
 * que quería cerrar. Se revoca —no se borra— y `uq_pl_vigente` garantiza en la
 * base que no puede haber dos vivos para el mismo usuario y propósito.
 *
 * ### Usarlo mata la sesión y el «recordarme»
 *
 * Poner una contraseña nueva y dejar viva la sesión del que entró con la vieja
 * es no haber hecho nada. Se borran las filas de `sessions` de ese usuario y se
 * rota `remember_token`, que es lo que invalida las cookies de «mantener la
 * sesión» repartidas por ahí.
 */
final class EnlacesDeContrasena
{
    /** Cuánto vive cada tipo de enlace, en horas (`DEC-113`). */
    public const HORAS = ['initial' => 72, 'reset' => 1];

    /**
     * Qué propósito produce qué plantilla. Explícito, no derivado del nombre:
     * una convención es justo lo que hace que un renombrado silencioso deje de
     * mandar correos sin que falle nada.
     *
     * @var array<string, string>
     */
    private const PLANTILLAS = ['initial' => 'user.password_initial', 'reset' => 'user.password_reset'];

    /**
     * Por qué un enlace no sirve. En texto, porque estos motivos se enseñan.
     *
     * @var array<string, string>
     */
    public const MOTIVOS = [
        'no_existe' => 'Este enlace no es valido. Puede que lo hayas copiado a medias, '
            .'o que ya se haya usado uno mas reciente.',
        'caducado' => 'Este enlace ha caducado. Pide otro y te llegara uno nuevo.',
        'usado' => 'Este enlace ya se uso una vez y no vale para mas. '
            .'Si no fuiste tu, avisa a administracion cuanto antes.',
        'revocado' => 'Este enlace se anulo porque se pidio otro despues. '
            .'Usa el ultimo correo que hayas recibido.',
        'cuenta_inactiva' => 'Esta cuenta no esta activa. Contacta con administracion.',
        // No es «este enlace no vale»: el enlace puede estar perfectamente bien
        // y lo que se ha perdido es el rastro en este navegador --cookies
        // borradas, otra pestana, media hora de por medio--. Decirle que el
        // enlace no sirve le haria pedir otro sin necesidad.
        'sesion_perdida' => 'Se perdio el rastro en este navegador. Vuelve a abrir el enlace del correo.',
    ];

    /**
     * Emite un enlace, revoca el anterior y avisa a quien sepa enviarlo.
     *
     * Devuelve el token **en claro**, que es la única vez que existe. Quien la
     * llama normalmente lo ignora: para eso se levanta el evento.
     */
    public static function emitir(
        int $usuarioId,
        string $proposito,
        ?int $solicitanteId = null,
    ): string {
        if (!array_key_exists($proposito, self::HORAS)) {
            throw new \InvalidArgumentException("Proposito de enlace desconocido: «{$proposito}».");
        }

        $usuario = DB::table('users')->where('id', $usuarioId)
            ->first(['id', 'name', 'email', 'locale', 'status']);

        if ($usuario === null) {
            throw new \RuntimeException("No existe el usuario {$usuarioId}.");
        }

        $token = bin2hex(random_bytes(32));
        $horas = self::HORAS[$proposito];
        $caduca = now()->addHours($horas);

        DB::transaction(function () use ($usuarioId, $proposito, $token, $caduca, $solicitanteId): void {
            // Primero se mata al anterior. Si se insertara antes, `uq_pl_vigente`
            // —que es lo que de verdad garantiza «uno vivo»— rechazaria el
            // INSERT con un 1062 y el usuario veria un 500 en vez de su enlace.
            self::revocarVigentes($usuarioId, $proposito, 'sustituido');

            DB::table('password_links')->insert([
                'uuid' => (string) Str::uuid(),
                'user_id' => $usuarioId,
                'purpose' => $proposito,
                // La huella, nunca el token.
                'token_sha256' => hash('sha256', $token),
                'expires_at' => $caduca,
                'requested_by_user_id' => $solicitanteId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // El HECHO, sin el token, para que conste aunque el correo falle.
        Eventos::ocurrio(
            nombre: 'user.password_link_issued',
            tipoEntidad: 'user',
            idEntidad: $usuarioId,
            payload: [
                'purpose' => $proposito,
                'expires_at' => $caduca->toDateTimeString(),
                'hours' => $horas,
            ],
        );

        // Y el token, sólo por memoria, a quien sepa enviarlo. `CorreoPedido`
        // vive en `Shared` y **no se persiste**: es la única forma de que el
        // enlace llegue al correo sin quedar guardado en `domain_events`.
        Event::dispatch(new CorreoPedido(
            codigo: self::PLANTILLAS[$proposito],
            destinatario: (string) $usuario->email,
            variables: [
                'nombre' => (string) $usuario->name,
                // El enlace ya montado: Communication no conoce --ni tiene por
                // que conocer-- los nombres de ruta de Identity.
                'enlace' => route('recuperar.usar', ['token' => $token]),
                'caduca' => $caduca->format('d/m/Y H:i'),
                'horas' => $horas,
            ],
            idioma: (string) ($usuario->locale ?: 'es'),
            tipoRelacionado: 'user',
            idRelacionado: $usuarioId,
        ));

        return $token;
    }

    /**
     * ¿Sirve este token? Devuelve el motivo cuando no, para poder decirlo.
     *
     * @return array{ok: bool, motivo: ?string, enlace: ?object}
     */
    public static function validar(string $token, ?string $proposito = null): array
    {
        $fila = self::porToken($token, $proposito);

        if ($fila === null) {
            return ['ok' => false, 'motivo' => 'no_existe', 'enlace' => null];
        }

        // El orden importa: se contesta la causa MÁS ESPECÍFICA primero. Un
        // enlace usado que además caducó es un enlace usado, y esa es la
        // respuesta que le interesa a quien pregunta «¿alguien entró con esto?».
        $motivo = match (true) {
            $fila->used_at !== null => 'usado',
            $fila->revoked_at !== null => 'revocado',
            $fila->expires_at < now()->format('Y-m-d H:i:s') => 'caducado',
            $fila->status !== 'active' => 'cuenta_inactiva',
            default => null,
        };

        return ['ok' => $motivo === null, 'motivo' => $motivo, 'enlace' => $fila];
    }

    /**
     * Usa el enlace: pone la contraseña, lo quema y cierra todo lo demás.
     *
     * @return array{ok: bool, motivo: ?string, usuarioId: ?int}
     */
    public static function consumir(string $token, string $passwordEnClaro, ?string $ip): array
    {
        $empaquetada = is_string($ip) ? inet_pton($ip) : false;

        $resultado = DB::transaction(function () use ($token, $passwordEnClaro, $empaquetada): array {
            // `lockForUpdate` y no una simple lectura: dos peticiones con el
            // mismo token —el doble clic de siempre, o alguien que lo intercepta
            // y corre— tienen que poder consumirlo una sola vez. Sin el bloqueo
            // las dos leerian `used_at IS NULL` y las dos escribirian.
            $fila = DB::table('password_links as l')
                ->join('users as u', 'u.id', '=', 'l.user_id')
                ->where('l.token_sha256', hash('sha256', $token))
                ->lockForUpdate()
                ->first([
                    'l.id', 'l.user_id', 'l.purpose', 'l.expires_at',
                    'l.used_at', 'l.revoked_at', 'u.status',
                ]);

            if ($fila === null) {
                return ['ok' => false, 'motivo' => 'no_existe', 'usuarioId' => null];
            }

            $motivo = match (true) {
                $fila->used_at !== null => 'usado',
                $fila->revoked_at !== null => 'revocado',
                $fila->expires_at < now()->format('Y-m-d H:i:s') => 'caducado',
                $fila->status !== 'active' => 'cuenta_inactiva',
                default => null,
            };

            if ($motivo !== null) {
                return ['ok' => false, 'motivo' => $motivo, 'usuarioId' => null];
            }

            $usuarioId = (int) $fila->user_id;

            DB::table('password_links')->where('id', $fila->id)->update([
                'used_at' => now(),
                // `ck_pl_used` exige la IP. Si el servidor no la da, no se
                // inventa: se guarda la de bucle local, que es lo que de verdad
                // se sabe.
                'used_ip' => $empaquetada === false ? inet_pton('127.0.0.1') : $empaquetada,
                'updated_at' => now(),
            ]);

            // Cualquier otro enlace vivo de esta persona muere aqui. Si pidio
            // recuperacion teniendo pendiente el de alta, dejar el de alta vivo
            // seria dejar una segunda llave de una cerradura que acaba de
            // cambiar.
            foreach (array_keys(self::HORAS) as $otro) {
                self::revocarVigentes($usuarioId, $otro, 'password_puesta');
            }

            DB::table('users')->where('id', $usuarioId)->update([
                'password' => Hash::make($passwordEnClaro),
                // La puso su dueno: ya no hay nada que forzar.
                'must_change_password' => 0,
                // Rota la cookie de «mantener la sesion». Sin esto, un navegador
                // ajeno con esa cookie sigue entrando con la contrasena vieja
                // aunque ya no exista.
                'remember_token' => Str::random(60),
                'updated_at' => now(),
            ]);

            // Y las sesiones abiertas. Con `SESSION_DRIVER=database` esto es
            // real: se borran las filas.
            //
            // Con cualquier otro almacen NO se puede, y lo importante es que no
            // falle en silencio: la tabla existe igualmente --la crea el
            // esqueleto de Laravel-- asi que el `DELETE` funcionaria, no
            // borraria nada, y todo pareceria correcto mientras la sesion de
            // quien entro con la contrasena vieja sigue viva. Un aviso en el log
            // no lo arregla, pero convierte un agujero mudo en uno que se ve.
            if (config('session.driver') === 'database' && DB::getSchemaBuilder()->hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $usuarioId)->delete();
            } else {
                Log::warning(
                    'Contrasena cambiada sin poder cerrar las sesiones abiertas: '
                    .'`SESSION_DRIVER` no es `database` (DEC-118).',
                    ['usuario' => $usuarioId, 'driver' => config('session.driver')],
                );
            }

            return ['ok' => true, 'motivo' => null, 'usuarioId' => $usuarioId];
        });

        if ($resultado['ok']) {
            Bitacora::registrar(
                accion: 'user.password_link_used',
                tipoEntidad: 'user',
                idEntidad: (int) $resultado['usuarioId'],
                // El hecho, nunca el valor. `Bitacora::REDACTAR` lo ocultaria de
                // todas formas; no se manda ni para que lo oculte.
                cambios: ['password' => ['antes' => null, 'despues' => null]],
            );
        }

        return $resultado;
    }

    /**
     * Revoca los enlaces vivos de un usuario y un propósito. Devuelve cuántos.
     *
     * Nunca borra: un enlace que existió es evidencia de seguridad. Lo que
     * cambia es que `vigente_gate` pasa a `NULL` y deja libre el hueco del
     * índice único.
     */
    public static function revocarVigentes(int $usuarioId, string $proposito, string $motivo): int
    {
        return DB::table('password_links')
            ->where('user_id', $usuarioId)
            ->where('purpose', $proposito)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => $motivo,
                'updated_at' => now(),
            ]);
    }

    private static function porToken(string $token, ?string $proposito): ?object
    {
        $consulta = DB::table('password_links as l')
            ->join('users as u', 'u.id', '=', 'l.user_id')
            ->where('l.token_sha256', hash('sha256', $token));

        if ($proposito !== null) {
            $consulta->where('l.purpose', $proposito);
        }

        return $consulta->first([
            'l.id', 'l.uuid', 'l.user_id', 'l.purpose', 'l.expires_at',
            'l.used_at', 'l.revoked_at', 'u.name', 'u.email', 'u.status',
        ]);
    }
}
