<?php

declare(strict_types=1);

namespace App\Shared\Crypto;

use Illuminate\Support\Facades\Crypt;

/**
 * Las tres formas en las que un número de cuenta entra en la base.
 *
 * Nunca en claro. La regla del proyecto es literal: *«no almacenar secretos en
 * texto plano en BD cuando exista alternativa segura»*. Un número de cuenta no
 * es una contraseña —hay que poder recuperarlo para pagar— así que va cifrado
 * y reversible, no hasheado.
 *
 * Pero cifrado no basta: hay que poder **comparar** dos cuentas sin descifrar
 * ninguna, para detectar la misma cuenta repetida (`DEC-065`). De ahí la huella.
 *
 * ### Por qué HMAC y no un SHA-256 pelado
 *
 * El espacio de números de cuenta es pequeño y muy estructurado: un CCI
 * peruano son 20 dígitos con banco y oficina en posiciones fijas. Un SHA-256 a
 * secas de eso se rompe por fuerza bruta en un rato con una tabla, y entonces
 * la huella —que está en un índice, sin cifrar— sería el número de cuenta.
 * Con HMAC hace falta además la clave de la aplicación.
 *
 * **Limitación conocida:** rotar `APP_KEY` invalida todas las huellas y la
 * detección de cuentas repetidas deja de funcionar sobre las filas viejas. Los
 * números siguen siendo recuperables (`Crypt` mantiene las claves anteriores en
 * `APP_PREVIOUS_KEYS`), pero las huellas habría que recalcularlas. No es un
 * problema hoy y sí lo será el día de la primera rotación: queda escrito aquí
 * para que ese día no sea una sorpresa. Ver `T-11`.
 */
final class CuentaBancaria
{
    /**
     * Deja solo lo que identifica a la cuenta.
     *
     * Sin esto, `0021-2345-6789` y `002123456789` darían huellas distintas y la
     * detección de duplicados se saltaría con un guion.
     */
    public static function normalizar(string $numero): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $numero));
    }

    public static function cifrar(string $numero): string
    {
        return Crypt::encryptString(self::normalizar($numero));
    }

    public static function descifrar(string $cifrado): string
    {
        return Crypt::decryptString($cifrado);
    }

    public static function huella(string $numero): string
    {
        return hash_hmac('sha256', self::normalizar($numero), (string) config('app.key'));
    }

    /**
     * Los cuatro últimos dígitos y nada más.
     *
     * `ck_cpm_masked_digits` no admite una quinta cifra: la máscara se enseña en
     * pantallas y en la bitácora, y `H-10` demostró que sin esa restricción
     * cabía el número entero en claro.
     *
     * Cuatro asteriscos fijos, no uno por dígito oculto: un IBAN largo daría
     * una máscara de más de 30 caracteres y `ck_cpm_masked` la rechazaría. Y de
     * paso, el largo del número tampoco se filtra.
     */
    public static function mascara(string $numero): string
    {
        return '****'.substr(self::normalizar($numero), -4);
    }
}
