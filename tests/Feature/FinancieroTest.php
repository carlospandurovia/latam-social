<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\FiltrosDeResumen;
use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Resumen;
use App\Shared\Auth\Permisos;
use Carbon\CarbonImmutable;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * `D-8`: el bloque financiero.
 *
 * ### La que de verdad importa
 *
 * `test_una_factura_con_dos_cobros_parciales_no_se_cuenta_dos_veces`. Es el
 * doble conteo del encargo en el sitio donde cuesta dinero: con un `JOIN` a
 * `payments` en vez de una subconsulta, una factura con tres cobros se sumaría
 * tres veces en «por cobrar» y el número saldría **negativo** sin que nadie
 * supiera por qué.
 *
 * Las sumas se pueden afirmar desde `T-115`: `facturaEmitida()` fabrica la
 * cadena entera del correlativo —tipo del país, serie, número del libro— que
 * antes hacía falta escribir a mano en cada prueba.
 */
final class FinancieroTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
        Queue::fake();
    }

    public function test_con_la_base_vacia_ningun_bloque_revienta_ni_se_inventa_un_cero(): void
    {
        $resumen = Resumen::financiero(FiltrosDeResumen::porDefecto());

        self::assertCount(4, $resumen['bloques']);
        self::assertSame([], $resumen['monedas'], 'Sin importes no hay monedas que enseñar.');

        foreach ($resumen['bloques'] as $bloque) {
            // Vacío, no cero. La pantalla pinta un guion: «no hay importes» y
            // «hay cero soles» no son lo mismo, y un 0,00 inventado se lee como
            // un dato medido.
            self::assertSame([], $bloque['lineas'], "«{$bloque['titulo']}» debería venir vacío.");
        }
    }

    public function test_con_un_cliente_elegido_lo_pagado_a_creadores_no_finge_estar_recortado(): void
    {
        // Un pago a un creador no cuelga de ningún cliente. Con un cliente
        // puesto, la respuesta honrada es «nada», no el total de todos.
        $conCliente = FiltrosDeResumen::desdePeticion(
            Request::create('/', 'GET', ['cliente' => '999999']),
        );

        self::assertSame(999_999, $conCliente->clienteId, 'El filtro tiene que haber calado.');

        $bloques = [];
        foreach (Resumen::financiero($conCliente)['bloques'] as $bloque) {
            $bloques[$bloque['clave']] = $bloque;
        }

        self::assertSame([], $bloques['pagado']['lineas']);
    }

    // ---------------------------------------------------------- la aritmética

    public function test_lo_facturado_suma_por_moneda_y_deja_fuera_los_borradores(): void
    {
        $clienteId = $this->clienteDePrueba();

        $this->facturaEmitida($clienteId);
        $this->facturaEmitida($clienteId);
        // Un borrador NO es dinero facturado: todavía se puede romper el papel.
        $this->facturaEmitida($clienteId, [
            'status' => 'draft', 'series' => null, 'number' => null, 'document_number_id' => null,
        ]);

        self::assertSame(
            [['moneda' => 'PEN', 'importe' => 236.0]],
            $this->bloque('facturado')['lineas'],
            'Dos facturas de 118 son 236. El borrador no cuenta.',
        );
    }

    public function test_una_factura_con_dos_cobros_parciales_no_se_cuenta_dos_veces(): void
    {
        $clienteId = $this->clienteDePrueba();
        $facturaId = $this->facturaEmitida($clienteId, ['status' => 'partially_paid']);

        $this->cobroDe($facturaId, 50);
        $this->cobroDe($facturaId, 18);

        // 118 − 68 = 50. Con un `JOIN` en vez de subconsulta, la factura se
        // sumaría DOS veces --una por cobro-- y saldría 236 − 68 = 168.
        self::assertSame(
            [['moneda' => 'PEN', 'importe' => 50.0]],
            $this->bloque('por_cobrar')['lineas'],
        );

        self::assertSame(
            [['moneda' => 'PEN', 'importe' => 68.0]],
            $this->bloque('cobrado')['lineas'],
        );
    }

    public function test_las_monedas_no_se_suman_entre_ellas(): void
    {
        $clienteId = $this->clienteDePrueba();

        $this->facturaEmitida($clienteId);
        $this->facturaEmitida($clienteId, ['currency_code' => 'USD']);

        $resumen = Resumen::financiero(FiltrosDeResumen::porDefecto());

        self::assertSame(['PEN', 'USD'], $resumen['monedas']);
        self::assertCount(2, $this->bloque('facturado')['lineas'],
            'Dos monedas son dos líneas, nunca un total.');
    }

    public function test_una_factura_vieja_sin_pagar_sigue_saliendo_en_por_cobrar(): void
    {
        $clienteId = $this->clienteDePrueba();

        // Emitida hace cuatro meses: fuera del periodo por omisión.
        $this->facturaEmitida($clienteId, [
            'issue_date' => CarbonImmutable::now()->subDays(120)->toDateString(),
        ]);

        self::assertSame([], $this->bloque('facturado')['lineas'],
            'No se facturó dentro del periodo.');
        self::assertSame(
            [['moneda' => 'PEN', 'importe' => 118.0]],
            $this->bloque('por_cobrar')['lineas'],
            'Pero sigue sin cobrarse HOY, que es justo lo que hay que ver (`DEC-339`).',
        );
    }

    public function test_por_cobrar_dice_que_no_se_recorta_por_periodo(): void
    {
        $bloques = [];
        foreach (Resumen::financiero(FiltrosDeResumen::porDefecto())['bloques'] as $bloque) {
            $bloques[$bloque['clave']] = $bloque;
        }

        // La nota es parte del indicador, no decoración: sin ella nadie sabe
        // por qué ese número no cambia al mover el periodo (`DEC-339`).
        self::assertStringContainsString('NO se recorta', $bloques['por_cobrar']['nota']);
        self::assertStringContainsString('a día de hoy', $bloques['por_cobrar']['nota']);
    }

    // -------------------------------------------------------------- permisos

    // ---------------------------------------------------------------- apoyo

    private function clienteDePrueba(): int
    {
        return (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_code' => 'FIN-01',
            'commercial_name' => 'Cliente de finanzas',
            'country_id' => (int) DB::table('countries')->where('iso2', 'PE')->value('id'),
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function bloque(string $clave): array
    {
        foreach (Resumen::financiero(FiltrosDeResumen::porDefecto())['bloques'] as $bloque) {
            if ($bloque['clave'] === $clave) {
                return $bloque;
            }
        }

        $this->fail("No hay ningún bloque financiero con la clave «{$clave}».");
    }

    public function test_quien_no_lleva_finanzas_no_recibe_el_bloque(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');

        // La premisa, comprobada y no supuesta: es la tercera vez en este
        // proyecto que una suposición sobre el reparto de permisos sale falsa.
        self::assertFalse($gestor->can('finance.view'));

        $html = (string) $this->actingAs($gestor)->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringNotContainsString('Pagado a creadores', $html);
    }

    public function test_finanzas_si_lo_recibe(): void
    {
        $finanzas = $this->usuarioCon('finance');

        self::assertTrue($finanzas->can('finance.view'));

        $html = (string) $this->actingAs($finanzas)->get('/backoffice/panel')->assertOk()->getContent();

        // La mitad positiva. Sin ella, la de arriba pasaría también con el
        // bloque roto para todo el mundo (`DEC-300`).
        self::assertStringContainsString('Pagado a creadores', $html);
        self::assertStringContainsString('Por cobrar hoy', $html);
        // Y la frase que impide leer mal las cifras.
        //
        // Hasta `D-11` era «no hay un total consolidado», porque no lo había.
        // `D-12` lo puso, así que la frase cambia PERO EL DEBER NO: quien mire
        // el bloque tiene que saber que el total es una CONVERSIÓN y que las
        // cifras por moneda siguen ahí sin tocar. Esta prueba se puso roja al
        // consolidar, que es exactamente su trabajo.
        self::assertStringContainsString('Totales consolidados en', $html);
        // Sin «cada» delante: la plantilla parte la linea justo ahi, y una
        // asercion que depende de donde envuelve el HTML se rompe el dia que
        // alguien reindenta un parrafo. Lo que importa es la frase, no el hueco.
        self::assertStringContainsString('moneda por separado', $html);
    }

    public function test_el_bloque_no_consulta_nada_cuando_no_hay_permiso(): void
    {
        // Que no se pinte no basta: la consulta no debe llegar a salir. Un
        // `@can` en la plantilla sobre datos ya traídos es una fuga esperando a
        // que alguien borre el `@can`.
        $gestor = $this->usuarioCon('campaign_manager');
        $consultas = 0;

        DB::listen(static function ($consulta) use (&$consultas): void {
            if (str_contains($consulta->sql, 'payouts')) {
                $consultas++;
            }
        });

        $this->actingAs($gestor)->get('/backoffice/panel')->assertOk();

        self::assertSame(0, $consultas, 'Se consultó `payouts` para alguien que no puede verlo.');
    }
}
