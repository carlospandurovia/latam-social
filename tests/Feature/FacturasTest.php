<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Correlativos;
use App\Modules\Finance\Services\Facturas;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * La factura que sale de una campaña (iteración 9.9b).
 *
 * ### Lo que fija
 *
 * Tres cosas, y las tres son la diferencia entre un comprobante defendible y uno
 * que no se puede explicar:
 *
 * 1. **El borrador no gasta correlativo.** El número se pide al emitir, así que
 *    descartar un borrador no deja un hueco en la numeración ante SUNAT.
 * 2. **El régimen se deduce comparando los dos países, sin nombrar ninguno.** La
 *    misma línea de código sirve para Perú, Colombia y España (`DEC-190`).
 * 3. **Lo emitido no se corrige.** El motor lo impone, no la capa de aplicación.
 */
final class FacturasTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $paisPE;

    private int $clienteId;

    private int $marcaId;

    private int $sociedadId;

    private int $serieId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');

        $this->clienteId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'commercial_name' => 'ACME',
            'client_code' => 'ACME-01', 'country_id' => $this->paisPE, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->marcaId = (int) DB::table('client_brands')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_organization_id' => $this->clienteId,
            'name' => 'Marca ACME', 'slug' => 'marca-acme', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->perfilFiscal($this->paisPE);

        $this->sociedadId = $this->entidadLegal(['country_id' => $this->paisPE]);
        $this->serieId = $this->serieDe($this->sociedadId);
    }

    // ------------------------------------------------------------ el borrador

    /** **La que más importa del borrador.** No gasta número. */
    public function test_el_borrador_no_gasta_correlativo(): void
    {
        $antes = (int) DB::table('document_series')->where('id', $this->serieId)->value('next_number');

        $uuid = Facturas::borrador($this->campanaFacturable());

        $factura = Facturas::ver($uuid);
        $this->assertNotNull($factura);
        $this->assertNull($factura->series);
        $this->assertNull($factura->number);
        $this->assertNull($factura->document_number_id);
        $this->assertSame(
            $antes,
            (int) DB::table('document_series')->where('id', $this->serieId)->value('next_number'),
        );
    }

    /** Y por eso descartarlo no deja hueco: el contador no se movió. */
    public function test_descartar_un_borrador_no_deja_hueco(): void
    {
        $uuid = Facturas::borrador($this->campanaFacturable());
        Facturas::descartar($uuid);

        $this->assertNull(Facturas::ver($uuid));
        $this->assertSame(0, DB::table('document_numbers')->count());
    }

    /** El importe sale de la campaña, con el impuesto de venta del país. */
    public function test_el_borrador_calcula_el_impuesto_del_pais(): void
    {
        $uuid = Facturas::borrador($this->campanaFacturable(10000.0));
        $factura = Facturas::ver($uuid);

        $this->assertNotNull($factura);
        $this->assertSame('gravado', $factura->tax_regime);
        $this->assertSame('18.0000', $factura->tax_rate_snapshot);
        $this->assertSame('10000.0000', $factura->subtotal_amount);
        $this->assertSame('1800.0000', $factura->tax_amount);
        $this->assertSame('11800.0000', $factura->total_amount);
    }

    /**
     * **La regla de `DEC-047`, sin ningún país escrito.**
     *
     * Al cliente de fuera la operación es exportación de servicios y va sin
     * impuesto. Lo que se compara son los dos países congelados en el documento,
     * no la palabra «Perú».
     */
    public function test_al_cliente_de_fuera_se_le_factura_sin_impuesto(): void
    {
        $colombia = (int) DB::table('countries')->where('iso2', 'CO')->value('id');
        DB::table('client_organizations')->where('id', $this->clienteId)
            ->update(['country_id' => $colombia]);
        $this->perfilFiscal($colombia);

        $uuid = Facturas::borrador($this->campanaFacturable(10000.0));
        $factura = Facturas::ver($uuid);

        $this->assertNotNull($factura);
        $this->assertSame('exportacion', $factura->tax_regime);
        $this->assertSame('0.0000', $factura->tax_amount);
        $this->assertSame('CO', $factura->receiver_country_snapshot);
        $this->assertSame('PE', $factura->issuer_country_snapshot);
    }

    /**
     * Un país sin impuesto de venta declarado no factura en cero: lo dice.
     *
     * Es la otra mitad de `9.9a`. Aquel aviso salía en rojo en el panel; aquí la
     * frase llega en el momento en que alguien intenta emitir de verdad.
     */
    public function test_sin_impuesto_de_venta_declarado_lo_dice_con_palabras(): void
    {
        DB::table('countries')->where('id', $this->paisPE)->update(['sales_tax_code' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/impuesto de venta|saldria en cero/');

        Facturas::borrador($this->campanaFacturable());
    }

    /** Y un cliente sin perfil fiscal vigente tampoco se factura a ciegas. */
    public function test_sin_perfil_fiscal_del_cliente_lo_dice_con_palabras(): void
    {
        DB::table('client_tax_profiles')->where('client_organization_id', $this->clienteId)
            ->update(['valid_to' => '2026-01-01']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/perfil fiscal/');

        Facturas::borrador($this->campanaFacturable());
    }

    // -------------------------------------------------------------- la emisión

    /** **La que más importa.** Emitir gasta el número, y lo gasta una sola vez. */
    public function test_emitir_gasta_el_numero_del_libro(): void
    {
        $uuid = Facturas::borrador($this->campanaFacturable());
        $completo = Facturas::emitir($uuid, $this->serieId, (int) $this->usuarioCon('finance')->id);

        $factura = Facturas::ver($uuid);
        $this->assertNotNull($factura);
        $this->assertSame('issued', $factura->status);
        $this->assertSame('F001-00000001', $completo);
        $this->assertSame($completo, $factura->full_number);

        $numero = DB::table('document_numbers')->where('id', $factura->document_number_id)->first();
        $this->assertNotNull($numero);
        $this->assertSame('used', $numero->status);
        $this->assertSame('invoice', $numero->entity_type);
        $this->assertSame((int) $factura->id, (int) $numero->entity_id);
    }

    /** Una serie de otra sociedad no numera esta factura (`BR-LE-008`). */
    public function test_una_serie_de_otra_sociedad_no_numera_esta_factura(): void
    {
        $otra = $this->entidadLegal(['country_id' => $this->paisPE]);
        $serieAjena = $this->serieDe($otra, 'F009');

        $uuid = Facturas::borrador($this->campanaFacturable());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/otra sociedad/');

        Facturas::emitir($uuid, $serieAjena, (int) $this->usuarioCon('finance')->id);
    }

    /**
     * Lo emitido no se corrige: lo impone el motor, no el servicio.
     *
     * Se ataca por debajo del servicio a propósito. Una regla que sólo vive en
     * la capa de aplicación es una regla que el primer script de mantenimiento
     * se salta sin enterarse.
     */
    public function test_una_factura_emitida_no_se_puede_reescribir(): void
    {
        $uuid = Facturas::borrador($this->campanaFacturable());
        Facturas::emitir($uuid, $this->serieId, (int) $this->usuarioCon('finance')->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/no se corrige/');

        DB::table('invoices')->where('uuid', $uuid)->update(['subtotal_amount' => '1.0000']);
    }

    /** Ni se le añaden líneas después. */
    public function test_no_se_anaden_lineas_a_una_factura_emitida(): void
    {
        $uuid = Facturas::borrador($this->campanaFacturable());
        Facturas::emitir($uuid, $this->serieId, (int) $this->usuarioCon('finance')->id);
        $factura = Facturas::ver($uuid);
        $this->assertNotNull($factura);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/No se anaden lineas/');

        DB::table('invoice_lines')->insert([
            'invoice_id' => (int) $factura->id, 'line_number' => 9,
            'description' => 'Colada después', 'quantity' => '1', 'unit_price' => '10',
            'line_subtotal' => '10', 'tax_rate' => '0', 'line_tax' => '0', 'line_total' => '10',
        ]);
    }

    /** Anular exige el motivo, y el número sigue gastado. */
    public function test_anular_exige_motivo_y_no_devuelve_el_numero(): void
    {
        $uuid = Facturas::borrador($this->campanaFacturable());
        Facturas::emitir($uuid, $this->serieId, (int) $this->usuarioCon('finance')->id);

        try {
            Facturas::anular($uuid, 'error');
            $this->fail('Una anulación muda no debería pasar.');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/explicar que paso/', $e->getMessage());
        }

        Facturas::anular($uuid, 'El cliente rechazó el alcance después de emitida.');

        $factura = Facturas::ver($uuid);
        $this->assertNotNull($factura);
        $this->assertSame('voided', $factura->status);
        $this->assertSame(
            'used',
            DB::table('document_numbers')->where('id', $factura->document_number_id)->value('status'),
        );
    }

    /** Emitir deja huella en la bitácora. */
    public function test_emitir_queda_en_la_bitacora(): void
    {
        $uuid = Facturas::borrador($this->campanaFacturable());
        Facturas::emitir($uuid, $this->serieId, (int) $this->usuarioCon('finance')->id);
        $factura = Facturas::ver($uuid);
        $this->assertNotNull($factura);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice.issued',
            'entity_type' => 'invoice',
            'entity_id' => (int) $factura->id,
        ]);
    }

    // -------------------------------------------------------------- la pantalla

    public function test_la_pantalla_pide_permiso(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('facturas.index'))->assertStatus(403);
    }

    public function test_finanzas_ve_los_comprobantes(): void
    {
        $uuid = Facturas::borrador($this->campanaFacturable());

        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('facturas.ver', ['uuid' => $uuid]))
            ->assertOk()
            ->assertSee('Emitir');
    }

    /** Emitir desde la pantalla, con la serie elegida. */
    public function test_finanzas_emite_desde_la_pantalla(): void
    {
        $uuid = Facturas::borrador($this->campanaFacturable());

        $this->actingAs($this->usuarioCon('finance'))
            ->post(route('facturas.emitir', ['uuid' => $uuid]), [
                'document_series_id' => $this->serieId,
            ])
            ->assertRedirect(route('facturas.ver', ['uuid' => $uuid]));

        $this->assertSame('issued', Facturas::ver($uuid)?->status);
    }

    // -------------------------------------------------------------- ayudantes

    private function campanaFacturable(float $importe = 12000.0): int
    {
        return $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'completed',
            'billing_legal_entity_id' => $this->sociedadId,
            'currency_code' => 'PEN',
            'revenue_amount' => $importe,
            'is_gratis' => 0,
        ]);
    }

    private function perfilFiscal(int $paisId): int
    {
        return (int) DB::table('client_tax_profiles')->insertGetId([
            'client_organization_id' => $this->clienteId,
            'country_id' => $paisId,
            'legal_name' => 'ACME S.A.',
            'tax_id_type' => 'RUC',
            'tax_id_number' => (string) random_int(20000000000, 20999999999),
            'address_line1' => 'Av. Demo 100',
            'city' => 'Lima',
            'payment_term_days' => 30,
            'valid_from' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function serieDe(int $sociedadId, string $serie = 'F001'): int
    {
        $tipo = (int) DB::table('document_types')
            ->where('country_id', $this->paisPE)->where('code', 'invoice')->value('id');

        return Correlativos::guardarSerie(null, [
            'legal_entity_id' => $sociedadId,
            'document_type_id' => $tipo,
            'series' => $serie,
            'next_number' => 1,
            'environment' => 'production',
            'is_active' => true,
            'is_default' => $serie === 'F001',
        ]);
    }
}
