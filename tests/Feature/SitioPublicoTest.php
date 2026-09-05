<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Sitio;
use App\Shared\Auth\Permisos;
use App\Shared\Config\Aviso;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Los datos que se pintan en la calle (iteración L-2a).
 *
 * ### De dónde sale
 *
 * De una corrección del negocio a mi auditoría de la landing, y conviene que
 * quede escrita porque es una lección y no un requisito más:
 *
 * > *«todo lo que me pidas debe ser configurable desde el admin»*
 *
 * Yo había terminado la auditoría pidiéndole siete datos —el WhatsApp, el correo
 * público, las redes— para escribirlos en una plantilla. La respuesta correcta
 * era **construir el sitio donde los pone él**.
 */
final class SitioPublicoTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $marcaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
        Sitio::olvidar();

        $this->marcaId = (int) Marca::idActual();
    }

    protected function tearDown(): void
    {
        Sitio::olvidar();
        parent::tearDown();
    }

    // ------------------------------------------------------------- el enlace

    /**
     * **La que más importa.** El enlace de WhatsApp se arma en el servicio, con
     * el mensaje codificado.
     *
     * Se comprueba con un mensaje que lleva `&`, `?` y una tilde a propósito:
     * los tres rompen una URL si alguien la pega a mano en una plantilla, y
     * ninguno da error — el enlace simplemente abre WhatsApp sin texto, o no
     * abre nada.
     */
    public function test_el_enlace_de_whatsapp_se_arma_y_se_codifica(): void
    {
        $this->guardar([
            'whatsapp_phone' => '+51987654321',
            'whatsapp_message' => 'Hola, ¿me cuentan cómo funciona? Marca & campaña',
        ]);

        $url = Sitio::datos()['whatsappUrl'];

        self::assertNotNull($url);
        self::assertStringStartsWith('https://wa.me/51987654321?text=', $url,
            'wa.me quiere el numero SIN el «+»');
        self::assertStringNotContainsString(' ', $url, 'un espacio en la URL la rompe');
        self::assertStringNotContainsString('&campaña', $url, 'el «&» va codificado, no crudo');
        self::assertStringContainsString('%26', $url);
    }

    /** Sin número no hay enlace, aunque haya mensaje. */
    public function test_sin_numero_no_hay_enlace_aunque_haya_mensaje(): void
    {
        $this->guardar(['whatsapp_message' => 'Hola, quiero una campaña con creadores.']);

        self::assertNull(Sitio::datos()['whatsappUrl'],
            'un wa.me sin destinatario abre la aplicacion en blanco y quien lo pulsa cree que ha escrito');
    }

    // ------------------------------------------------------------- la base manda

    /**
     * El número tiene que ir en E.164, y lo impone **la base**.
     *
     * No es un capricho de formato: este valor viaja dentro de una URL, y un
     * espacio o un paréntesis la rompen sin dar ningún error.
     */
    public function test_la_base_rechaza_un_whatsapp_con_espacios(): void
    {
        $this->expectException(QueryException::class);

        DB::table('site_settings')->updateOrInsert(
            ['platform_brand_id' => $this->marcaId],
            ['whatsapp_phone' => '+51 987 654 321', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** Y una red social sin `https` tampoco entra. */
    public function test_la_base_rechaza_una_red_sin_cifrar(): void
    {
        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `ck_sl_url` la rechaza. Un enlace
        // publico sin cifrar es la misma advertencia de `9.17e` en otro sitio.
        DB::table('social_links')->insert([
            'platform_brand_id' => $this->marcaId, 'network' => 'instagram',
            'label' => 'Instagram', 'url' => 'http://instagram.com/latamsocial',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** La misma red dos veces son dos iconos iguales y nadie sabe cuál vale. */
    public function test_la_misma_red_no_entra_dos_veces(): void
    {
        $this->red('instagram');

        $this->expectException(QueryException::class);
        $this->red('instagram', 'Instagram viejo');
    }

    // ------------------------------------------------------------- los avisos

    /**
     * Sin sociedad operadora, **rojo**: los textos legales no pueden nombrar a
     * nadie, y eso lo lee un tercero.
     */
    public function test_sin_sociedad_operadora_el_aviso_es_rojo(): void
    {
        DB::table('site_settings')->where('platform_brand_id', $this->marcaId)
            ->update(['operator_legal_entity_id' => null]);
        Sitio::olvidar();

        $rojos = array_filter(Sitio::avisos(), static fn (Aviso $a): bool => $a->nivel === Aviso::ROJO);
        $textos = implode(' ', array_map(static fn (Aviso $a): string => $a->texto, $rojos));

        self::assertStringContainsString('sociedad que opera', $textos);
        self::assertStringContainsString('política de privacidad', $textos,
            'el aviso dice QUE se rompe si falta, no solo que falta');
    }

    /** Con todo puesto, ni un aviso. */
    public function test_con_todo_puesto_no_queda_ningun_aviso(): void
    {
        $this->guardar([
            'whatsapp_phone' => '+51987654321',
            'whatsapp_message' => 'Hola, quiero hacer una campaña con creadores.',
            'contact_email' => 'hola@latamsocial.com',
        ]);
        $this->red('instagram');

        self::assertSame([], Sitio::avisos());
    }

    /**
     * El WhatsApp puesto y sin mensaje avisa en **ámbar**, no en rojo.
     *
     * Funciona: la conversación se abre. Sólo empieza en blanco, y quien escribe
     * tiene que explicarse solo.
     */
    public function test_whatsapp_sin_mensaje_avisa_en_ambar(): void
    {
        // El mensaje se pone a NULL a mano: la semilla trae uno de partida, y
        // sin esto la prueba pasaria por el motivo equivocado --no habria aviso
        // ambar porque no falta nada--.
        $this->guardar([
            'whatsapp_phone' => '+51987654321',
            'whatsapp_message' => null,
            'contact_email' => 'hola@latamsocial.com',
        ]);
        $this->red('instagram');

        $niveles = array_map(static fn (Aviso $a): string => $a->nivel, Sitio::avisos());

        self::assertContains(Aviso::AMBAR, $niveles);
        self::assertNotContains(Aviso::ROJO, $niveles);
    }

    // ------------------------------------------------------------- la calle

    /** Lo configurado sale en el pie de la portada. */
    public function test_el_pie_de_la_portada_ensena_lo_configurado(): void
    {
        $this->guardar([
            'whatsapp_phone' => '+51987654321',
            'whatsapp_message' => 'Hola, quiero hacer una campaña.',
            'contact_email' => 'hola@latamsocial.com',
        ]);
        $this->red('instagram', 'Instagram', 'https://instagram.com/latamsocial');

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('hola@latamsocial.com', false)
            ->assertSee('https://instagram.com/latamsocial', false)
            ->assertSee('https://wa.me/51987654321', false);
    }

    /**
     * Y lo que NO está configurado **no deja un hueco ni un enlace roto**.
     *
     * Misma regla que el logotipo en `9.17`: una imagen rota es peor que ninguna
     * imagen. Aquí, un `mailto:` vacío es peor que ningún correo.
     */
    public function test_lo_que_no_esta_configurado_no_se_pinta(): void
    {
        DB::table('site_settings')->where('platform_brand_id', $this->marcaId)->update([
            'contact_email' => null, 'whatsapp_phone' => null, 'contact_phone' => null,
        ]);
        Sitio::olvidar();

        $respuesta = $this->get(route('portada.marcas'));

        $respuesta->assertOk();
        $respuesta->assertDontSee('mailto:"', false);
        $respuesta->assertDontSee('wa.me', false);
    }

    /** Una red apagada se guarda y no se enseña. */
    public function test_una_red_apagada_no_sale_en_el_pie(): void
    {
        $this->red('tiktok', 'TikTok', 'https://tiktok.com/@latamsocial');
        DB::table('social_links')->where('network', 'tiktok')->update(['is_visible' => false]);

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertDontSee('tiktok.com/@latamsocial', false);

        self::assertCount(1, Sitio::redes(soloVisibles: false), 'pero sigue guardada');
    }

    /**
     * Una red **desconocida** sale con icono de enlace y no rompe la página.
     *
     * Es la mitad que justifica que el código de red sea texto libre: una red
     * nueva tiene que funcionar el mismo día, sin migración y sin despliegue.
     */
    public function test_una_red_desconocida_no_rompe_el_pie(): void
    {
        $this->red('threads-nueva', 'Red del futuro', 'https://ejemplo.test/latamsocial');

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('Red del futuro', false)
            ->assertSee('https://ejemplo.test/latamsocial', false);
    }

    // ------------------------------------------------------------- el admin

    /** La pantalla exige `brand.manage`, como la portada. */
    public function test_la_pantalla_exige_permiso(): void
    {
        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('sitio.index'))
            ->assertForbidden();
    }

    /** Y con el permiso se ve, con la sociedad y las redes. */
    public function test_con_permiso_se_ve_y_se_guarda(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('sitio.index'))
            ->assertOk()
            ->assertSee('Soluciones Tecnológicas a Medida S.A.C.', false);

        $this->actingAs($this->usuarioCon('admin'))
            ->put(route('sitio.update'), [
                'whatsapp_phone' => '+51987654321',
                'whatsapp_message' => 'Hola, quiero hacer una campaña con creadores.',
                'contact_email' => 'hola@latamsocial.com',
            ])
            ->assertRedirect();

        Sitio::olvidar();
        self::assertSame('+51987654321', Sitio::datos()['whatsapp']);
    }

    /** Un número mal escrito se explica junto al campo, no como error de SQL. */
    public function test_un_numero_mal_escrito_se_explica(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->put(route('sitio.update'), ['whatsapp_phone' => '987 654 321'])
            ->assertSessionHasErrors('whatsapp_phone');
    }

    /** Guardar deja rastro en la bitácora. */
    public function test_guardar_deja_rastro(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->put(route('sitio.update'), ['contact_email' => 'hola@latamsocial.com']);

        self::assertSame(1, DB::table('audit_logs')->where('action', 'site_settings.updated')->count());
    }

    // ------------------------------------------------------------- utilería

    /** @param array<string, mixed> $datos */
    private function guardar(array $datos): void
    {
        DB::table('site_settings')->updateOrInsert(
            ['platform_brand_id' => $this->marcaId],
            $datos + ['updated_at' => now(), 'created_at' => now()],
        );
        Sitio::olvidar();
    }

    private function red(string $codigo, string $etiqueta = 'Instagram', string $url = 'https://instagram.com/latamsocial'): void
    {
        DB::table('social_links')->insert([
            'platform_brand_id' => $this->marcaId, 'network' => $codigo,
            'label' => $etiqueta, 'url' => $url, 'sort_order' => 100, 'is_visible' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
