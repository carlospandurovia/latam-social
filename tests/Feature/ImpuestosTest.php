<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Impuestos;
use App\Shared\Auth\Permisos;
use App\Shared\Database\Vigencia;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Cuánto era el impuesto, y cuándo (iteración 9.9a).
 *
 * ### Lo que fija
 *
 * Que **la tasa se pregunta por una fecha**. Es la única forma de que subir el
 * IGV mañana no reescriba el impuesto de las facturas de ayer, y es la misma
 * disciplina de `T-73`: preguntar «¿cuál es?» en vez de «¿cuál era?» produce
 * respuestas correctas hasta el día en que algo cambia, y entonces produce
 * facturas mal calculadas hacia atrás.
 */
final class ImpuestosTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $peru;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->peru = (int) DB::table('countries')->where('iso2', 'PE')->value('id');
    }

    // ------------------------------------------------------------- la lectura

    /** La semilla deja el IGV puesto: sin él, el impuesto saldría en cero. */
    public function test_la_instalacion_arranca_con_el_igv(): void
    {
        $tasa = Impuestos::vigente($this->peru);

        $this->assertNotNull($tasa);
        $this->assertSame('IGV', $tasa->code);
        $this->assertSame('18.0000', $tasa->rate);
    }

    /**
     * **La que más importa.** Cada fecha tiene su tasa, y son distintas.
     *
     * Se publica una tasa nueva y se comprueba que la de ayer sigue siendo la de
     * ayer. Sin esto, «configurable» significaría «reescribible».
     */
    public function test_cada_fecha_tiene_la_tasa_que_regia_entonces(): void
    {
        $antes = (string) Impuestos::vigente($this->peru)->valid_from;
        $cambio = Vigencia::masDias($antes, 200);

        Impuestos::publicar([
            'country_id' => $this->peru,
            'code' => 'IGV',
            'name' => 'Impuesto General a las Ventas',
            'rate' => '20',
            'valid_from' => $cambio,
        ]);

        $vispera = Vigencia::cerrarElDiaAntesDe($cambio);

        $this->assertSame('18.0000', Impuestos::vigente($this->peru, 'IGV', $vispera)->rate);
        $this->assertSame('20.0000', Impuestos::vigente($this->peru, 'IGV', $cambio)->rate);
    }

    /** Un país sin tasa declarada devuelve `null`, no una excepción. */
    public function test_un_pais_sin_tasa_no_revienta(): void
    {
        $chile = (int) DB::table('countries')->where('iso2', 'CL')->value('id');

        $this->assertNull(Impuestos::vigente($chile));
    }

    /** Y antes de que existiera ninguna tasa, tampoco hay respuesta. */
    public function test_antes_de_la_primera_tasa_no_hay_tasa(): void
    {
        $desde = (string) Impuestos::vigente($this->peru)->valid_from;

        $this->assertNull(Impuestos::vigente(
            $this->peru, 'IGV', Vigencia::cerrarElDiaAntesDe($desde),
        ));
    }

    // ----------------------------------------------------------- la publicación

    /** Publicar cierra la anterior **el día antes**, no el mismo día. */
    public function test_publicar_cierra_la_anterior_el_dia_antes(): void
    {
        $anterior = Impuestos::vigente($this->peru);
        $cambio = Vigencia::masDias((string) $anterior->valid_from, 100);

        Impuestos::publicar([
            'country_id' => $this->peru, 'code' => 'IGV',
            'name' => 'Impuesto General a las Ventas', 'rate' => '19',
            'valid_from' => $cambio,
        ]);

        $this->assertSame(
            Vigencia::cerrarElDiaAntesDe($cambio),
            (string) DB::table('tax_rates')->where('id', $anterior->id)->value('valid_to'),
        );
    }

    /** No se puede publicar una tasa que empiece antes de la que está abierta. */
    public function test_no_se_puede_publicar_hacia_atras(): void
    {
        $anterior = Impuestos::vigente($this->peru);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya emitido|despues/');

        Impuestos::publicar([
            'country_id' => $this->peru, 'code' => 'IGV',
            'name' => 'Impuesto General a las Ventas', 'rate' => '19',
            'valid_from' => Vigencia::cerrarElDiaAntesDe((string) $anterior->valid_from),
        ]);
    }

    public function test_publicar_queda_en_la_bitacora(): void
    {
        $desde = Vigencia::masDias((string) Impuestos::vigente($this->peru)->valid_from, 30);

        $id = Impuestos::publicar([
            'country_id' => $this->peru, 'code' => 'IGV',
            'name' => 'Impuesto General a las Ventas', 'rate' => '19',
            'valid_from' => $desde,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tax_rate.published',
            'entity_type' => 'tax_rate',
            'entity_id' => $id,
        ]);
    }

    /** Una tasa no se borra: explica el impuesto de lo ya emitido. */
    public function test_una_tasa_no_se_borra(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/no se borra/');

        DB::table('tax_rates')->delete();
    }

    // ------------------------------------------------------------- la pantalla

    public function test_la_pantalla_pide_permiso(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('impuestos.index'))->assertStatus(403);
    }

    public function test_finanzas_ve_las_tasas_y_publica(): void
    {
        $desde = Vigencia::masDias((string) Impuestos::vigente($this->peru)->valid_from, 60);

        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('impuestos.index'))->assertOk()->assertSee('IGV');

        $this->actingAs($this->usuarioCon('finance'))
            ->post(route('impuestos.publicar'), [
                'country_id' => $this->peru, 'code' => 'igv',
                'name' => 'Impuesto General a las Ventas', 'rate' => '19',
                'valid_from' => $desde,
            ])
            ->assertRedirect(route('impuestos.index'));

        // En mayúsculas aunque se teclee en minúsculas: `ck_tax_code` lo exige y
        // el servicio lo normaliza antes de que la base tenga que decirlo.
        $this->assertSame('19.0000', Impuestos::vigente($this->peru, 'IGV', $desde)->rate);
    }

    // ---------------------------------------------------------------- avisos

    /** Una sociedad activa en un país sin tasa es rojo. */
    public function test_un_pais_con_sociedad_y_sin_tasa_sale_en_rojo(): void
    {
        DB::table('tax_rates')->update(['valid_to' => Vigencia::cerrarElDiaAntesDe(
            Vigencia::fecha(now()->toDateString()),
        )]);

        $textos = implode(' ', array_map(
            fn ($a): string => $a->texto,
            array_filter(Impuestos::avisos(), fn ($a): bool => $a->nivel === 'rojo'),
        ));

        $this->assertStringContainsString('Perú', $textos);
        $this->assertStringContainsString('en cero', $textos);
    }

    /** El área está en el panel de configuración, en el grupo fiscal. */
    public function test_el_panel_de_configuracion_la_incluye(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('configuracion'))
            ->assertOk()
            ->assertSee('Impuestos');
    }
}
