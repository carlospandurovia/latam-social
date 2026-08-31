<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Communication\Listeners\AvisarCambioSensible;
use App\Shared\Auth\Permisos;
use App\Shared\Eventos\EventoOcurrido;
use App\Shared\Eventos\Eventos;
use Database\Seeders\CimientosSeeder;
use Database\Seeders\PlantillasDeCorreoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * `BR-CREATOR-007`: avisar al creador de un cambio sensible (`T-10`, 4.13).
 *
 * La regla es 🔴 y estaba **a medias** desde la Fase 3:
 *
 * > Los cambios en datos fiscales, medios de pago o documento de identidad
 * > **requieren aprobación interna** antes de surtir efecto, **y notifican al
 * > canal de contacto anterior.**
 *
 * La primera mitad existe desde 3.6 y 3.8, con dos personas distintas exigidas
 * por la base. La segunda **no existía**: la pantalla se lo recordaba al
 * operador para que lo hiciera a mano, que es otra forma de decir que no se
 * hacía.
 *
 * ### Lo que estas pruebas fijan
 *
 * | Qué | Por qué |
 * |---|---|
 * | Se avisa **al capturar** | avisar tras aprobar es contar un hecho consumado |
 * | El correo **no lleva el dato** | se lee en buzones que no controlamos |
 * | Si el aviso falla, el cambio **sigue** | un SMTP caído no puede parar el back-office |
 * | Creator **no conoce** Communication | un fallo del correo no tumba la captura |
 */
final class AvisoCambioSensibleTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $creadorId;

    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        $this->seed(PlantillasDeCorreoSeeder::class);
        Permisos::olvidar();

        $this->uuid = (string) Str::uuid();
        $this->creadorId = $this->creadorPendiente(['uuid' => $this->uuid]);

        // La premisa: las plantillas existen. Sin ellas el listener registra el
        // fallo y sigue --que es lo decidido-- y TODAS las aserciones de «se
        // encolo un correo» saldrian rojas por el motivo equivocado.
        //
        // Se nombran LAS DOS que hacen falta, no se cuenta el total. Contarlas
        // era como estaba escrito, y `5.9` --que anadio al seeder los dos textos
        // del enlace de contrasena-- puso en rojo diez pruebas que no tenian
        // nada que ver: una premisa tiene que romperse cuando falta lo que
        // afirma, no cuando aparece algo mas.
        foreach (['creator.tax_profile_changed', 'creator.payment_method_changed'] as $codigo) {
            $this->assertTrue(
                DB::table('email_templates')->where('code', $codigo)->exists(),
                "falta la plantilla `{$codigo}`: el seeder tiene que haber corrido",
            );
        }
    }

    // ------------------------------------------------- el evento se levanta

    /** Capturar un perfil fiscal levanta el evento **y** encola el aviso. */
    public function test_capturar_un_perfil_fiscal_avisa_al_creador(): void
    {
        Queue::fake();

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/backoffice/creadores/{$this->uuid}/fiscal", $this->perfilFiscal())
            ->assertSessionHas('exito');

        $this->assertSame(1, DB::table('domain_events')
            ->where('event_name', 'creator.tax_profile_captured')->count());

        $correo = DB::table('email_log')->first();

        $this->assertNotNull($correo, 'BR-CREATOR-007 exige notificar, no solo registrar');
        $this->assertSame('creator.tax_profile_changed', $correo->template_code);
        $this->assertSame('creator', $correo->related_type);
        $this->assertSame($this->creadorId, (int) $correo->related_id);
    }

    /** Y capturar un medio de pago, también: ahí cambia **a dónde va el dinero**. */
    public function test_capturar_un_medio_de_pago_avisa_al_creador(): void
    {
        Queue::fake();

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/backoffice/creadores/{$this->uuid}/pagos", $this->medioDePagoFormulario())
            ->assertSessionHas('exito');

        $this->assertSame('creator.payment_method_changed',
            DB::table('email_log')->value('template_code'));
    }

    /** Va al correo del creador, que es el «canal de contacto anterior». */
    public function test_el_aviso_va_al_correo_del_creador(): void
    {
        Queue::fake();
        $suyo = (string) DB::table('creators')->where('id', $this->creadorId)->value('email');

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/backoffice/creadores/{$this->uuid}/fiscal", $this->perfilFiscal());

        $this->assertSame($suyo, DB::table('email_log')->value('to_email'));
    }

    // ------------------------------------------ el correo no lleva el dato

    /**
     * **La prueba de la decisión de privacidad.**
     *
     * Ni el asunto ni el cuerpo llevan el régimen, el RUC ni el número de
     * cuenta. Un correo se lee en pantallas ajenas y se queda en buzones que no
     * controlamos — y el escenario del que nos defendemos es justo ése.
     */
    public function test_el_correo_no_lleva_ni_el_ruc_ni_la_cuenta(): void
    {
        Queue::fake();

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/backoffice/creadores/{$this->uuid}/fiscal", $this->perfilFiscal());

        $plantilla = DB::table('email_templates')
            ->where('code', 'creator.tax_profile_changed')->first();
        $texto = $plantilla->subject."\n".$plantilla->body;

        foreach (['10400000012', 'RER', 'RUC', '{{ ruc }}', '{{ regimen }}'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $texto,
                "la plantilla esta filtrando «{$prohibido}» a un canal que no controlamos");
        }

        // Y lo que SI tiene que decir: que hay algo que reclamar.
        $this->assertStringContainsString('no ha sido usted', $texto);
    }

    /** El aviso sale **antes** de que el cambio surta efecto. */
    public function test_se_avisa_con_el_perfil_todavia_pendiente(): void
    {
        Queue::fake();

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/backoffice/creadores/{$this->uuid}/fiscal", $this->perfilFiscal());

        $this->assertSame('pending',
            DB::table('creator_tax_profiles')->where('creator_id', $this->creadorId)->value('status'),
            'el aviso tiene que salir mientras el cambio todavia se puede parar');
        $this->assertSame(1, DB::table('email_log')->count());
    }

    // ------------------------------ si el aviso falla, el cambio sigue

    /**
     * Sin plantilla publicada, el aviso no sale — **y el cambio se guarda igual**.
     *
     * Bloquear la captura de un dato fiscal porque falta un texto convierte un
     * fallo de configuración en un creador al que no se le puede corregir un
     * dato. Y el hecho consta: la fila de `domain_events` queda, aunque no haya
     * correo.
     */
    public function test_sin_plantilla_el_cambio_se_guarda_y_el_hecho_consta(): void
    {
        Queue::fake();
        DB::table('email_templates')->delete();

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/backoffice/creadores/{$this->uuid}/fiscal", $this->perfilFiscal())
            ->assertSessionHas('exito');

        $this->assertSame(1, DB::table('creator_tax_profiles')->count(), 'el cambio NO se bloquea');
        $this->assertSame(1, DB::table('domain_events')->count(), 'y el hecho consta igual');
        $this->assertSame(0, DB::table('email_log')->count());
    }

    /** Un creador sin correo tampoco bloquea nada. */
    public function test_sin_correo_al_que_avisar_el_cambio_sigue(): void
    {
        Queue::fake();

        (new AvisarCambioSensible)->handle(new EventoOcurrido(
            'creator.tax_profile_captured', 'creator', $this->creadorId,
            ['nombre' => 'Ana', 'correo' => ''],
        ));

        $this->assertSame(0, DB::table('email_log')->count());
    }

    // ------------------------------------------------- el bus de eventos

    /** El hecho se guarda **antes** de despachar: si el oyente revienta, consta igual. */
    public function test_el_evento_se_guarda_aunque_nadie_escuche(): void
    {
        Queue::fake();

        Eventos::ocurrio('algo.que.nadie.escucha', 'creator', $this->creadorId, ['x' => 1]);

        $fila = DB::table('domain_events')->where('event_name', 'algo.que.nadie.escucha')->first();

        $this->assertNotNull($fila);
        $this->assertSame($this->creadorId, (int) $fila->entity_id);
        $this->assertSame(['x' => 1], json_decode((string) $fila->payload, true));
    }

    /** Un evento que no está en el mapa no manda nada. */
    public function test_un_evento_desconocido_no_produce_correo(): void
    {
        Queue::fake();

        (new AvisarCambioSensible)->handle(new EventoOcurrido(
            'creator.algo_inofensivo', 'creator', $this->creadorId,
            ['correo' => 'ana@ejemplo.test', 'nombre' => 'Ana'],
        ));

        $this->assertSame(0, DB::table('email_log')->count());
    }

    /** Y el actor queda registrado: quién levantó el hecho. */
    public function test_el_evento_registra_quien_lo_provoco(): void
    {
        Queue::fake();
        $usuario = $this->usuarioCon('finance');

        $this->actingAs($usuario)->post("/backoffice/creadores/{$this->uuid}/fiscal", $this->perfilFiscal());

        $this->assertSame((int) $usuario->id,
            (int) DB::table('domain_events')->value('actor_user_id'));
    }

    // ------------------------------------------------------------------ apoyo

    /** @return array<string, mixed> */
    private function perfilFiscal(): array
    {
        return [
            'holder_type' => 'creator',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'tax_regime_code' => 'RER',
            'tax_id_type' => 'RUC',
            'tax_id_number' => '10400000012',
            'issued_document_type' => 'factura',
            'valid_from' => '2026-01-01',
        ];
    }

    /** @return array<string, mixed> */
    private function medioDePagoFormulario(): array
    {
        return [
            'owner_type' => 'creator',
            'method_type' => 'bank_account',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'currency_code' => 'PEN',
            'bank_name' => 'BCP',
            'account_type' => 'savings',
            'account_number' => '19100000001',
            'account_number_confirmacion' => '19100000001',
            'holder_name' => 'Ana Torres',
            'holder_document_type' => 'DNI',
            'holder_document_number' => '40000001',
        ];
    }
}
