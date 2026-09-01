<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Landing;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * La puerta de la calle (iteración 9.21b).
 *
 * ### Lo que fija
 *
 * Que **la portada es contenido y no plantilla**. Es la prueba que se pone roja
 * el día que alguien escriba un titular en el `.blade.php`: cambia el texto en
 * la base y exige verlo en la página. Sin ella, «configurable» es una promesa.
 *
 * Y que la postulación pública **escribe de verdad** en `creator_applications`,
 * que existe desde la Fase 2 con `source` por defecto `'landing'` y hasta hoy no
 * tenía ninguna landing que escribiera en ella.
 */
final class LandingTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
    }

    // ------------------------------------------------------------ el contenido

    /** **La que más importa.** El texto sale de la base, no de la plantilla. */
    public function test_el_titular_sale_de_la_base(): void
    {
        $pagina = $this->pagina(Landing::MARCAS);

        DB::table('landing_pages')->where('id', $pagina->id)->update([
            'headline' => 'Un titular que sólo puede venir de la base de datos',
        ]);

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('Un titular que sólo puede venir de la base de datos');
    }

    /** Y los bloques también, con su orden y su visibilidad. */
    public function test_los_bloques_se_pintan_y_los_ocultos_no(): void
    {
        $pagina = $this->pagina(Landing::CREADORES);

        Landing::guardarBloque((int) $pagina->id, null, [
            'kind' => 'feature', 'heading' => 'Bloque que se ve',
            'body' => 'Texto visible.', 'sort_order' => 1, 'is_visible' => true,
        ]);
        Landing::guardarBloque((int) $pagina->id, null, [
            'kind' => 'feature', 'heading' => 'Bloque escondido',
            'body' => 'Todavía no.', 'sort_order' => 2, 'is_visible' => false,
        ]);

        $this->get(route('portada.creadores'))
            ->assertOk()
            ->assertSee('Bloque que se ve')
            ->assertDontSee('Bloque escondido');
    }

    /** Lo que sale al compartir el enlace sale de la página, no del navegador. */
    public function test_la_descripcion_para_buscadores_llega_al_html(): void
    {
        $pagina = $this->pagina(Landing::MARCAS);

        DB::table('landing_pages')->where('id', $pagina->id)->update([
            'meta_description' => 'Esto es lo que se lee al compartir el enlace.',
        ]);

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('Esto es lo que se lee al compartir el enlace.', escape: false);
    }

    /** Las dos portadas se enlazan entre sí: una sola deja fuera a la mitad. */
    public function test_las_dos_portadas_se_enlazan(): void
    {
        $this->get(route('portada.marcas'))->assertOk()
            ->assertSee(route('portada.creadores'), escape: false);

        $this->get(route('portada.creadores'))->assertOk()
            ->assertSee(route('portada.marcas'), escape: false);
    }

    /**
     * Sin publicar se va al acceso, **no a un 404**.
     *
     * Apagar una portada es una decisión de contenido, no una avería: quien
     * entre tiene que encontrar la puerta, que es lo que había antes de que
     * existiera la landing. Nada bloquea (`DEC-190`).
     */
    public function test_una_portada_sin_publicar_lleva_al_acceso(): void
    {
        DB::table('landing_pages')->where('code', Landing::MARCAS)->update(['is_published' => 0]);

        $this->get(route('portada.marcas'))->assertRedirect(route('acceso'));
    }

    /** Y sin ninguna portada tampoco revienta. */
    public function test_sin_portadas_la_raiz_sigue_llevando_a_alguna_parte(): void
    {
        DB::table('landing_blocks')->delete();
        DB::table('landing_pages')->delete();

        $this->get(route('portada.marcas'))->assertRedirect(route('acceso'));
    }

    // --------------------------------------------------------- la postulación

    /** **La otra que más importa.** Postular escribe en `creator_applications`. */
    public function test_postular_crea_una_solicitud(): void
    {
        $peru = DB::table('countries')->where('iso2', 'PE')->value('id');

        $this->post(route('postular'), [
            'full_name' => 'Ana Ruiz',
            'email' => 'ANA@ejemplo.pe',
            'country_id' => $peru,
            'phone' => '+51 999 888 777',
        ])->assertRedirect(route('portada.gracias'));

        $this->assertDatabaseHas('creator_applications', [
            // En minúsculas: el correo es la llave de `uq_creator_applications_open`
            // y «Ana@» y «ana@» son la misma persona.
            'email' => 'ana@ejemplo.pe',
            'source' => 'landing',
            'status' => 'submitted',
        ]);
    }

    /** Postular dos veces no es un error del que postula: se le dice lo útil. */
    public function test_postular_dos_veces_lo_dice_con_palabras(): void
    {
        $peru = DB::table('countries')->where('iso2', 'PE')->value('id');
        $datos = ['full_name' => 'Ana Ruiz', 'email' => 'ana@ejemplo.pe', 'country_id' => $peru];

        $this->post(route('postular'), $datos)->assertRedirect(route('portada.gracias'));

        $this->post(route('postular'), $datos)
            ->assertRedirect()
            ->assertSessionHas('aviso', fn (string $aviso): bool => str_contains($aviso, 'en revisión'));

        $this->assertSame(1, DB::table('creator_applications')
            ->where('email', 'ana@ejemplo.pe')->count());
    }

    /**
     * El campo trampa: se contesta «gracias» y no se escribe nada.
     *
     * Decirle al robot que fue detectado sólo le enseña a intentarlo mejor.
     */
    public function test_el_campo_trampa_no_escribe_nada(): void
    {
        $peru = DB::table('countries')->where('iso2', 'PE')->value('id');

        $this->post(route('postular'), [
            'full_name' => 'Robot Cualquiera',
            'email' => 'robot@ejemplo.pe',
            'country_id' => $peru,
            'empresa' => 'me delaté solo',
        ])->assertRedirect(route('portada.gracias'));

        $this->assertDatabaseMissing('creator_applications', ['email' => 'robot@ejemplo.pe']);
    }

    /** Y la solicitud aparece en la bandeja de siempre, sin nada nuevo. */
    public function test_la_solicitud_llega_a_la_bandeja_del_admin(): void
    {
        $peru = DB::table('countries')->where('iso2', 'PE')->value('id');

        $this->post(route('postular'), [
            'full_name' => 'Ana Ruiz', 'email' => 'ana@ejemplo.pe', 'country_id' => $peru,
        ]);

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('solicitudes.index'))
            ->assertOk()
            ->assertSee('Ana Ruiz');
    }

    // ------------------------------------------------------------- la edición

    public function test_la_pantalla_de_edicion_pide_permiso(): void
    {
        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('landing.index'))->assertStatus(403);
    }

    public function test_se_edita_el_titular_desde_el_admin_y_se_ve_en_la_calle(): void
    {
        $pagina = $this->pagina(Landing::MARCAS);

        $this->actingAs($this->usuarioCon('admin'))
            ->put(route('landing.update', $pagina->id), [
                'headline' => 'Titular escrito desde el panel de administración',
                'cta_label' => 'Hablemos',
                'is_published' => '1',
            ])
            ->assertRedirect(route('landing.index'));

        $this->get(route('portada.marcas'))
            ->assertOk()
            ->assertSee('Titular escrito desde el panel de administración');
    }

    /** El cambio de lo que se ve en la calle deja huella. */
    public function test_editar_la_portada_queda_en_la_bitacora(): void
    {
        $pagina = $this->pagina(Landing::MARCAS);

        $this->actingAs($this->usuarioCon('admin'))
            ->put(route('landing.update', $pagina->id), [
                'headline' => 'Otro titular cualquiera para la portada',
                'cta_label' => 'Hablemos',
                'is_published' => '1',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'landing.updated',
            'entity_type' => 'landing_page',
            'entity_id' => $pagina->id,
        ]);
    }

    /** Un titular de tres letras lo rechaza la base; el formulario lo dice antes. */
    public function test_un_titular_vacio_se_rechaza_con_palabras(): void
    {
        $pagina = $this->pagina(Landing::MARCAS);

        $this->actingAs($this->usuarioCon('admin'))
            ->put(route('landing.update', $pagina->id), [
                'headline' => 'corto', 'cta_label' => 'Hablemos',
            ])
            ->assertSessionHasErrors('headline');
    }

    // ---------------------------------------------------------------- avisos

    public function test_una_portada_sin_descripcion_sale_en_ambar(): void
    {
        DB::table('landing_pages')->update(['meta_description' => null]);

        $textos = implode(' ', array_map(
            fn ($a): string => $a->texto,
            array_filter(Landing::avisos(), fn ($a): bool => $a->nivel === 'ambar'),
        ));

        $this->assertStringContainsString('buscadores', $textos);
    }

    private function pagina(string $code): object
    {
        $pagina = DB::table('landing_pages')->where('code', $code)->first();

        $this->assertNotNull($pagina, "La semilla tiene que dejar la portada «{$code}».");

        return $pagina;
    }
}
