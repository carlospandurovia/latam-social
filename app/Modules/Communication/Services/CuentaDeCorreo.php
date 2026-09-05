<?php

declare(strict_types=1);

namespace App\Modules\Communication\Services;

use App\Modules\Core\Services\Integraciones;
use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use App\Shared\Config\Instalacion;
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
     * Transportes que **no** sacan nada de la máquina.
     *
     * @var list<string>
     */
    private const CAPTURADORES = ['log', 'array', 'null'];

    /**
     * Dónde queda escrito que ya se desvió, para esta petición.
     *
     * Hace falta acordarse porque `aplicar()` reescribe el transporte a `log`, y
     * sin esto una segunda llamada leería ese valor —el que ella misma puso—,
     * concluiría que ya no hace falta desviar, y aplicaría la cuenta de
     * producción que acababa de rechazar. Un desvío que se desactiva a sí mismo
     * en la segunda vuelta es peor que no tenerlo: funciona en la prueba.
     *
     * Vive en la **configuración** y no en una propiedad estática. Una estática
     * sobrevive a la petición, y en la suite eso significa que lo que decida una
     * prueba lo hereda la siguiente: exactamente el defecto que apareció al
     * escribir esto —una prueba de `9.17b` se puso roja porque arrastraba el
     * transporte que había fijado la anterior—. La configuración se rehace en
     * cada petición y en cada prueba, que es la vida que esta decisión tiene.
     */
    private const MARCA_DESVIO = 'mail.desviado';

    /**
     * `null` si el correo sale de verdad desde aquí; el motivo si se desvía
     * al capturador (9.22b, la otra mitad de `DEC-029`).
     *
     * ### El agujero que esto tapa
     *
     * Desde `9.17g` la cuenta SMTP vive **en la base**. Eso fue un acierto —era
     * el último ajuste que obligaba a entrar a la máquina— y a la vez abrió esto:
     * una copia del volcado de producción en un servidor de pruebas trae dentro
     * la cuenta de correo buena, y el sistema **manda correos de verdad a los
     * creadores** sin que nadie haya configurado nada.
     *
     * Y no es un correo suelto: `9.19b` escribe a **cada creador activo** al
     * publicar una versión de los términos. El destinatario es un tercero, y un
     * correo mandado no se retira.
     *
     * ### La regla, en una frase
     *
     * En una instalación que no es producción, el correo **sólo sale** si la
     * cuenta en efecto es una conexión de **pruebas guardada en la base**.
     * Cualquier otra cosa —una conexión de producción, o el `.env`— va al
     * capturador.
     *
     * Deja abierto el camino legítimo —una cuenta de ensayo configurada **en el
     * panel**, que es donde `DEC-190` quiere la configuración— y cierra los dos
     * que muerden: el volcado restaurado y el `.env` copiado de producción para
     * levantar el servidor de pruebas deprisa.
     *
     * ### Desviar y no negarse
     *
     * `9.22a` se **niega** cuando el envío a la administración no toca; aquí se
     * **desvía**. Son remedios distintos porque los fallos lo son: no emitir un
     * comprobante deja el trabajo a medias y hay que enterarse; no mandar un
     * correo de prueba no rompe nada, y con el capturador el mensaje sigue
     * escrito y se puede leer. Negarse aquí convertiría cada pantalla que manda
     * un correo en un error.
     */
    public static function desviado(): ?string
    {
        if (Instalacion::esProduccion() || Instalacion::anulacionAbierta()) {
            return null;
        }

        $yaDesviado = config(self::MARCA_DESVIO);

        if (is_string($yaDesviado)) {
            return $yaDesviado;
        }

        $cuenta = self::vigente();

        if ($cuenta !== null) {
            if (Instalacion::porQueNoPuedeUsar((string) $cuenta->environment) === null) {
                // Una conexion de PRUEBAS guardada en el panel: ese es el camino
                // bueno para ensayar el correo desde una maquina que no es la de
                // verdad, y no se toca.
                return null;
            }

            return sprintf(
                'La cuenta activa «%s» es de PRODUCCIÓN y esta instalación es «%s», '
                .'así que el correo se escribe en el registro y no sale. '
                .'Para ensayar de verdad desde aquí, guarde una conexión de correo en entorno Pruebas.',
                (string) $cuenta->name,
                Instalacion::nombre(),
            );
        }

        if (in_array((string) config('mail.default'), self::CAPTURADORES, true)) {
            // Ya no sale nada: no hay nada que desviar.
            return null;
        }

        return sprintf(
            'El servidor de correo sale del archivo de entorno y esta instalación es «%s», '
            .'así que el correo se escribe en el registro y no sale. Suele pasar al copiar el '
            .'.env de producción para levantar un servidor de pruebas.',
            Instalacion::nombre(),
        );
    }

    /**
     * Qué configuración está en efecto y de dónde sale.
     *
     * @return array{origen: string, transporte: string, host: ?string, port: ?int, encryption: ?string, from_address: ?string, from_name: ?string, sale_de_aqui: bool, desviado: ?string}
     */
    public static function enEfecto(): array
    {
        $cuenta = self::vigente();
        // 9.22b: si el correo se desvia, `sale_de_aqui` tiene que decir que NO.
        // Una pantalla que afirma «sale de aqui» cuando el mensaje termina en un
        // archivo de registro es peor que una que no dice nada: hace perder la
        // tarde buscando el correo en la bandeja del destinatario.
        $desviado = self::desviado();

        if ($cuenta !== null) {
            return [
                'origen' => self::DE_LA_BASE,
                'transporte' => $desviado === null ? 'smtp' : 'log',
                'host' => (string) $cuenta->host,
                'port' => (int) $cuenta->port,
                'encryption' => $cuenta->encryption === null ? null : (string) $cuenta->encryption,
                'from_address' => (string) $cuenta->from_address,
                'from_name' => (string) $cuenta->from_name,
                'sale_de_aqui' => $desviado === null,
                'desviado' => $desviado,
            ];
        }

        $transporte = $desviado === null ? (string) config('mail.default') : 'log';

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
            'sale_de_aqui' => !in_array($transporte, self::CAPTURADORES, true),
            'desviado' => $desviado,
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
        // 9.22b: el desvio se decide ANTES de mirar la cuenta y ANTES de tocar
        // nada, porque tambien alcanza al `.env` --el caso de «copie el .env de
        // produccion para levantar el servidor de pruebas»--.
        if (($motivo = self::desviado()) !== null) {
            config([self::MARCA_DESVIO => $motivo, 'mail.default' => 'log']);

            return;
        }

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

        // 9.22b: probar con el correo desviado habria dicho «funciona» sin haber
        // mandado nada --el capturador nunca falla-- y habria estampado
        // `last_success_at`, que es la fecha en la que el sistema afirma que esa
        // cuenta funcionaba. Una prueba que no puede fallar no es una prueba, y
        // una que ademas deja escrito que salio bien es peor que no tenerla.
        if (($motivo = self::desviado()) !== null) {
            throw new RuntimeException('No se puede probar la cuenta desde aquí. '.$motivo);
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

        // 9.22b: desviado NO es lo mismo que mal configurado, y pintarlos igual
        // seria un error de los que cuestan caro: en un servidor de pruebas el
        // desvio es el estado CORRECTO, y un rojo permanente ahi acabaria
        // haciendo que tampoco se lea el rojo de produccion, que si importa.
        if ($efecto['desviado'] !== null) {
            $avisos[] = Aviso::ambar($efecto['desviado']);
        } elseif (!$efecto['sale_de_aqui']) {
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
