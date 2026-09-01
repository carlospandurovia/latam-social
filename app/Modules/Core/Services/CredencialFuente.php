<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Dónde vive la clave de una fuente de tipos de cambio (9.2, mudada en 9.17h).
 *
 * ### Qué cambió, y por qué sigue existiendo esta clase
 *
 * Hasta `9.17h` la clave vivía en cuatro columnas de `fx_sources`: una **segunda
 * caja fuerte**, más pobre que la que ya existía —no versionaba, no revocaba y
 * no dejaba rastro de la anterior—. Ahora vive en `integration_credentials`
 * (`9.17d`), que hace las tres cosas, y la fuente cuelga de una conexión.
 *
 * La clase se queda como **frontera**: `Decolecta` pregunta «¿cuál es la clave
 * de esta fuente?» y no tiene por qué saber que por debajo hay conexiones,
 * credenciales vivas y versiones. El día que una fuente se resuelva de otra
 * manera, cambia esto y no el cliente HTTP. Es el mismo motivo por el que
 * Greenter irá detrás de una frontera (`DEC-252`).
 *
 * ### La precedencia, ahora igual que la del correo
 *
 * **Manda la guardada; si no hay, el `.env`.** Hasta `9.17h` era al revés —el
 * entorno ganaba siempre— y eso convivía en la MISMA pantalla con la regla
 * contraria de `DEC-260`, que es como se produce un «cambié la clave y no pasó
 * nada»: se teclea en el panel, se guarda, y sigue llamando con la de antes sin
 * que nada lo diga. Dos integraciones vecinas con precedencias opuestas es peor
 * que cualquiera de las dos reglas por separado.
 *
 * Lo que hace que la regla sirva no es cuál se elija: es que **la pantalla diga
 * cuál está en efecto**, y `estado()` devuelve el origen para eso.
 *
 * ### La clave entera no vuelve nunca a una pantalla
 *
 * `estado()` es lo que ve el humano —de dónde sale, sus cuatro últimos, quién la
 * puso y cuándo— y `clave()` es lo que usa el cliente HTTP. Dos métodos
 * distintos a propósito: el día que alguien quiera enseñar «la configuración» en
 * una vista, el que tiene a mano es el que no filtra nada (`BR-SEC-001`).
 */
final class CredencialFuente
{
    public const ENTORNO = 'entorno';

    public const BASE = 'base';

    public const NINGUNA = 'ninguna';

    /** La clase de credencial con la que se guarda: es una clave de API. */
    public const CLASE = 'api_key';

    /**
     * Qué clave de configuración mira cada fuente.
     *
     * **De `config()` y no de `env()`.** Con `php artisan config:cache` —que es
     * lo normal en producción— `env()` devuelve null fuera de `config/`, y el
     * síntoma no sería un error: sería un cron que corre, no trae nada, y dice
     * «no hay credencial» teniéndola delante.
     */
    private const CLAVES = [
        'sunat' => 'latam.cambio.decolecta.clave',
    ];

    /**
     * La conexión de la que cuelga una fuente, con su estado. `null` si aún no
     * tiene ninguna —una instalación recién puesta está así—.
     */
    public static function conexion(string $fuente): ?object
    {
        if (!Schema::hasColumn('fx_sources', 'integration_connection_id')) {
            return null;
        }

        return DB::table('fx_sources as s')
            ->join('integration_connections as ic', 'ic.id', '=', 's.integration_connection_id')
            ->where('s.code', $fuente)
            ->first(['ic.id', 'ic.uuid', 'ic.name', 'ic.status', 'ic.environment',
                'ic.base_url', 'ic.last_success_at', 'ic.last_error_at', 'ic.last_error_message']);
    }

    /**
     * A dónde se llama para esta fuente.
     *
     * La de la conexión si tiene una propia; si no, la que declara el proveedor
     * (`DEC-255`); y si tampoco, la de `config()`. Hasta `9.17h` esto era una
     * constante de PHP **y además** una columna que se podía teclear: la
     * dirección pública de un proveedor no es ninguna de las dos cosas.
     */
    public static function url(string $fuente, string $porDefecto): string
    {
        $conexion = self::conexion($fuente);
        $url = $conexion === null ? null : Integraciones::urlDe((int) $conexion->id);

        return $url !== null && trim($url) !== '' ? trim($url) : $porDefecto;
    }

    /**
     * La clave en claro, o `null` si no hay ninguna configurada.
     *
     * Devuelve `null` y no lanza: «no hay credencial» es un estado normal —el
     * sistema recién instalado está así— y quien llama tiene que poder decirlo
     * con palabras en vez de estrellarse.
     */
    public static function clave(string $fuente): ?string
    {
        $conexion = self::conexion($fuente);

        if ($conexion !== null && $conexion->status === 'active') {
            try {
                $guardada = Integraciones::secreto((int) $conexion->id, self::CLASE);
            } catch (Throwable) {
                // Si `APP_KEY` cambio, `Crypt` lanza. Se contesta «no hay
                // credencial» en vez de reventar el cron: el sintoma correcto
                // es «configurala otra vez», no una traza en el planificador.
                $guardada = null;
            }

            if ($guardada !== null && trim($guardada) !== '') {
                return trim($guardada);
            }
        }

        return self::delEntorno($fuente);
    }

    /**
     * Lo que se le puede enseñar a una persona. **Nunca la clave.**
     *
     * @return array{origen: string, ultimos: ?string, puesta_por: ?string, puesta_el: ?string, version: ?int, url: ?string, conexion: ?object}
     */
    public static function estado(string $fuente): array
    {
        $conexion = self::conexion($fuente);
        $vacio = ['origen' => self::NINGUNA, 'ultimos' => null, 'puesta_por' => null,
            'puesta_el' => null, 'version' => null, 'url' => null, 'conexion' => $conexion];

        if ($conexion !== null) {
            $vacio['url'] = Integraciones::urlDe((int) $conexion->id);
        }

        if ($conexion !== null && $conexion->status === 'active') {
            $viva = collect(Integraciones::estado((int) $conexion->id))
                ->firstWhere('clase', self::CLASE);

            if ($viva !== null) {
                return [
                    'origen' => self::BASE,
                    'ultimos' => $viva['ultimos'] === null ? null : (string) $viva['ultimos'],
                    'puesta_por' => $viva['puesta_por'] === null ? null : (string) $viva['puesta_por'],
                    'puesta_el' => $viva['puesta_el'] === null ? null : (string) $viva['puesta_el'],
                    'version' => (int) $viva['version'],
                    'url' => $vacio['url'],
                    'conexion' => $conexion,
                ];
            }
        }

        if (self::delEntorno($fuente) !== null) {
            return ['origen' => self::ENTORNO] + $vacio;
        }

        return $vacio;
    }

    /**
     * Guarda la clave de una fuente, creando su conexión si aún no tenía.
     *
     * La conexión se crea **activa**: quien teclea una clave en el panel quiere
     * que se use. Y la clave entra por la puerta de `9.17d`, que revoca la
     * anterior en la misma transacción en vez de pisarla.
     */
    public static function guardar(string $fuente, string $clave, int $usuarioId, ?string $url = null): void
    {
        $clave = trim($clave);

        DB::transaction(function () use ($fuente, $clave, $usuarioId, $url): void {
            $conexion = self::conexion($fuente);

            $datos = [
                'integration_provider_id' => self::proveedorDe($fuente),
                'legal_entity_id' => null,
                'name' => (string) (DB::table('fx_sources')->where('code', $fuente)->value('name')
                    ?: 'Fuente de tipos de cambio'),
                'environment' => 'production',
                // Vacia = la que declara el proveedor (`DEC-255`).
                'base_url' => trim((string) $url),
                'username' => '',
                'status' => 'active',
            ];

            $uuid = Integraciones::guardarConexion(
                $conexion === null ? null : (string) $conexion->uuid, $datos, $usuarioId,
            );

            $conexionId = (int) Integraciones::porUuid($uuid)->id;

            DB::table('fx_sources')->where('code', $fuente)->update([
                'integration_connection_id' => $conexionId,
                'updated_at' => now(),
            ]);

            if ($clave !== '') {
                Integraciones::guardarSecreto($conexionId, self::CLASE, $clave, $usuarioId);
            }
        });
    }

    /**
     * Revoca la clave guardada. **No la borra: la marca.**
     *
     * `9.2` la ponía a `NULL` y con ella se iba quién la había puesto. Ahora
     * queda la fila revocada, con su motivo: la pregunta «¿quién tuvo acceso a
     * este servicio y hasta cuándo?» es la primera el día que aparezca un
     * consumo raro, y no se puede contestar borrando.
     */
    public static function olvidar(string $fuente): void
    {
        $conexion = self::conexion($fuente);

        if ($conexion === null) {
            return;
        }

        Integraciones::revocarSecreto(
            (int) $conexion->id, self::CLASE, 'Retirada desde la pestana de tipos de cambio.',
        );
    }

    public static function hay(string $fuente): bool
    {
        return self::clave($fuente) !== null;
    }

    private static function proveedorDe(string $fuente): int
    {
        // Por PROPOSITO y no por codigo: el dia que se cambie de pasarela el
        // codigo cambia y el proposito no (`DEC-258`).
        $id = DB::table('integration_providers')
            ->where('purpose', 'fx')->where('is_active', 1)
            ->orderBy('id')->value('id');

        if ($id === null) {
            throw new RuntimeException(
                'No hay ningun proveedor de tipos de cambio en el catalogo: siembre los cimientos.',
            );
        }

        unset($fuente);

        return (int) $id;
    }

    private static function delEntorno(string $fuente): ?string
    {
        $llave = self::CLAVES[$fuente] ?? null;

        if ($llave === null) {
            return null;
        }

        $valor = config($llave);

        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }
}
