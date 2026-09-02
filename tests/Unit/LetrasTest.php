<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Texto\Letras;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El importe en letras (iteración 9.9d).
 *
 * El XML de un comprobante peruano lleva obligatoriamente el total escrito con
 * palabras. No es decorativo: es cómo se comprueba desde siempre que nadie ha
 * corrido una coma.
 */
final class LetrasTest extends TestCase
{
    /**
     * Las excepciones del castellano, que son donde falla quien lo escribe a
     * mano: «CIEN» a secas pero «CIENTO UNO»; «VEINTIUNO» junto pero «TREINTA Y
     * UNO» separado; «UN MILLON» y no «UNO MILLONES».
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function importes(): array
    {
        return [
            ['0.00', 'PEN', 'CERO CON 00/100 SOLES'],
            ['0.50', 'PEN', 'CERO CON 50/100 SOLES'],
            ['16.00', 'PEN', 'DIECISEIS CON 00/100 SOLES'],
            ['21.00', 'PEN', 'VEINTIUNO CON 00/100 SOLES'],
            ['31.00', 'PEN', 'TREINTA Y UNO CON 00/100 SOLES'],
            ['100.00', 'PEN', 'CIEN CON 00/100 SOLES'],
            ['101.00', 'PEN', 'CIENTO UNO CON 00/100 SOLES'],
            ['1000.00', 'PEN', 'MIL CON 00/100 SOLES'],
            ['1180.00', 'PEN', 'MIL CIENTO OCHENTA CON 00/100 SOLES'],
            ['1000000.00', 'PEN', 'UN MILLON CON 00/100 SOLES'],
            ['2000000.00', 'PEN', 'DOS MILLONES CON 00/100 SOLES'],
            ['115.45', 'EUR', 'CIENTO QUINCE CON 45/100 EUROS'],
            ['1000.00', 'USD', 'MIL CON 00/100 DOLARES AMERICANOS'],
        ];
    }

    #[DataProvider('importes')]
    public function test_escribe_el_importe(string $monto, string $moneda, string $espera): void
    {
        self::assertSame($espera, Letras::importe($monto, $moneda));
    }

    /**
     * Los céntimos se CORTAN, no se redondean.
     *
     * `1180.999` se escribe «CON 99/100» y no «CON 00/100» del siguiente sol:
     * la leyenda dice lo que dice el importe. Si viene un tercer decimal, el
     * problema está antes, en quien lo calculó.
     */
    public function test_los_centimos_se_cortan_y_no_se_redondean(): void
    {
        self::assertSame('MIL CIENTO OCHENTA CON 99/100 SOLES', Letras::importe('1180.999', 'PEN'));
    }

    /**
     * Una moneda que esta clase no sabe nombrar escribe su código.
     *
     * Feo, pero no falso — y `conoce()` permite avisar antes en vez de emitir
     * un comprobante que dice «CON 00/100 ARS».
     */
    public function test_una_moneda_desconocida_dice_su_codigo_y_se_puede_preguntar(): void
    {
        self::assertFalse(Letras::conoce('ARS'));
        self::assertTrue(Letras::conoce('pen'));
        self::assertSame('DOSCIENTOS CON 00/100 ARS', Letras::importe('200.00', 'ARS'));
    }

    /** Lo que no es una cifra no se escribe con palabras: se rechaza. */
    public function test_lo_que_no_es_una_cifra_se_rechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Letras::importe('mil doscientos', 'PEN');
    }
}
