<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Politica;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * La política de precios y el neto pactado (iteración 9.18).
 *
 * ### El ejemplo del negocio, convertido en prueba
 *
 * > «te pagaré 100 soles pero en realidad lo que estaría provisionando para
 * > pagarle sería 141.84 […] el ingreso aceptable más bajo por este creador
 * > sería de 170.21 soles»
 *
 * Las tres cifras están aquí, con los números sembrados. Si alguien cambia la
 * fórmula, esta prueba lo dice con el mismo ejemplo con el que se pidió.
 *
 * ### Y la pregunta que no había que hacer
 *
 * 170,21 es `141,84 × 1,20` —recargo **sobre el costo**—. Un 20 % de margen
 * **sobre el ingreso** habría dado 177,31. Iba a preguntar cuál de los dos era;
 * `DEC-190` dice que estas cosas se configuran, así que hay una prueba de cada
 * una y la elección vive en la base.
 */
final class PoliticaTest extends TestCase
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

    // ------------------------------------------------------------- la cuenta

    /** **La que más importa.** 100 netos cuestan 141,84 con 29,5 % de retención. */
    public function test_cien_netos_cuestan_ciento_cuarenta_y_uno_con_ochenta_y_cuatro(): void
    {
        $this->assertSame(141.844, Politica::brutoDesdeNeto(100.0));
    }

    /** Y la vuelta cuadra: 141,844 con 29,5 % deja 100. */
    public function test_la_vuelta_cuadra(): void
    {
        $this->assertSame(100.0, round(Politica::netoDesdeBruto(141.844), 2));
    }

    /** El ingreso mínimo sobre el COSTO: 141,844 × 1,20 = 170,21. */
    public function test_el_ingreso_minimo_sobre_el_costo_es_170_21(): void
    {
        $this->assertSame(170.21, round(Politica::ingresoMinimo(141.844), 2));
    }

    /**
     * Y sobre el INGRESO, el mismo 20 % pide 177,31.
     *
     * Es la diferencia que el negocio tiene que ver antes de elegir. No se
     * eligió por él: se configuró, y las dos cuentas están probadas.
     */
    public function test_y_sobre_el_ingreso_el_mismo_veinte_por_ciento_pide_mas(): void
    {
        $this->assertSame(177.31,
            round(Politica::ingresoMinimo(141.844, 20.0, Politica::INGRESO), 2));
    }

    /** El desglose entero, que es lo que enseña la pantalla. */
    public function test_el_desglose_da_las_cuatro_cifras_del_ejemplo(): void
    {
        $d = Politica::desglose(100.0);

        $this->assertSame(100.0, $d['neto']);
        $this->assertSame(29.5, $d['tasa']);
        $this->assertSame(41.844, $d['retenido']);
        $this->assertSame(141.844, $d['costo']);
        $this->assertSame(170.2128, $d['minimo']);
    }

    /**
     * Una retención del 100 % dejaría el costo en infinito.
     *
     * `ck_pp_tasa` lo impide en la base, y aquí se para **antes de dividir**:
     * este método también recibe la tasa congelada de una participación vieja o
     * la de un formulario, que no pasan por ese CHECK.
     */
    public function test_una_retencion_del_cien_por_cien_no_divide_por_cero(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/infinito/');

        Politica::brutoDesdeNeto(100.0, 100.0);
    }

    // ------------------------------------------------------------ publicar

    public function test_publicar_cierra_la_anterior_el_dia_antes(): void
    {
        $this->actingAs($this->usuarioCon('admin'))->post(route('politica.store'), [
            'withholding_rate' => 18,
            'min_margin_pct' => 25,
            'margin_basis' => 'revenue',
            'note' => 'Prueba.',
            'valid_from' => '2030-01-01',
        ])->assertRedirect(route('politica.index'));

        $anterior = DB::table('pricing_policies')->where('valid_from', '2026-01-01')->first();
        $this->assertSame('2029-12-31', (string) $anterior->valid_to);

        $vigente = Politica::vigente();
        $this->assertSame('18.0000', (string) $vigente->withholding_rate);
        $this->assertSame('revenue', $vigente->margin_basis);
    }

    /** Una que empieza antes que la vigente se rechaza con palabras, no con un 45000. */
    public function test_una_politica_que_empieza_antes_se_rechaza_con_un_mensaje(): void
    {
        $this->actingAs($this->usuarioCon('admin'))->post(route('politica.store'), [
            'withholding_rate' => 18, 'min_margin_pct' => 25,
            'margin_basis' => 'cost', 'valid_from' => '2025-01-01',
        ])->assertSessionHas('aviso');

        $this->assertSame(1, DB::table('pricing_policies')->count());
    }

    public function test_sin_pricing_manage_no_se_entra_ni_se_publica(): void
    {
        $usuario = $this->usuarioCon('campaign_manager');

        $this->actingAs($usuario)->get(route('politica.index'))->assertForbidden();
        $this->actingAs($usuario)->post(route('politica.store'), [
            'withholding_rate' => 1, 'min_margin_pct' => 1,
            'margin_basis' => 'cost', 'valid_from' => '2030-01-01',
        ])->assertForbidden();
    }

    // ---------------------------------------------------- sin politica no bloquea

    /**
     * `DEC-190`: sin política, el sistema opera y lo dice en rojo.
     *
     * La política sembrada se cierra —no se borra, `tg_pp_no_delete`— y queda un
     * sistema sin política vigente, que es al que se llega de verdad si alguien
     * cierra una y no publica la siguiente.
     */
    public function test_sin_politica_vigente_el_panel_entra_y_avisa_en_rojo(): void
    {
        DB::table('pricing_policies')->update(['valid_to' => '2026-12-31']);

        $this->assertNull(Politica::vigente());
        $this->assertSame(0.0, Politica::datos()['tasa']);
        $this->assertSame('rojo', Politica::avisos()[0]->nivel);

        $this->actingAs($this->usuarioCon('admin'))->get(route('configuracion'))
            ->assertOk()
            ->assertSee('No hay política de precios');
    }

    // ------------------------------------------------ el compromiso, en la pantalla

    /**
     * Se teclea 100, se guarda 141,844, y el mensaje dice las tres cifras.
     *
     * Es la iteración entera vista desde la pantalla que la usa.
     */
    public function test_pactar_el_neto_guarda_el_bruto_y_congela_la_tasa(): void
    {
        [$campana, $participacion] = $this->participacionSinAceptar();

        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('campanas.candidatos.monto', [$campana->uuid, $participacion]), [
                'agreed_basis' => 'net',
                'agreed_amount' => 100,
            ])->assertSessionHas('exito');

        $fila = DB::table('campaign_creators')->where('id', $participacion)->first();

        $this->assertSame('net', $fila->agreed_basis);
        $this->assertSame('141.8440', (string) $fila->agreed_amount);
        $this->assertSame('100.0000', (string) $fila->agreed_net_amount);
        $this->assertSame('29.5000', (string) $fila->withholding_rate_snapshot);
        // El umbral se congela tambien: es con lo que se juzgo esta
        // participacion, y subirlo manana no puede reescribir ese juicio.
        $this->assertSame('20.0000', (string) $fila->min_margin_pct_snapshot);
        $this->assertSame('cost', $fila->margin_basis_snapshot);
    }

    /** Y el mensaje dice el ingreso mínimo, que es lo que se pidió ver. */
    public function test_el_mensaje_dice_el_ingreso_minimo_aceptable(): void
    {
        [$campana, $participacion] = $this->participacionSinAceptar();

        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('campanas.candidatos.monto', [$campana->uuid, $participacion]), [
                'agreed_basis' => 'net', 'agreed_amount' => 100,
            ])->assertSessionHas('exito', fn (string $m): bool => str_contains($m, '170.21'));
    }

    /** Pactar el costo sigue funcionando igual que antes de 9.18. */
    public function test_pactar_el_costo_sigue_funcionando(): void
    {
        [$campana, $participacion] = $this->participacionSinAceptar();

        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('campanas.candidatos.monto', [$campana->uuid, $participacion]), [
                'agreed_basis' => 'gross', 'agreed_amount' => 500,
            ])->assertSessionHas('exito');

        $fila = DB::table('campaign_creators')->where('id', $participacion)->first();

        $this->assertSame('gross', $fila->agreed_basis);
        $this->assertSame('500.0000', (string) $fila->agreed_amount);
        $this->assertNull($fila->agreed_net_amount);
    }

    /**
     * Sin retención configurada, pactar «el neto» se rechaza **con explicación**.
     *
     * No es un bloqueo por gusto: con tasa 0 el neto y el costo son el mismo
     * número, y guardarlo como «neto» haría creer que se retuvo algo. Se dice
     * qué hacer —ponerla, o pactar el costo— en vez de guardar una mentira.
     */
    public function test_sin_retencion_pactar_el_neto_se_rechaza_con_explicacion(): void
    {
        DB::table('pricing_policies')->whereNull('valid_to')->update(['withholding_rate' => 0]);
        [$campana, $participacion] = $this->participacionSinAceptar();

        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('campanas.candidatos.monto', [$campana->uuid, $participacion]), [
                'agreed_basis' => 'net', 'agreed_amount' => 100,
            ])->assertSessionHas('aviso', fn (string $m): bool => str_contains($m, 'retención'));
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Una participación que todavía se puede tocar.
     *
     * Las dos que siembra `CimientosSeeder` están **aceptadas**, y
     * `tg_ccr_monto_congelado` (`BR-CREATOR-008`) impide cambiarles el importe
     * — con razón: lo aceptado no se toca. Usarlas mediría esa regla y no la de
     * aquí, así que se crea una nueva.
     *
     * @return array{0: \stdClass, 1: int}
     */
    private function participacionSinAceptar(): array
    {
        $paisId = (int) DB::table('countries')->where('iso2', 'PE')->value('id');

        $clienteId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'commercial_name' => 'ACME 918',
            'client_code' => 'ACME-918', 'country_id' => $paisId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $marcaId = (int) DB::table('client_brands')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_organization_id' => $clienteId,
            'name' => 'Marca 918', 'slug' => 'marca-918', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Presupuesto amplio: lo que se prueba aqui es la cuenta del neto, no
        // `BR-CAMPAIGN-005`. Un fixture que tropieza con otra regla mide esa.
        $campanaId = $this->campanaDe($clienteId, $marcaId, ['creator_budget_amount' => 999999]);
        $campana = DB::table('campaigns')->where('id', $campanaId)->first();
        $creador = $this->creadorPendiente(['display_name' => 'Creador 918']);

        $id = (int) DB::table('campaign_creators')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campana->id,
            'creator_id' => $creador,
            'status' => 'shortlisted',
            'agreed_amount' => 0,
            'currency_code' => $campana->currency_code,
            'payee_type' => 'creator',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$campana, $id];
    }
}
