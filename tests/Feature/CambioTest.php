<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Cambio;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tipos de cambio (iteración 9.1).
 *
 * Tres cosas, y las tres son de dinero:
 *
 * 1. **Nadie elige qué fuente manda al convertir**: se declara antes, con
 *    periodos, y hay como mucho una por par y fecha.
 * 2. **La fecha que se guarda es la de la TASA**, no la de la operación. Un
 *    domingo se convierte con la tasa del viernes, y lo que queda escrito es el
 *    viernes.
 * 3. **Una tasa publicada no se reescribe** (`BR-FIN-009`).
 */
final class CambioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CimientosSeeder::class);

        foreach (['USD', 'PEN', 'COP'] as $codigo) {
            $this->assertDatabaseHas('currencies', ['code' => $codigo]);
        }

        // Desde `9.2` el catalogo ya trae declarado quien publica USD->PEN, asi
        // que las pruebas de «no hay fuente» usan OTRO par. Se afirma aqui: si
        // esta fila desapareciera, media suite probaria otra cosa creyendo que
        // prueba lo mismo.
        $this->assertDatabaseHas('fx_official_sources', [
            'base_currency_code' => 'USD', 'quote_currency_code' => 'PEN',
            'source_code' => 'sunat', 'valid_to' => null,
        ]);
        $this->assertDatabaseMissing('fx_official_sources', ['base_currency_code' => 'COP']);
    }

    public function test_sin_fuente_declarada_no_se_convierte_y_se_dice_por_que(): void
    {
        // COP a proposito: SUNAT no lo publica y nadie ha declarado fuente,
        // que es exactamente el caso de un creador colombiano.
        $tasa = Cambio::tasa('COP', 'PEN', '2026-08-20', Cambio::VENTA);

        $this->assertSame(Cambio::SIN_FUENTE, $tasa->resultado);
        $this->assertNull($tasa->tasa);
        $this->assertStringContainsString('Nadie ha dicho que fuente', $tasa->explicacion);
    }

    /** Hay fuente y no ha publicado nada: es un «no» distinto y se cuenta distinto. */
    public function test_con_fuente_pero_sin_tasa_lo_dice_de_otra_manera(): void
    {
        $tasa = Cambio::tasa('USD', 'PEN', '2026-08-20', Cambio::VENTA);

        $this->assertSame(Cambio::SIN_TASA, $tasa->resultado);
        $this->assertSame('sunat', $tasa->fuente);
    }

    public function test_la_misma_moneda_no_se_convierte(): void
    {
        $tasa = Cambio::tasa('PEN', 'PEN', '2026-08-20', Cambio::VENTA);

        $this->assertTrue($tasa->hay());
        $this->assertSame('1.00000000', $tasa->tasa);
    }

    /**
     * **La prueba de `BR-FIN-009`.**
     *
     * El 16 de agosto de 2026 es domingo. Se publica la tasa del viernes 14 y se
     * pide convertir el domingo: tiene que usarla, y tiene que guardar **el 14**.
     * Guardar el 16 haría que el histórico afirmara que ese domingo hubo una
     * tasa que nunca existió.
     */
    public function test_un_domingo_usa_la_tasa_del_viernes_y_guarda_la_fecha_del_viernes(): void
    {
        Cambio::anotar('USD', 'PEN', '2026-08-14', '3.74200000', 'sunat', Cambio::VENTA);

        $tasa = Cambio::tasa('USD', 'PEN', '2026-08-16', Cambio::VENTA);

        $this->assertTrue($tasa->hay());
        $this->assertSame('2026-08-14', $tasa->fecha, 'la fecha guardada es la de la TASA');
        $this->assertStringContainsString('ultima publicada antes del 2026-08-16', $tasa->explicacion);
    }

    /** Y si hay tasa de ese mismo día, no va a buscar la anterior. */
    public function test_con_tasa_del_dia_se_usa_la_del_dia(): void
    {
        Cambio::anotar('USD', 'PEN', '2026-08-14', '3.74200000', 'sunat', Cambio::VENTA);
        Cambio::anotar('USD', 'PEN', '2026-08-16', '3.75100000', 'sunat', Cambio::VENTA);

        $tasa = Cambio::tasa('USD', 'PEN', '2026-08-16', Cambio::VENTA);

        $this->assertSame('2026-08-16', $tasa->fecha);
        $this->assertSame('3.75100000', $tasa->tasa);
    }

    /**
     * Una tasa de hace tres semanas no es un feriado: es que dejaron de llegar.
     *
     * Sin este corte, una tabla congelada se ve igual que una sana — y se
     * descubre el día de la liquidación, convirtiendo con una tasa de otro mes.
     */
    public function test_una_tasa_demasiado_vieja_no_se_usa_y_lo_dice(): void
    {
        Cambio::anotar('USD', 'PEN', '2026-07-20', '3.74200000', 'sunat', Cambio::VENTA);

        $tasa = Cambio::tasa('USD', 'PEN', '2026-08-20', Cambio::VENTA);

        $this->assertSame(Cambio::RANCIA, $tasa->resultado);
        $this->assertNull($tasa->tasa);
        $this->assertStringContainsString('dejaron de llegar', $tasa->explicacion);
        // La fecha SI viaja, aunque no se convierta: es lo que hay que mirar.
        $this->assertSame('2026-07-20', $tasa->fecha);
    }

    /** Compra y venta del mismo día son dos filas, y no se confunden. */
    public function test_compra_y_venta_conviven_el_mismo_dia(): void
    {
        Cambio::anotar('USD', 'PEN', '2026-08-20', '3.73500000', 'sunat', Cambio::COMPRA);
        Cambio::anotar('USD', 'PEN', '2026-08-20', '3.74200000', 'sunat', Cambio::VENTA);

        $this->assertSame('3.73500000', Cambio::tasa('USD', 'PEN', '2026-08-20', Cambio::COMPRA)->tasa);
        $this->assertSame('3.74200000', Cambio::tasa('USD', 'PEN', '2026-08-20', Cambio::VENTA)->tasa);
    }

    /** `BR-FIN-004`: la conversión devuelve las siete cosas, no un número. */
    public function test_convertir_devuelve_todo_lo_que_hay_que_guardar(): void
    {
        Cambio::anotar('USD', 'PEN', '2026-08-14', '3.74200000', 'sunat', Cambio::VENTA);

        $c = Cambio::convertir('1500.0000', 'USD', 'PEN', '2026-08-16', Cambio::VENTA);

        $this->assertSame('1500.0000', $c['monto_origen']);
        $this->assertSame('USD', $c['moneda_origen']);
        $this->assertSame('5613.00', $c['monto_destino']);
        $this->assertSame('PEN', $c['moneda_destino']);
        $this->assertSame('3.74200000', $c['tasa_valor']);
        $this->assertSame('2026-08-14', $c['tasa_fecha']);
        $this->assertSame('sunat', $c['tasa_fuente']);
        $this->assertSame(Cambio::VENTA, $c['tasa_lado']);
    }

    /** Sin tasa no hay importe convertido, y **no es cero**. */
    public function test_sin_tasa_el_monto_destino_es_nulo_y_no_cero(): void
    {
        $c = Cambio::convertir('1500.0000', 'USD', 'PEN', '2026-08-16', Cambio::VENTA);

        $this->assertNull($c['monto_destino']);
        $this->assertFalse($c['tasa']->hay());
    }

    /** Relevar una fuente cierra la anterior el día ANTES, no el mismo día. */
    public function test_relevar_la_fuente_oficial_cierra_la_anterior_el_dia_antes(): void
    {
        Cambio::declararOficial('USD', 'PEN', 'manual', '2026-06-01');

        $this->assertSame('sunat', Cambio::fuenteOficial('USD', 'PEN', '2026-05-31')->source_code);
        $this->assertSame('manual', Cambio::fuenteOficial('USD', 'PEN', '2026-06-01')->source_code);

        $this->assertDatabaseHas('fx_official_sources', [
            'source_code' => 'sunat', 'valid_to' => '2026-05-31',
        ]);
    }

    /**
     * El histórico se sigue explicando con la fuente de entonces.
     *
     * Es el motivo entero de que la fuente oficial tenga periodos en vez de ser
     * una columna: convertir un importe de marzo tiene que resolver con quien
     * mandaba en marzo.
     */
    public function test_una_conversion_pasada_usa_la_fuente_que_mandaba_entonces(): void
    {
        Cambio::anotar('USD', 'PEN', '2026-03-10', '3.70000000', 'sunat', Cambio::VENTA);
        Cambio::declararOficial('USD', 'PEN', 'manual', '2026-06-01');
        Cambio::anotar('USD', 'PEN', '2026-03-10', '3.99000000', 'manual', Cambio::VENTA);

        $tasa = Cambio::tasa('USD', 'PEN', '2026-03-10', Cambio::VENTA);

        $this->assertSame('3.70000000', $tasa->tasa, 'en marzo mandaba sunat');
        $this->assertSame('sunat', $tasa->fuente);
    }

    /**
     * Relevar «desde el mismo día» no se hace moviendo fechas: se dice.
     *
     * Cerrar la anterior el día antes le pondría un `valid_to` anterior a su
     * `valid_from` y saldría un `45000` feo de `ck_fos_dates`. Lo que ese caso
     * significa es que la fuente anterior **no llegó a mandar ningún día**, y
     * eso no lo arregla recortar una fecha. Es `Cobertura::noCerrablesEn()`
     * otra vez.
     */
    public function test_relevar_desde_la_misma_fecha_se_contesta_con_palabras(): void
    {
        $veto = Cambio::vetoParaDeclarar('USD', 'PEN', '2026-01-01');

        $this->assertNotNull($veto);
        $this->assertStringContainsString('exige una fecha posterior', $veto);

        $this->expectException(\RuntimeException::class);
        Cambio::declararOficial('USD', 'PEN', 'manual', '2026-01-01');
    }

    /** `tg_fx_inmutable`: una tasa publicada no se reescribe ni por SQL. */
    public function test_una_tasa_publicada_no_se_modifica_ni_por_sql(): void
    {
        Cambio::anotar('USD', 'PEN', '2026-08-14', '3.74200000', 'sunat', Cambio::VENTA);

        $this->expectException(QueryException::class);

        DB::table('exchange_rates')->where('rate_date', '2026-08-14')->update(['rate' => '9.99999999']);
    }

    /** Repetir la carga de un día no revienta: el cron repite y eso es normal. */
    public function test_anotar_dos_veces_el_mismo_dia_no_falla_y_lo_dice(): void
    {
        $this->assertTrue(Cambio::anotar('USD', 'PEN', '2026-08-14', '3.74200000', 'sunat', Cambio::VENTA));
        $this->assertFalse(Cambio::anotar('USD', 'PEN', '2026-08-14', '3.74200000', 'sunat', Cambio::VENTA));

        $this->assertSame(1, DB::table('exchange_rates')->where('rate_date', '2026-08-14')->count());
    }
}
