<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Dónde vive la clave de una fuente de tipos de cambio, y quién la puso (9.2).
 *
 * **El entorno manda.** Si hay `DECOLECTA_API_KEY` en `.env`, se usa esa y la de
 * la base ni se mira. Es el sitio bueno: no viaja por el navegador, no está en
 * ninguna tabla y no sale en un volcado de base de datos. La columna cifrada
 * existe para no obligar a entrar por SSH cada vez que haya que rotarla.
 *
 * **La clave entera no se devuelve nunca a una pantalla.** `estado()` es lo que
 * ve el humano —de dónde sale, sus cuatro últimos, quién la puso y cuándo— y
 * `clave()` es lo que usa el cliente HTTP. Son dos métodos distintos a propósito:
 * el día que alguien quiera enseñar «la configuración» en una vista, el que
 * tiene a mano es el que no filtra nada.
 *
 * Nada de esto se registra en la bitácora con su valor: `BR-SEC-001` y la regla
 * de no guardar información sensible innecesariamente en los logs.
 */
final class CredencialFuente
{
    public const ENTORNO = 'entorno';

    public const BASE = 'base';

    public const NINGUNA = 'ninguna';

    /**
     * Qué clave de configuración mira cada fuente.
     *
     * **De `config()` y no de `env()`.** Con `php artisan config:cache` —que es
     * lo normal en producción— `env()` devuelve null fuera de `config/`, y el
     * síntoma no sería un error: sería un cron que corre, no trae nada, y dice
     * «no hay credencial» teniéndola delante. Es exactamente lo que le pasó al
     * seeder del administrador, y lo cazó PHPStan las dos veces.
     */
    private const CLAVES = [
        'sunat' => 'latam.cambio.decolecta.clave',
    ];

    /**
     * La clave en claro, o `null` si no hay ninguna configurada.
     *
     * Devuelve `null` y no lanza: «no hay credencial» es un estado normal —el
     * sistema recién instalado está así— y quien llama tiene que poder decirlo
     * con palabras en vez de estrellarse.
     */
    public static function clave(string $fuente): ?string
    {
        $delEntorno = self::delEntorno($fuente);

        if ($delEntorno !== null) {
            return $delEntorno;
        }

        $cifrada = DB::table('fx_sources')->where('code', $fuente)->value('api_key_cipher');

        if ($cifrada === null || $cifrada === '') {
            return null;
        }

        // Si `APP_KEY` cambio, `Crypt` lanza. Se contesta «no hay credencial»
        // en vez de reventar el cron: el sintoma correcto es «configurala otra
        // vez», no una traza en el log del planificador.
        try {
            return Crypt::decryptString((string) $cifrada);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Lo que se le puede enseñar a una persona. **Nunca la clave.**
     *
     * @return array{origen: string, ultimos: ?string, puesta_por: ?string, puesta_el: ?string, url: ?string}
     */
    public static function estado(string $fuente): array
    {
        $fila = DB::table('fx_sources as s')
            ->leftJoin('users as u', 'u.id', '=', 's.credential_set_by_user_id')
            ->where('s.code', $fuente)
            ->first(['s.api_key_cipher', 's.api_key_last4', 's.api_base_url',
                's.credential_set_at', 'u.name as autor']);

        if (self::delEntorno($fuente) !== null) {
            return [
                'origen' => self::ENTORNO,
                'ultimos' => null,
                'puesta_por' => null,
                'puesta_el' => null,
                'url' => $fila->api_base_url ?? null,
            ];
        }

        if ($fila === null || $fila->api_key_cipher === null || $fila->api_key_cipher === '') {
            return ['origen' => self::NINGUNA, 'ultimos' => null, 'puesta_por' => null,
                'puesta_el' => null, 'url' => $fila->api_base_url ?? null];
        }

        return [
            'origen' => self::BASE,
            'ultimos' => $fila->api_key_last4 === null ? null : (string) $fila->api_key_last4,
            'puesta_por' => $fila->autor === null ? null : (string) $fila->autor,
            'puesta_el' => $fila->credential_set_at === null ? null : (string) $fila->credential_set_at,
            'url' => $fila->api_base_url === null ? null : (string) $fila->api_base_url,
        ];
    }

    /**
     * Guarda una clave, cifrada y firmada.
     *
     * Las tres columnas de la firma van juntas o no van: `tg_fxs_credencial_firmada`
     * lo impone en la base, porque media firma parece que la pregunta «quién la
     * puso» tiene respuesta cuando no la tiene.
     */
    public static function guardar(string $fuente, string $clave, int $usuarioId): void
    {
        $clave = trim($clave);

        DB::table('fx_sources')->where('code', $fuente)->update([
            'api_key_cipher' => Crypt::encryptString($clave),
            'api_key_last4' => mb_substr($clave, -4),
            'credential_set_at' => now(),
            'credential_set_by_user_id' => $usuarioId,
            'updated_at' => now(),
        ]);
    }

    /** Borra la de la base. La del entorno no se toca desde aquí, y es correcto. */
    public static function olvidar(string $fuente): void
    {
        DB::table('fx_sources')->where('code', $fuente)->update([
            'api_key_cipher' => null,
            'api_key_last4' => null,
            'credential_set_at' => null,
            'credential_set_by_user_id' => null,
            'updated_at' => now(),
        ]);
    }

    public static function hay(string $fuente): bool
    {
        return self::clave($fuente) !== null;
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
