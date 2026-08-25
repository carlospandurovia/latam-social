<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Database\Vigencia;
use PHPUnit\Framework\TestCase;

/**
 * `Vigencia` decide con qué fecha se cierra un periodo, y de eso salen la tarifa
 * que se le paga a un creador, la retención que se le practica, el RUC que se
 * imprime en una factura y qué sociedad la emite.
 *
 * El error de un día ha aparecido en **seis** sitios de este proyecto. Estas
 * pruebas son baratas y no tocan la base: si alguna se pone roja, hay seis
 * pantallas equivocándose a la vez.
 */
final class VigenciaTest extends TestCase
{
    public function test_se_cierra_el_dia_antes_no_el_mismo_dia(): void
    {
        $this->assertSame('2026-05-31', Vigencia::cerrarElDiaAntesDe('2026-06-01'));
    }

    /** Cruzando mes, año y un 29 de febrero bisiesto, que es donde falla restar «un día» a mano. */
    public function test_el_dia_antes_cruza_mes_anio_y_bisiesto(): void
    {
        $this->assertSame('2025-12-31', Vigencia::cerrarElDiaAntesDe('2026-01-01'));
        $this->assertSame('2028-02-29', Vigencia::cerrarElDiaAntesDe('2028-03-01'));
        $this->assertSame('2026-02-28', Vigencia::cerrarElDiaAntesDe('2026-03-01'));
    }

    /** El primer día DESCUBIERTO es el siguiente al último cubierto, no el último. */
    public function test_el_dia_despues_es_el_primero_sin_cubrir(): void
    {
        $this->assertSame('2026-07-01', Vigencia::elDiaDespuesDe('2026-06-30'));
        $this->assertSame('2027-01-01', Vigencia::elDiaDespuesDe('2026-12-31'));
    }

    public function test_un_periodo_releva_al_que_empezo_antes(): void
    {
        $this->assertTrue(Vigencia::puedeRelevar('2026-06-01', '2026-01-01'));
    }

    /** El mismo día no releva: el anterior no llegó a estar vigente. */
    public function test_el_mismo_dia_no_releva(): void
    {
        $this->assertFalse(Vigencia::puedeRelevar('2026-01-01', '2026-01-01'));
        $this->assertFalse(Vigencia::puedeRelevar('2025-12-31', '2026-01-01'));
    }

    /**
     * **El defecto que encontró la revisión.**
     *
     * `'2026-2-1' > '2026-11-01'` es CIERTO comparando cadenas: carácter a
     * carácter, el `2` gana al `1`. Con la comparación de cadenas esta guarda
     * decía que sí se puede relevar, se cerraba el anterior el `2026-01-31`
     * —antes de su propio `valid_from`— y salía un `45000` crudo en la cara del
     * operador, que es exactamente lo que la guarda existe para evitar.
     *
     * La regla `date` de Laravel acepta `2026-2-1`. Los formularios mandan
     * `Y-m-d`; una orden de consola o una importación, no.
     */
    public function test_una_fecha_sin_ceros_se_compara_como_fecha_y_no_como_cadena(): void
    {
        $this->assertFalse(Vigencia::puedeRelevar('2026-2-1', '2026-11-01'));
        $this->assertTrue(Vigencia::puedeRelevar('2026-12-1', '2026-9-30'));
    }

    public function test_normalizar_deja_siempre_un_y_m_d(): void
    {
        $this->assertSame('2026-02-01', Vigencia::fecha('2026-2-1'));
        $this->assertSame('2026-02-01', Vigencia::fecha('2026-02-01'));
    }
}
