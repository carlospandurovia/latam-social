<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Sitio;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El formulario corto y la medición (iteración L-5).
 *
 * ### Las dos que más importan
 *
 * **`C-2`**: que el país por defecto NO sea «el primero de la lista» —que era
 * Chile, por orden alfabético— ni tampoco «Perú» escrito en el código. El país
 * de un lead decide el mercado, la moneda y qué comprobante se emite; etiquetarlo
 * mal no da ningún error y se descubre tarde.
 *
 * **§21 + `9.22a`**: que la medición **no se emita fuera de producción**. Un
 * volcado de producción restaurado en un servidor de pruebas trae dentro el
 * identificador bueno de la propiedad, así que cada clic de una prueba se
 * contaría como una visita real. No rompe nada, y por eso nadie lo nota: los
 * números simplemente dejan de significar algo.
 */
final class FormularioYMedicionTest extends TestCase
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
        Sitio::olvidar();
    }

    // ------------------------------------------------------- el país (`C-2`)

    /** **La que más importa.** El país por defecto no está escrito en el código. */
    public function test_el_pais_por_defecto_es_el_de_la_sociedad_operadora(): void
    {
        $peru = DB::table('countries')->where('iso2', 'PE')->value('id');

        $this->assertSame((int) $peru, Sitio::paisPorDefecto());

        // Y si la sociedad operadora cambia de pais, cambia con ella: no hay
        // ningun «Peru» escrito en ninguna parte.
        $colombia = DB::table('countries')->where('iso2', 'CO')->value('id');
        DB::table('legal_entities')->where('code', 'CTS_PE')->update(['country_id' => $colombia]);
        Sitio::olvidar();

        $this->assertSame((int) $colombia, Sitio::paisPorDefecto());
    }

    /** Y lo elegido en «Sitio público» manda sobre la sociedad. */
    public function test_el_ajuste_manda_sobre_la_sociedad(): void
    {
        $chile = DB::table('countries')->where('iso2', 'CL')->value('id');
        DB::table('site_settings')->update(['default_country_id' => $chile]);
        Sitio::olvidar();

        $this->assertSame((int) $chile, Sitio::paisPorDefecto());
    }

    /** El desplegable lo trae PRIMERO y MARCADO, que es lo que se ve. */
    public function test_el_formulario_trae_el_pais_marcado(): void
    {
        $peru = (int) DB::table('countries')->where('iso2', 'PE')->value('id');

        $html = (string) $this->get(route('portada.marcas'))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="'.$peru.'"', $html);
        $this->assertMatchesRegularExpression(
            '/<option value="'.$peru.'"\s+selected/',
            $html,
            'El país por defecto tiene que salir marcado, no sólo el primero de la lista.',
        );
    }

    /** Sin sociedad operadora no bloquea: sale el primero y se avisa (`DEC-190`). */
    public function test_sin_pais_por_defecto_el_formulario_sigue_funcionando(): void
    {
        DB::table('site_settings')->update([
            'default_country_id' => null, 'operator_legal_entity_id' => null,
        ]);
        Sitio::olvidar();

        $this->assertNull(Sitio::paisPorDefecto());
        $this->get(route('portada.marcas'))->assertOk();

        $this->assertStringContainsString('país por defecto', $this->ambares());
    }

    // ------------------------------------------------- la medición (§21)

    /** Sin medición configurada no sale ni un script de terceros. */
    public function test_sin_medicion_no_hay_ningun_script_de_terceros(): void
    {
        $html = (string) $this->get(route('portada.marcas'))->assertOk()->getContent();

        $this->assertStringNotContainsString('googletagmanager', $html);
        $this->assertStringNotContainsString('connect.facebook.net', $html);
        $this->assertStringNotContainsString('plausible.io', $html);
    }

    /**
     * **La otra que más importa.** Configurada, pero desde aquí NO se emite.
     *
     * Es el agujero de `9.22b` aplicado a la medición: el volcado de producción
     * trae el identificador bueno, y sin esta barrera un servidor de pruebas
     * mandaría visitas falsas a la propiedad de verdad sin dar ningún error.
     */
    public function test_configurada_pero_fuera_de_produccion_no_se_emite(): void
    {
        $this->medir('ga4', 'G-PRUEBA123');
        config(['instalacion.entorno' => 'staging']);

        $this->assertFalse(Sitio::medicion()['emite']);

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertDontSee('googletagmanager', escape: false)
            ->assertDontSee('G-PRUEBA123');
    }

    /** Y en la instalación de verdad sí se emite, con su identificador. */
    public function test_en_produccion_se_emite(): void
    {
        $this->medir('ga4', 'G-PRUEBA123');
        config(['instalacion.entorno' => 'production']);

        $this->assertTrue(Sitio::medicion()['emite']);

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('googletagmanager.com/gtag/js?id=G-PRUEBA123', escape: false);
    }

    /** Cada proveedor emite lo suyo y no lo del vecino. */
    public function test_cada_proveedor_emite_su_fragmento(): void
    {
        config(['instalacion.entorno' => 'production']);

        $this->medir('plausible', 'latamsocial.com');

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('plausible.io/js/script.js', escape: false)
            ->assertDontSee('connect.facebook.net', escape: false);
    }

    /** El puente de eventos sólo existe cuando hay algo que medir. */
    public function test_el_puente_de_eventos_solo_existe_si_se_mide(): void
    {
        $this->get(route('portada.marcas'))->assertOk()->assertDontSee('data-evento]', escape: false);

        $this->medir('ga4', 'G-PRUEBA123');
        config(['instalacion.entorno' => 'production']);

        $this->get(route('portada.marcas'))->assertOk()->assertSee('[data-evento]', escape: false);
    }

    // --------------------------------------------------- lo que no se admite

    /**
     * Un identificador con una comilla se rechaza con palabras.
     *
     * Ese valor entra DENTRO de un `<script>` de todas las páginas públicas: una
     * comilla ahí no es una errata, es una inyección.
     */
    public function test_un_identificador_con_comilla_se_rechaza(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->put(route('sitio.update'), [
                'analytics_provider' => 'ga4',
                'analytics_id' => "G-1';alert(1);//",
            ])
            ->assertSessionHasErrors('analytics_id');

        $this->assertDatabaseMissing('site_settings', ['analytics_provider' => 'ga4']);
    }

    /** Y un proveedor sin identificador tampoco entra: mediría nada. */
    public function test_un_proveedor_sin_identificador_se_rechaza_con_palabras(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->put(route('sitio.update'), ['analytics_provider' => 'ga4', 'analytics_id' => ''])
            ->assertSessionHas('aviso');

        $this->assertDatabaseMissing('site_settings', ['analytics_provider' => 'ga4']);
    }

    /**
     * Medir es tratar datos de terceros, y eso se dice (§56) — pero **en la
     * pantalla de la medición, no como aviso permanente**.
     *
     * Los dos primeros avisos que escribí estaban mal, y lo dijo una prueba de
     * `L-2a`: no medir es una decisión legítima, no una configuración a medias,
     * y un ámbar que no se apaga nunca acaba tapando los que sí hay que leer
     * (`DEC-282`).
     */
    public function test_la_pantalla_dice_que_medir_es_tratar_datos_de_terceros(): void
    {
        $this->medir('meta', '1234567890');

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('sitio.index'))
            ->assertOk()
            ->assertSee('la política de privacidad tiene');

        // Y no como aviso: con todo lo demas puesto, esta pantalla no puede
        // quedarse con un ambar encendido para siempre.
        $this->assertStringNotContainsString('medidor', $this->ambares());
    }

    // -------------------------------------------- el formulario sigue entero

    /**
     * Los campos plegados siguen guardándose (`C-7`).
     *
     * Acortar el formulario es esconder lo que no hace falta para contestar, no
     * dejar de recogerlo. `client_leads` no cambia y `Prospectos` tampoco: el §6
     * pide no crear soluciones paralelas.
     */
    public function test_el_telefono_y_la_web_se_siguen_guardando(): void
    {
        $peru = DB::table('countries')->where('iso2', 'PE')->value('id');

        $this->post(route('contacto'), [
            'company_name' => 'Marca de prueba', 'contact_name' => 'Quien escribe',
            'email' => 'quien@ejemplo.test', 'country_id' => $peru,
            'phone' => '+51987654321', 'website' => 'https://ejemplo.test',
            'message' => 'Queremos hacer una campaña.',
        ])->assertRedirect();

        $this->assertDatabaseHas('client_leads', [
            'email' => 'quien@ejemplo.test',
            'phone' => '+51987654321',
            'website' => 'https://ejemplo.test',
        ]);
    }

    /** Y sin ellos también se puede escribir: son opcionales de verdad. */
    public function test_sin_telefono_ni_web_el_lead_entra_igual(): void
    {
        $peru = DB::table('countries')->where('iso2', 'PE')->value('id');

        $this->post(route('contacto'), [
            'company_name' => 'Marca corta', 'contact_name' => 'Quien escribe',
            'email' => 'corta@ejemplo.test', 'country_id' => $peru,
        ])->assertRedirect();

        $this->assertDatabaseHas('client_leads', ['email' => 'corta@ejemplo.test']);
    }

    // ------------------------------------------------------------ auxiliares

    private function medir(string $proveedor, string $id): void
    {
        DB::table('site_settings')->update([
            'analytics_provider' => $proveedor, 'analytics_id' => $id,
        ]);
        Sitio::olvidar();
    }

    private function ambares(): string
    {
        return implode(' ', array_map(
            static fn ($a): string => $a->texto,
            array_filter(Sitio::avisos(), static fn ($a): bool => $a->nivel === 'ambar'),
        ));
    }
}
