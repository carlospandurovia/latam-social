<?php

declare(strict_types=1);

namespace App\Shared\Texto;

use InvalidArgumentException;

/**
 * El importe en letras (9.9d).
 *
 * ### Por qué hace falta
 *
 * El XML de un comprobante electrónico peruano lleva obligatoriamente una
 * leyenda `1000` con **el total escrito con palabras**. No es decorativo: es
 * cómo se ha comprobado desde siempre que nadie ha corrido una coma, y sigue
 * siendo obligatorio.
 *
 * ### Por qué está en `Shared` y no en el adaptador peruano
 *
 * Porque **no es peruano**. Colombia, México, Ecuador, Chile y España piden lo
 * mismo con otras palabras para la moneda, y el algoritmo —partir en grupos de
 * tres y nombrarlos— es el mismo en todos. Lo único que cambia por país es cómo
 * se llama la moneda, y eso entra por parámetro.
 *
 * ### Lo que NO hace
 *
 * No redondea. Recibe el importe ya cuadrado por quien sabe cuadrarlo —la base,
 * con `ck_invoice_math`— y lo escribe. Un conversor que redondea por su cuenta
 * es un sitio más donde el total puede cambiar de valor.
 */
final class Letras
{
    private const UNIDADES = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE',
        'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS',
        'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE'];

    private const DECENAS = ['', '', 'VEINTI', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA',
        'SETENTA', 'OCHENTA', 'NOVENTA'];

    private const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS',
        'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    /**
     * Cómo se llama cada moneda en la leyenda. El día que entre otra es una
     * fila más, y mientras tanto se escribe su código —«CON 00/100 ARS»—, que
     * es feo pero no es falso.
     *
     * @var array<string, string>
     */
    private const MONEDAS = [
        'PEN' => 'SOLES',
        'USD' => 'DOLARES AMERICANOS',
        'EUR' => 'EUROS',
        'COP' => 'PESOS COLOMBIANOS',
        'MXN' => 'PESOS MEXICANOS',
        'CLP' => 'PESOS CHILENOS',
    ];

    /**
     * «MIL CIENTO OCHENTA CON 00/100 SOLES».
     *
     * El importe entra como **texto** y no como `float` a propósito: viene de un
     * `DECIMAL(18,4)` y convertirlo a coma flotante por el camino es meter un
     * error de redondeo justo en el dato que la leyenda existe para proteger.
     */
    public static function importe(string $monto, string $moneda): string
    {
        if (!preg_match('/^-?\d+(\.\d+)?$/', $monto)) {
            throw new InvalidArgumentException('Un importe se escribe con cifras: «'.$monto.'».');
        }

        $negativo = str_starts_with($monto, '-');
        $monto = ltrim($monto, '-');

        [$enteros, $centimos] = self::partir($monto);

        if ($enteros > 999_999_999) {
            throw new InvalidArgumentException('Importe fuera de lo que esta leyenda sabe escribir.');
        }

        $nombre = self::MONEDAS[strtoupper($moneda)] ?? strtoupper($moneda);

        return sprintf(
            '%s%s CON %02d/100 %s',
            $negativo ? 'MENOS ' : '',
            $enteros === 0 ? 'CERO' : self::numero($enteros),
            $centimos,
            $nombre,
        );
    }

    /** ¿Sabe esta clase nombrar esa moneda, o dirá su código? */
    public static function conoce(string $moneda): bool
    {
        return isset(self::MONEDAS[strtoupper($moneda)]);
    }

    /**
     * Los céntimos se cortan, no se redondean.
     *
     * `1180.999` se escribe «CON 99/100» y no «CON 00/100» del siguiente sol:
     * la leyenda tiene que decir lo que dice el importe, y si el importe trae un
     * tercer decimal el problema está antes, en quien lo calculó.
     *
     * @return array{0: int, 1: int}
     */
    private static function partir(string $monto): array
    {
        $punto = strpos($monto, '.');

        if ($punto === false) {
            return [(int) $monto, 0];
        }

        $decimales = substr($monto, $punto + 1).'00';

        return [(int) substr($monto, 0, $punto), (int) substr($decimales, 0, 2)];
    }

    private static function numero(int $n): string
    {
        if ($n >= 1_000_000) {
            $millones = intdiv($n, 1_000_000);
            $resto = $n % 1_000_000;

            return trim(
                ($millones === 1 ? 'UN MILLON' : self::numero($millones).' MILLONES')
                .($resto > 0 ? ' '.self::numero($resto) : ''),
            );
        }

        if ($n >= 1000) {
            $miles = intdiv($n, 1000);
            $resto = $n % 1000;

            return trim(
                ($miles === 1 ? 'MIL' : self::centenas($miles).' MIL')
                .($resto > 0 ? ' '.self::centenas($resto) : ''),
            );
        }

        return self::centenas($n);
    }

    private static function centenas(int $n): string
    {
        // «CIEN» a secas; «CIENTO UNO» en cuanto le sigue algo. Es la excepcion
        // que todo el mundo se come al escribir esto a mano.
        if ($n === 100) {
            return 'CIEN';
        }

        $c = intdiv($n, 100);
        $resto = $n % 100;

        return trim(self::CENTENAS[$c].' '.self::decenas($resto));
    }

    private static function decenas(int $n): string
    {
        if ($n <= 20) {
            return self::UNIDADES[$n];
        }

        $d = intdiv($n, 10);
        $u = $n % 10;

        if ($u === 0) {
            return self::DECENAS[$d];
        }

        // «VEINTIUNO» va junto; de treinta en adelante, «TREINTA Y UNO».
        return $d === 2
            ? self::DECENAS[2].self::UNIDADES[$u]
            : self::DECENAS[$d].' Y '.self::UNIDADES[$u];
    }
}
