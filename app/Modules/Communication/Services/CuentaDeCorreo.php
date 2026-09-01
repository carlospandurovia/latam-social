<?php

declare(strict_types=1);

namespace App\Modules\Communication\Services;

use App\Modules\Core\Services\Integraciones;
use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Con qué cuenta sale el correo (9.17g).
 *
 * ### La precedencia, escrita una sola vez
 *
 * Manda **la conexión activa** de propósito `email` si existe; si no, lo que
 * diga `.env`. No hay tercera opción y no hay mezcla: o toda la configuración
 * sale de la base, o toda sale del entorno. Media configuración de cada sitio es
 * la clase de cosa que produce «cambié el puerto y no pasó nada».
 *
 * Y `enEfecto()` devuelve **de dónde sale**, no sólo qué vale. La pantalla lo
 * enseña porque una precedencia que no se ve hace perder horas.
 *
 * ### El secreto no vive aquí
 *
 * La contraseña es una credencial de la conexión (`9.17d`): cifrada, versionada
 * y con la rotación que no la pisa. Esta clase pide el valor en el único momento
 * en que hace falta —al construir la configuración— y no lo devuelve a ninguna
 * pantalla (`DEC-226`).
 */
final class CuentaDeCorreo
{
    /** Lo que se enseña en la pantalla como origen de la configuración. */
    public const DE_LA_BASE = 'base';

    public const DEL_ENTORNO = 'entorno';

    /** @var array<string, string> */
    public const CIFRADOS = ['' => 'Sin cifrar', 'tls' => 'TLS (normalmente 587)', 'ssl' => 'SSL (normalmente 465)'];

    /** Puertos que la costumbre asocia a cada cifrado (9.17i). */
    public const PUERTOS = ['ssl' => 465, 'tls' => 587];

    /**
     * La conexión de correo activa, con sus parámetros. Nunca la contraseña.
     */
    public static function vigente(): ?object
    {
        return self::cuenta(soloActiva: true);
    }

    /**
     * La cuenta guardada, esté encendida o apagada (9.17i).
     *
     * Hace falta porque `9.17g` dejó una **puerta de un solo sentido**: guardar
     * ponía la conexión en `active` y no había ninguna forma de volver al
     * `.env`. Para poder apagarla hay que poder verla apagada.
     */
    public static function guardada(): ?object
    {
        return self::cuenta(soloActiva: false);
    }

    private static function cuenta(bool $soloActiva): ?object
    {
        if (!Schema::hasTable('mail_settings')) {
            return null;
        }

        $consulta = DB::table('integration_connections as ic')
            ->join('mail_settings as ms', 'ms.integration_connection_id', '=', 'ic.id')
            ->where('ic.purpose_snapshot', 'email');

        if ($soloActiva) {
            $consulta->where('ic.status', 'active');
        } else {
            // La encendida manda: puede haber varias apagadas, activa sólo una
            // (`uq_iconn_activa`, `DEC-258`).
            $consulta->whereIn('ic.status', ['active', 'disabled'])
                ->orderByRaw("CASE WHEN ic.status = 'active' THEN 0 ELSE 1 END")
                ->orderByDesc('ic.id');
        }

        return $consulta->first(['ic.id', 'ic.uuid', 'ic.name', 'ic.environment', 'ic.username',
            'ic.status', 'ic.last_success_at', 'ic.last_error_at', 'ic.last_error_message',
            'ms.host', 'ms.port', 'ms.encryption', 'ms.from_address', 'ms.from_name',
            'ms.timeout_seconds']);
    }

    /**
     * Enciende o apaga la cuenta guardada (9.17i).
     *
     * Apagar **no borra nada**: la cuenta queda escrita y el correo vuelve al
     * `.env`. Es lo que permite dejar de mandar desde aquí sin tener que
     * teclear la contraseña otra vez para volver.
     */
    public static function conmutar(int $conexionId, bool $encendida): void
    {
        $antes = (string) DB::table('integration_connections')
            ->where('id', $conexionId)->value('status');

        $despues = $encendida ? 'active' : 'disabled';

        if ($antes === $despues) {
            return;
        }

        DB::table('integration_connections')->where('id', $conexionId)->update([
            'status' => $despues,
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: $encendida ? 'mail_settings.enabled' : 'mail_settings.disabled',
            tipoEntidad: 'integration_connection',
            idEntidad: $conexionId,
            cambios: ['estado' => ['antes' => $antes, 'despues' => $despues]],
        );
    }

    /**
     * Qué configuración está en efecto y de dónde sale.
     *
     * @return array{origen: string, transporte: string, host: ?string, port: ?int, encryption: ?string, from_address: ?string, from_name: ?string, sale_de_aqui: bool}
     */
    public static function enEfecto(): array
    {
        $cuenta = self::vigente();

        if ($cuenta !== null) {
            return [
                'origen' => self::DE_LA_BASE,
                'transporte' => 'smtp',
                'host' => (string) $cuenta->host,
                'port' => (int) $cuenta->port,
                'encryption' => $cuenta->encryption === null ? null : (string) $cuenta->encryption,
                'from_address' => (string) $cuenta->from_address,
                'from_name' => (string) $cuenta->from_name,
                'sale_de_aqui' => true,
            ];
        }

        $transporte = (string) config('mail.default');

        return [
            'origen' => self::DEL_ENTORNO,
            'transporte' => $transporte,
            'host' => config('mail.mailers.smtp.host') === null ? null : (string) config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port') === null ? null : (int) config('mail.mailers.smtp.port'),
            'encryption' => config('mail.mailers.smtp.scheme') === null ? null : (string) config('mail.mailers.smtp.scheme'),
            'from_address' => config('mail.from.address') === null ? null : (string) config('mail.from.address'),
            'from_name' => config('mail.from.name') === null ? null : (string) config('mail.from.name'),
            // Con el transporte en «log», «array» o «null» nada sale de la
            // maquina: se escribe en el registro y no da ningun error.
            'sale_de_aqui' => !in_array($transporte, ['log', 'array', 'null'], true),
        ];
    }

    /**
     * Mete la cuenta de la base en la configuración viva de Laravel.
     *
     * Se llama al arrancar. Si no hay conexión activa no toca nada: manda
     * `.env`, que es la precedencia escrita arriba.
     */
    public static function aplicar(): void
    {
        $cuenta = self::vigente();

        if ($cuenta === null) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => (string) $cuenta->host,
            'mail.mailers.smtp.port' => (int) $cuenta->port,
            'mail.mailers.smtp.scheme' => $cuenta->encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.username' => (string) ($cuenta->username ?? ''),
            'mail.mailers.smtp.password' => Integraciones::secreto((int) $cuenta->id, 'password') ?? '',
            'mail.mailers.smtp.timeout' => (int) $cuenta->timeout_seconds,
            'mail.from.address' => (string) $cuenta->from_address,
            'mail.from.name' => (string) $cuenta->from_name,
        ]);
    }

    /**
     * Guarda los parámetros de una conexión de correo.
     *
     * La conexión ya existe: se crea desde la pantalla de integraciones como
     * cualquier otra. Aquí sólo entran los datos que son del correo.
     *
     * @param array<string, mixed> $datos
     */
    public static function guardar(int $conexionId, array $datos): void
    {
        $campos = [
            'host' => trim((string) $datos['host']),
            'port' => (int) $datos['port'],
            'encryption' => ($datos['encryption'] ?? '') !== '' ? (string) $datos['encryption'] : null,
            'from_address' => trim((string) $datos['from_address']),
            'from_name' => trim((string) $datos['from_name']),
            'timeout_seconds' => (int) ($datos['timeout_seconds'] ?? 10),
            'updated_at' => now(),
        ];

        $existe = DB::table('mail_settings')
            ->where('integration_connection_id', $conexionId)->exists();

        if ($existe) {
            DB::table('mail_settings')->where('integration_connection_id', $conexionId)->update($campos);
        } else {
            DB::table('mail_settings')->insert(
                $campos + ['integration_connection_id' => $conexionId, 'created_at' => now()],
            );
        }

        // La contrasena NO entra aqui (`DEC-226`): se anota que cambio la
        // cuenta, no con que valores se conecta.
        Bitacora::registrar(
            accion: 'mail_settings.saved',
            tipoEntidad: 'integration_connection',
            idEntidad: $conexionId,
            cambios: [
                'servidor' => ['antes' => null, 'despues' => $campos['host'].':'.$campos['port']],
                'remitente' => ['antes' => null, 'despues' => $campos['from_address']],
            ],
        );
    }

    /**
     * Manda un correo de prueba y **escribe el resultado en la conexión**.
     *
     * Es lo que convierte «creo que está bien» en «funciona». Y el resultado se
     * guarda porque el modo de fallo real de este proyecto no es que falle: es
     * que falle y nadie lo sepa hasta que un creador diga que no le llegó nada.
     */
    public static function probar(string $destino): void
    {
        $cuenta = self::vigente();

        if ($cuenta === null) {
            throw new RuntimeException(
                'No hay ninguna cuenta de correo activa: guarde una y actívela antes de probarla.',
            );
        }

        self::aplicar();

        try {
            Mail::raw(
                'Esto es una prueba de la cuenta de correo de LATAM Social. Si lo estás leyendo, funciona.',
                static function (mixed $mensaje) use ($destino): void {
                    $mensaje->to($destino)->subject('Prueba de la cuenta de correo');
                },
            );
        } catch (\Throwable $e) {
            DB::table('integration_connections')->where('id', $cuenta->id)->update([
                'last_error_at' => now(),
                'last_error_message' => mb_substr($e->getMessage(), 0, 255),
                'updated_at' => now(),
            ]);

            throw new RuntimeException('El servidor no aceptó el correo: '.mb_substr($e->getMessage(), 0, 200));
        }

        DB::table('integration_connections')->where('id', $cuenta->id)->update([
            'last_success_at' => now(),
            'last_verified_at' => now(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'mail_settings.tested',
            tipoEntidad: 'integration_connection',
            idEntidad: (int) $cuenta->id,
            cambios: ['destino' => ['antes' => null, 'despues' => $destino]],
        );
    }

    // --------------------------------------------------------------- avisos

    /** @return list<Aviso> */
    public static function avisos(): array
    {
        $avisos = [];
        $efecto = self::enEfecto();

        if (!$efecto['sale_de_aqui']) {
            $avisos[] = Aviso::rojo(sprintf(
                'El correo está en «%s»: no sale de este servidor, se escribe en el registro. '
                .'Nadie recibe nada —ni el enlace de alta de un creador— y el sistema no da '
                .'ningún error.',
                $efecto['transporte'],
            ));
        }

        // Sin cifrar significa mandar la contrasena en claro por la red. No se
        // impide --un servidor local de pruebas no lo lleva-- pero se dice.
        if ($efecto['origen'] === self::DE_LA_BASE && $efecto['encryption'] === null) {
            $avisos[] = Aviso::rojo(
                'La cuenta de correo va sin cifrar: la contraseña viaja en claro por la red. '
                .'Sólo es aceptable contra un servidor de pruebas en esta misma máquina.',
            );
        }

        $cuenta = self::vigente();

        // Fallo mas reciente que el ultimo exito: no es «un correo que reboto»,
        // es la cuenta que dejo de funcionar.
        if ($cuenta !== null && $cuenta->last_error_at !== null
            && ($cuenta->last_success_at === null || $cuenta->last_error_at > $cuenta->last_success_at)) {
            $avisos[] = Aviso::rojo(sprintf(
                'La última prueba de la cuenta de correo falló: %s',
                (string) ($cuenta->last_error_message ?? 'sin detalle'),
            ));
        }

        if ($efecto['origen'] === self::DEL_ENTORNO && $efecto['sale_de_aqui']) {
            $avisos[] = Aviso::ambar(
                'El correo se configura en el `.env` de este servidor. Cambiarlo exige entrar a la '
                .'máquina: guarde la cuenta aquí para poder tocarla sin desplegar.',
            );
        }

        // 9.17i: el puerto y el cifrado que no casan.
        //
        // Salió de un intento real: `smtp.gmail.com` con cifrado SSL y puerto
        // 587. Los dos valores son legítimos por separado y ninguna regla los
        // rechazaba, pero juntos NO CONECTAN --587 habla claro y sube a TLS con
        // STARTTLS; 465 va cifrado desde el saludo--. El error que devuelve el
        // servidor es un tiempo de espera agotado, que no dice nada de esto.
        //
        // Se AVISA y no se impide (`DEC-190`): la costumbre no es la ley y hay
        // servidores que escuchan donde quieren. Pero quien lo teclee mal se
        // entera aquí y no dentro de tres días, cuando alguien pregunte por qué
        // no le llegó el correo.
        $guardada = self::guardada();

        if ($guardada !== null && $guardada->encryption !== null) {
            $esperado = self::PUERTOS[(string) $guardada->encryption] ?? null;
            $otro = array_search((int) $guardada->port, self::PUERTOS, true);

            if ($esperado !== null && (int) $guardada->port !== $esperado && $otro !== false) {
                $avisos[] = Aviso::ambar(sprintf(
                    'La cuenta de correo tiene cifrado %s con el puerto %d, y ésa es la pareja del '
                    .'cifrado %s. Suele no conectar, y el servidor sólo contesta con una espera '
                    .'agotada: use el puerto %d, o cambie el cifrado a %s.',
                    strtoupper((string) $guardada->encryption),
                    (int) $guardada->port,
                    strtoupper((string) $otro),
                    $esperado,
                    strtoupper((string) $otro),
                ));
            }
        }

        return $avisos;
    }
}
