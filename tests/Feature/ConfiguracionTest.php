<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Marca;
use App\Shared\Auth\Permisos;
use App\Shared\Config\Aviso;
use App\Shared\Config\Preparacion;
use App\Shared\Database\Vigencia;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El panel de «qué falta por configurar» (iteración 9.17b).
 *
 * ### Qué fija
 *
 * Tres cosas, y las tres son la iteración entera:
 *
 * 1. **No bloquea.** Con avisos rojos por todas partes, el sistema entra y
 *    opera igual. Es `DEC-190` y es lo que hay que impedir que alguien
 *    «arregle» dentro de seis meses convirtiendo un aviso en una puerta.
 * 2. **Cada uno ve lo suyo.** Un área se muestra sólo a quien tiene el permiso
 *    con el que se arregla. Sin esto, el panel sería la fuga que `BR-SEC-001`
 *    prohíbe, con el agravante de que reúne en una pantalla lo que está
 *    repartido en seis.
 * 3. **Una comprobación que revienta no tumba el panel.** Es lo que hace que
 *    esta pantalla siga contestando el día en que hay algo que contestar.
 */
final class ConfiguracionTest extends TestCase
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

    /**
     * El registro es estático y sobrevive a la prueba; la aplicación no.
     *
     * Las pruebas que registran áreas de mentira las dejarían puestas para la
     * siguiente, que volvería a registrar las de verdad **encima** de ellas y
     * contaría cinco áreas donde hay siete. Se limpia al salir: en la prueba
     * siguiente el arranque de la aplicación vuelve a poner las reales.
     */
    protected function tearDown(): void
    {
        Preparacion::olvidar();
        parent::tearDown();
    }

    // ------------------------------------------------------------- no bloquea

    /**
     * **La que más importa.** Con avisos rojos, el sistema opera igual.
     *
     * Una instalación recién sembrada tiene el correo en `log`, la marca sin
     * logotipo y los términos sin revisar. Nada de eso impide entrar, ver el
     * panel ni abrir ninguna pantalla.
     */
    public function test_con_avisos_rojos_el_sistema_sigue_operando(): void
    {
        $admin = $this->usuarioCon('admin');

        $this->actingAs($admin)->get(route('configuracion'))->assertOk();
        $this->actingAs($admin)->get(route('panel'))->assertOk();
        $this->actingAs($admin)->get(route('marca.index'))->assertOk();
        $this->actingAs($admin)->get(route('terminos.index'))->assertOk();
        $this->actingAs($admin)->get(route('entidades.index'))->assertOk();
    }

    /** Y el panel dice justamente eso, con todas las letras. */
    public function test_el_panel_dice_que_nada_de_esto_impide_operar(): void
    {
        $this->actingAs($this->usuarioCon('admin'))->get(route('configuracion'))
            ->assertOk()
            ->assertSee('Nada de esto impide operar');
    }

    /** El correo sembrado está en «log»: no sale de la máquina, y es rojo. */
    public function test_el_correo_en_log_sale_en_rojo(): void
    {
        config(['mail.default' => 'log']);

        $respuesta = $this->actingAs($this->usuarioCon('admin'))->get(route('configuracion'));

        $respuesta->assertSee('Correo');
        $respuesta->assertSee('no sale de este servidor');
    }

    /** Con SMTP puesto, ese aviso desaparece: no es un adorno permanente. */
    public function test_con_smtp_configurado_el_aviso_del_correo_desaparece(): void
    {
        config(['mail.default' => 'smtp']);

        $this->actingAs($this->usuarioCon('admin'))->get(route('configuracion'))
            ->assertOk()
            ->assertDontSee('no sale de este servidor');
    }

    // --------------------------------------------------------- cada uno ve lo suyo

    /**
     * Finanzas ve el área de tipos de cambio y **no** la de términos.
     *
     * `BR-SEC-001` aplicado a una pantalla que reúne en un sitio lo que está
     * repartido en seis: juntar avisos no puede ser la forma de enseñarlos a
     * quien no los vería por separado.
     */
    public function test_finanzas_ve_su_area_y_no_las_demas(): void
    {
        $respuesta = $this->actingAs($this->usuarioCon('finance'))->get(route('configuracion'));

        $respuesta->assertOk();
        $respuesta->assertSee('Tipos de cambio');
        $respuesta->assertDontSee('Términos');
        $respuesta->assertDontSee('Marca');
    }

    /** Y se le dice que hay más áreas, sin decirle cuáles. */
    public function test_se_dice_cuantas_areas_no_se_ven(): void
    {
        $this->actingAs($this->usuarioCon('finance'))->get(route('configuracion'))
            ->assertSee('que no se muestran porque su configuración la lleva otro permiso');
    }

    public function test_sin_config_view_no_se_entra(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('configuracion'))->assertForbidden();
    }

    // ------------------------------------------- una comprobacion que revienta

    /**
     * Un área que lanza una excepción **no tumba el panel**.
     *
     * Se convierte en un aviso ámbar que dice que hoy no se sabe, y las demás
     * se siguen viendo. Un panel de «qué me falta» que responde 500 porque a un
     * área le pasa algo deja de contestar justo el día en que hay algo que
     * contestar.
     */
    public function test_un_area_que_revienta_no_tumba_el_panel(): void
    {
        Preparacion::area('Area rota', null, null, static function (): array {
            throw new \RuntimeException('la tabla no existe todavia');
        }, orden: 1);

        $respuesta = $this->actingAs($this->usuarioCon('admin'))->get(route('configuracion'));

        $respuesta->assertOk();
        $respuesta->assertSee('no se pudo ejecutar');
        $respuesta->assertSee('la tabla no existe todavia');
        // Y el resto del panel sigue ahi.
        $respuesta->assertSee('Marca');
    }

    // ------------------------------------------------------------------ orden

    /** Lo rojo va antes que lo ámbar, y lo ámbar antes que lo que está listo. */
    public function test_lo_urgente_sale_arriba(): void
    {
        Preparacion::olvidar();
        Preparacion::area('Tranquila', null, null, static fn (): array => [], orden: 1);
        Preparacion::area('Regular', null, null,
            static fn (): array => [Aviso::ambar('conviene')], orden: 2);
        Preparacion::area('Urgente', null, null,
            static fn (): array => [Aviso::rojo('atender')], orden: 3);

        $revision = Preparacion::revision(static fn (string $p): bool => true);

        $this->assertSame(['Urgente', 'Regular', 'Tranquila'], array_column($revision, 'area'));
        $this->assertSame(['rojo', 'ambar', 'verde'], array_column($revision, 'nivel'));
    }

    /** Un área sin avisos queda en verde, que no es lo mismo que sin comprobar. */
    public function test_un_area_sin_avisos_queda_en_verde(): void
    {
        Preparacion::olvidar();
        Preparacion::area('Vacia', null, null, static fn (): array => []);

        $revision = Preparacion::revision(static fn (string $p): bool => true);
        $recuento = Preparacion::recuento($revision);

        $this->assertSame('verde', $revision[0]['nivel']);
        $this->assertSame(1, $recuento['listas']);
        $this->assertSame(0, $recuento['rojo']);
    }

    // --------------------------------------------- que las areas esten registradas

    /**
     * Las áreas que existen hoy están registradas, y son éstas.
     *
     * Es la prueba que se rompe cuando alguien construye una pantalla de
     * configuración nueva y no la enchufa aquí: existiría un sitio donde falta
     * algo y el panel diría que no falta nada, que es peor que no tener panel.
     */
    public function test_las_areas_que_existen_estan_registradas(): void
    {
        $this->assertSame(
            // 9.18 anadio «Politica de precios» y esta prueba se puso roja, que
            // es exactamente para lo que esta: la lista se actualiza a mano y a
            // proposito, para que anadir un area sea una decision y no un
            // descuido.
            ['Correo', 'Entidades legales', 'Marca', 'Política de precios',
                'Tipos de cambio', 'Términos'],
            Preparacion::areasRegistradas(),
        );
    }

    /** Un país con clientes que no puede facturar nadie es rojo. */
    public function test_un_pais_con_clientes_y_sin_cobertura_sale_en_rojo(): void
    {
        // Se cierra la cobertura de Peru: el pais queda descubierto y hay
        // clientes peruanos en la semilla.
        $peru = (int) DB::table('countries')->where('iso2', 'PE')->value('id');
        $this->clienteEn($peru);
        // `Vigencia` y no `subDay()`: la puerta de vigencias lo caza, y con
        // razon --es la copia numero N del defecto de `H-16`--. Que sea una
        // prueba no lo hace menos copia.
        DB::table('legal_entity_countries')->where('country_id', $peru)
            ->whereNull('valid_to')
            ->update(['valid_to' => Vigencia::cerrarElDiaAntesDe(now()->toDateString())]);

        $this->actingAs($this->usuarioCon('admin'))->get(route('configuracion'))
            ->assertSee('no puede facturar ninguna sociedad');
    }

    /** Un cliente activo en un país, que es la premisa del aviso de arriba. */
    private function clienteEn(int $paisId): void
    {
        // `commercial_name` y no `legal_name`: la razon social vive en el
        // perfil fiscal, que es POR PAIS y puede ser distinta en cada uno.
        DB::table('client_organizations')->insert([
            'uuid' => (string) Str::uuid(),
            'commercial_name' => 'Cliente de prueba 917b',
            'client_code' => 'C917B',
            'country_id' => $paisId,
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
