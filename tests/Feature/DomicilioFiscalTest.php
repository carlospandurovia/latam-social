<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El domicilio fiscal, con la forma que exige cada país (iteración 9.17c).
 *
 * ### Qué fija
 *
 * Que **la forma del código de localidad viene del país y no del código**. En
 * Perú es el ubigeo del INEI —seis dígitos—; en Colombia el código DANE
 * —cinco—. Escribir `^[0-9]{6}$` en la validación sería la regla de un país
 * aplicada a los seis, y rechazaría un código colombiano correcto (`DEC-190`).
 *
 * La prueba que más importa es la que mete **el mismo valor en dos países** y
 * espera respuestas distintas: es lo único que demuestra que la regla no está
 * quemada.
 */
final class DomicilioFiscalTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Queue::fake();
    }

    // ------------------------------------------------- la forma la declara el pais

    /**
     * **La que más importa.** El mismo código, dos países, dos respuestas.
     *
     * Seis dígitos valen en Perú y no en Colombia. Si algún día alguien escribe
     * el patrón peruano en la validación, esta prueba se pone roja por el lado
     * colombiano.
     */
    public function test_el_mismo_codigo_vale_en_un_pais_y_no_en_otro(): void
    {
        $admin = $this->usuarioCon('admin');

        $this->actingAs($admin)
            ->post(route('entidades.store'), $this->datos('PE', 'PE01', '150101'))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post(route('entidades.store'), $this->datos('CO', 'CO01', '150101'))
            ->assertSessionHasErrors('tax_location_code');
    }

    /** Y el código colombiano bueno sí entra. */
    public function test_el_codigo_dane_de_cinco_digitos_entra_en_colombia(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('entidades.store'), $this->datos('CO', 'CO02', '11001'))
            ->assertSessionHasNoErrors();

        $this->assertSame('11001',
            DB::table('legal_entities')->where('code', 'CO02')->value('tax_location_code'));
    }

    /**
     * Un país que no declara forma **no impide** dar de alta una sociedad.
     *
     * `DEC-190`: una configuración que falta no bloquea. México no tiene
     * equivalente al ubigeo y no se le ha declarado patrón; eso no puede
     * impedir registrar una sociedad mexicana con el código que su
     * administración le pida.
     */
    public function test_un_pais_sin_patron_declarado_admite_el_codigo(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('entidades.store'), $this->datos('MX', 'MX01', 'AB1234'))
            ->assertSessionHasNoErrors();
    }

    /** Y sin código tampoco se bloquea: se guarda a medias y el panel lo dice. */
    public function test_sin_codigo_de_localidad_la_sociedad_se_guarda_igual(): void
    {
        $datos = $this->datos('PE', 'PE02', '150101');
        unset($datos['tax_location_code']);

        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('entidades.store'), $datos)
            ->assertSessionHasNoErrors();

        $this->assertNull(
            DB::table('legal_entities')->where('code', 'PE02')->value('tax_location_code'));
    }

    // -------------------------------------------------------- el establecimiento

    /** Sin ponerlo, «0000»: el domicilio fiscal. */
    public function test_el_establecimiento_nace_en_0000(): void
    {
        $datos = $this->datos('PE', 'PE03', '150101');
        unset($datos['establishment_code']);

        $this->actingAs($this->usuarioCon('admin'))->post(route('entidades.store'), $datos);

        $this->assertSame('0000',
            DB::table('legal_entities')->where('code', 'PE03')->value('establishment_code'));
    }

    /**
     * Vaciar el campo al editar **no** manda una cadena vacía a la base.
     *
     * La columna no admite nulo y `ck_le_establecimiento` rechaza `''`. Sin
     * normalizarlo, vaciar el campo en la ficha daba un `45000` sin explicar
     * nada.
     */
    public function test_vaciar_el_establecimiento_al_editar_lo_devuelve_a_0000(): void
    {
        $admin = $this->usuarioCon('admin');
        $this->actingAs($admin)->post(route('entidades.store'), $this->datos('PE', 'PE04', '150101'));

        $uuid = (string) DB::table('legal_entities')->where('code', 'PE04')->value('uuid');
        $datos = $this->datos('PE', 'PE04', '150101');
        unset($datos['code'], $datos['country_id']);
        $datos['establishment_code'] = '';

        $this->actingAs($admin)->put(route('entidades.update', $uuid), $datos)
            ->assertSessionHasNoErrors();

        $this->assertSame('0000',
            DB::table('legal_entities')->where('code', 'PE04')->value('establishment_code'));
    }

    // ------------------------------------------------------ la pantalla lo pide bien

    /**
     * El formulario lo llama como lo llame el país de esa sociedad.
     *
     * «Ubigeo» para una peruana. Sin esto habría un `Ubigeo` escrito en el
     * Blade, que es la regla de un país en la plantilla de todos.
     */
    public function test_la_ficha_llama_al_codigo_como_lo_llama_el_pais(): void
    {
        $admin = $this->usuarioCon('admin');
        $this->actingAs($admin)->post(route('entidades.store'), $this->datos('PE', 'PE05', '150101'));
        $uuid = (string) DB::table('legal_entities')->where('code', 'PE05')->value('uuid');

        $this->actingAs($admin)->get(route('entidades.show', $uuid))
            ->assertOk()
            ->assertSee('Ubigeo');
    }

    // ------------------------------------------------- lo que el panel tiene que decir

    /**
     * El domicilio sembrado dice «Por completar», y hasta hoy no lo decía nadie.
     *
     * Las dos columnas son `NOT NULL` y el sembrador no tiene nada verdadero que
     * poner, así que escribe un texto. La sociedad parecía completa, y ese texto
     * habría salido impreso en una factura.
     */
    public function test_el_panel_avisa_del_domicilio_por_completar(): void
    {
        $this->actingAs($this->usuarioCon('admin'))->get(route('configuracion'))
            ->assertOk()
            ->assertSee('todavía dice «Por completar»');
    }

    /** Y de que a las sociedades peruanas les falta el ubigeo, en rojo. */
    public function test_el_panel_avisa_del_ubigeo_que_falta(): void
    {
        $this->actingAs($this->usuarioCon('admin'))->get(route('configuracion'))
            ->assertSee('Sin ubigeo');
    }

    /** Puesto el ubigeo, ese aviso concreto desaparece. */
    public function test_puesto_el_ubigeo_el_aviso_desaparece(): void
    {
        DB::table('legal_entities')
            ->whereIn('country_id', DB::table('countries')->where('iso2', 'PE')->pluck('id'))
            ->update(['tax_location_code' => '150101']);

        $this->actingAs($this->usuarioCon('admin'))->get(route('configuracion'))
            ->assertDontSee('Sin ubigeo');
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Los datos de una sociedad nueva en el país que se le diga.
     *
     * @return array<string, mixed>
     */
    private function datos(string $iso2, string $codigo, string $localidad): array
    {
        return [
            'code' => $codigo,
            'legal_name' => "Sociedad {$codigo} S.A.",
            'country_id' => (int) DB::table('countries')->where('iso2', $iso2)->value('id'),
            'tax_id_type' => 'TAX',
            'tax_id_number' => '9'.str_pad((string) crc32($codigo), 10, '0', STR_PAD_LEFT),
            'address_line1' => 'Av. de Prueba 123',
            'city' => 'Lima',
            'district' => 'Miraflores',
            'region' => 'Lima',
            'tax_location_code' => $localidad,
            'establishment_code' => '0000',
            'default_currency_code' => 'USD',
            'timezone' => 'America/Lima',
        ];
    }
}
