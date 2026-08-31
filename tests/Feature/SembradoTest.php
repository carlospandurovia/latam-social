<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Database\Vigencia;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Volver a sembrar no deshace lo que una persona configuró (`T-77`).
 *
 * ### De dónde sale
 *
 * El runbook manda `php artisan db:seed --class=CimientosSeeder` **después de
 * cada migración** —es la única forma de que entren los catálogos nuevos—. Y
 * hasta hoy eso significaba: la marca vuelve a llamarse «LATAM Social» con los
 * colores de fábrica, la sociedad vuelve a tener la dirección en «Por completar»
 * y los dos `uuid` se regeneran.
 *
 * «Idempotente» estaba escrito en la cabecera del sembrador y era cierto —no
 * duplicaba nada— pero no era lo que hacía falta: `updateOrInsert` **reescribe**.
 *
 * Y además reventaba: con un periodo de tipo de cambio cerrado a mano, mover el
 * `valid_from` del abierto lo solapaba con el cerrado, `tg_fos_sin_solape_upd`
 * paraba el sembrador entero, y los catálogos que venían después —los proveedores
 * de integración de `9.17d`, los tipos de comprobante de `9.12`— no llegaban a
 * entrar. Se descubrió en la instalación de verdad, no aquí.
 *
 * Cada prueba de este archivo **falla sin el arreglo**. La última es la que
 * reproduce el error tal cual llegó.
 */
final class SembradoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CimientosSeeder::class);
    }

    /** **La que más importa.** Lo que se configura desde `/marca` sobrevive. */
    public function test_volver_a_sembrar_no_pisa_la_marca(): void
    {
        $antes = DB::table('platform_brands')->where('is_default', 1)->first();

        DB::table('platform_brands')->where('id', $antes->id)->update([
            'name' => 'Marca de la casa',
            'primary_color' => '#123456',
            'font_family' => 'Inter',
        ]);

        $this->seed(CimientosSeeder::class);

        $despues = DB::table('platform_brands')->where('id', $antes->id)->first();

        $this->assertSame('Marca de la casa', $despues->name);
        $this->assertSame('#123456', $despues->primary_color);
        $this->assertSame('Inter', $despues->font_family);
        // El `uuid` es el que viaja en las URLs: regenerarlo las rompe todas.
        $this->assertSame($antes->uuid, $despues->uuid);
    }

    /** Y el domicilio fiscal que `9.17c` acababa de dejar configurable. */
    public function test_volver_a_sembrar_no_pisa_el_domicilio_de_la_sociedad(): void
    {
        $antes = DB::table('legal_entities')->where('code', 'CTS_PE')->first();

        DB::table('legal_entities')->where('id', $antes->id)->update([
            'address_line1' => 'Av. Javier Prado Este 4200',
            'city' => 'Lima',
            'district' => 'Santiago de Surco',
            'tax_location_code' => '150140',
        ]);

        $this->seed(CimientosSeeder::class);

        $despues = DB::table('legal_entities')->where('id', $antes->id)->first();

        $this->assertSame('Av. Javier Prado Este 4200', $despues->address_line1);
        $this->assertSame('Santiago de Surco', $despues->district);
        $this->assertSame('150140', $despues->tax_location_code);
        $this->assertSame($antes->uuid, $despues->uuid);
    }

    /** Una fuente apagada a propósito no se reactiva sola. */
    public function test_volver_a_sembrar_no_reactiva_lo_que_alguien_apago(): void
    {
        DB::table('fx_sources')->where('code', 'manual')->update(['is_active' => 0]);
        DB::table('integration_providers')->where('code', 'smtp')->update(['is_active' => 0]);

        $this->seed(CimientosSeeder::class);

        $this->assertSame(0, (int) DB::table('fx_sources')
            ->where('code', 'manual')->value('is_active'));
        $this->assertSame(0, (int) DB::table('integration_providers')
            ->where('code', 'smtp')->value('is_active'));
    }

    /** Ni devuelve a fábrica un tipo de comprobante ajustado. */
    public function test_volver_a_sembrar_no_pisa_un_tipo_de_comprobante(): void
    {
        DB::table('document_types')->where('code', 'invoice')->update(['number_length' => 6]);

        $this->seed(CimientosSeeder::class);

        $this->assertSame(6, (int) DB::table('document_types')
            ->where('code', 'invoice')->value('number_length'));
    }

    /**
     * El error tal cual llegó de la instalación.
     *
     * Se cierra el periodo abierto de USD→PEN y se abre otro, que es lo que hace
     * cualquiera que cambie de fuente. Al volver a sembrar, el `updateOrInsert`
     * movía el `valid_from` del abierto hasta `2026-01-01` —encima del cerrado—
     * y `tg_fos_sin_solape_upd` paraba el sembrador con un `45000`.
     *
     * Que no lance ya sería bastante; se comprueba además que **el sembrador
     * llegó hasta el final**, porque el síntoma real no fue el error sino lo que
     * dejó de entrar detrás de él.
     */
    public function test_con_un_periodo_de_cambio_ya_cerrado_el_sembrado_no_revienta(): void
    {
        $abierta = DB::table('fx_official_sources')
            ->where('base_currency_code', 'USD')->where('quote_currency_code', 'PEN')
            ->whereNull('valid_to')->first();

        $corte = Vigencia::cerrarElDiaAntesDe('2026-06-01');

        DB::table('fx_official_sources')->where('id', $abierta->id)->update(['valid_to' => $corte]);
        DB::table('fx_official_sources')->insert([
            'base_currency_code' => 'USD', 'quote_currency_code' => 'PEN',
            'source_code' => 'manual', 'valid_from' => '2026-06-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->seed(CimientosSeeder::class);

        // Sigue mandando la que el operador dejó abierta, no la de fábrica.
        $this->assertSame('manual', DB::table('fx_official_sources')
            ->where('base_currency_code', 'USD')->where('quote_currency_code', 'PEN')
            ->whereNull('valid_to')->value('source_code'));

        // Y lo que se sembraba DESPUÉS del punto de rotura sí entró.
        $this->assertDatabaseHas('integration_providers', ['code' => 'sunat']);
        $this->assertDatabaseHas('document_types', ['code' => 'invoice']);
    }
}
