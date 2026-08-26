<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Communication\Jobs\EnviarCorreo;
use App\Modules\Communication\Services\Correo;
use App\Modules\Communication\Services\Plantillas;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El correo: plantillas versionadas y registro de envíos (iteración 4.9).
 *
 * ### Lo que esta iteración desbloquea
 *
 * `F4.9` paró media Fase 7: sin correo no se invita a un creador (`7.6`), no se
 * le manda su enlace de contraseña al aprobarlo (`5.9`), no se recupera una
 * contraseña (`4.1`) y no se le avisa cuando cambian sus datos fiscales (`T-10`,
 * y `BR-CREATOR-007` es 🔴).
 *
 * ### Las tres decisiones que estas pruebas fijan
 *
 * | Decisión | Qué se afirma |
 * |---|---|
 * | El cuerpo **no** se guarda | `email_log` tiene la huella, no el texto |
 * | Caída de idioma con constancia | se envía igual, y el registro dice qué se pidió |
 * | Tres intentos y luego visible | `failed` **con el error**, no en un archivo de log |
 */
final class CorreoTest extends TestCase
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

    // --------------------------------------------------------- las plantillas

    public function test_se_publica_una_plantilla_y_es_la_vigente(): void
    {
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola {{ nombre }}', 'Bienvenido, {{ nombre }}.');

        $r = Plantillas::resolver('creator.bienvenida', 'es');

        $this->assertSame('1.0', $r['plantilla']->version);
        $this->assertFalse($r['hubo_caida']);
    }

    /**
     * Publicar la siguiente cierra la anterior **el día antes**.
     *
     * `effective_to` es inclusivo: cerrarla el mismo día en que empieza la
     * siguiente deja dos vigentes a la vez. Es el error que este proyecto ha
     * visto once veces.
     */
    public function test_publicar_la_siguiente_cierra_la_anterior_el_dia_antes(): void
    {
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Uno.', '2026-01-01');
        $this->plantilla('creator.bienvenida', 'es', '2.0', 'Hola', 'Dos.', '2026-06-01');

        $vieja = DB::table('email_templates')->where('version', '1.0')->first();

        $this->assertSame('2026-05-31', $vieja->effective_to, 'el dia ANTES, no el mismo');
        $this->assertSame('2.0', Plantillas::resolver('creator.bienvenida', 'es')['plantilla']->version);
    }

    /** Y dos vigentes a la vez no entran ni por SQL: lo impide el compilador de periodos. */
    public function test_dos_versiones_vigentes_el_mismo_dia_no_entran_ni_por_sql(): void
    {
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Uno.', '2026-01-01');

        $this->expectException(QueryException::class);

        DB::table('email_templates')->insert([
            'uuid' => (string) Str::uuid(),
            'code' => 'creator.bienvenida', 'locale' => 'es', 'version' => 'pirata',
            'subject' => 'Hola', 'body' => 'Otro.', 'content_sha256' => hash('sha256', 'x'),
            'effective_from' => '2026-03-01', 'effective_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Una versión publicada para mañana **no** es la vigente hoy (`T-21`). */
    public function test_una_version_que_todavia_no_empieza_no_es_la_vigente(): void
    {
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Uno.', '2026-01-01');
        $this->plantilla('creator.bienvenida', 'es', '2.0', 'Hola', 'Dos.',
            now()->addMonth()->toDateString());

        $this->assertSame('1.0', Plantillas::resolver('creator.bienvenida', 'es')['plantilla']->version,
            'la del mes que viene todavia no rige');
    }

    // ------------------------------------------------------------ el renderizado

    public function test_las_variables_se_sustituyen(): void
    {
        $this->assertSame(
            'Hola Ana, son 500 soles.',
            Plantillas::renderizar('Hola {{ nombre }}, son {{ monto }} soles.',
                ['nombre' => 'Ana', 'monto' => 500]),
        );
    }

    /**
     * Una variable que nadie pasó **revienta**, no sale literal.
     *
     * Un `{{ nombre }}` en el correo de una persona es peor que un error aquí.
     */
    public function test_una_variable_sin_valor_revienta_en_vez_de_salir_literal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/apellido/');

        Plantillas::renderizar('Hola {{ nombre }} {{ apellido }}.', ['nombre' => 'Ana']);
    }

    // -------------------------------------------------------- la caída de idioma

    public function test_sin_plantilla_en_su_idioma_cae_al_de_por_defecto_y_lo_anota(): void
    {
        Queue::fake();
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Bienvenido.');

        Correo::enviar('creator.bienvenida', 'ana@ejemplo.test', [], 'pt-BR');

        $fila = DB::table('email_log')->first();

        $this->assertSame('es', $fila->template_locale, 'salio en el idioma por defecto');
        $this->assertSame('pt-BR', $fila->locale_requested, 'y queda constancia del que se pidio');
    }

    /** Con plantilla en su idioma, no hay caída. La otra mitad de la afirmación. */
    public function test_con_plantilla_en_su_idioma_no_hay_caida(): void
    {
        Queue::fake();
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Bienvenido.');
        $this->plantilla('creator.bienvenida', 'pt', '1.0', 'Ola', 'Bem-vindo.');

        Correo::enviar('creator.bienvenida', 'ana@ejemplo.test', [], 'pt');

        $fila = DB::table('email_log')->first();

        $this->assertSame('pt', $fila->template_locale);
        $this->assertSame('pt', $fila->locale_requested);
    }

    /** `pt-BR` cae a `pt` antes que a `es`: es una caída mucho más pequeña. */
    public function test_pt_br_cae_a_pt_antes_que_a_es(): void
    {
        Queue::fake();
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Bienvenido.');
        $this->plantilla('creator.bienvenida', 'pt', '1.0', 'Ola', 'Bem-vindo.');

        Correo::enviar('creator.bienvenida', 'ana@ejemplo.test', [], 'pt-BR');

        $this->assertSame('pt', DB::table('email_log')->value('template_locale'));
    }

    /** Y la lista de lo que falta por traducir sale de los envíos reales. */
    public function test_las_traducciones_que_faltan_se_pueden_consultar(): void
    {
        Queue::fake();
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Bienvenido.');

        Correo::enviar('creator.bienvenida', 'a@ejemplo.test', [], 'pt-BR');
        Correo::enviar('creator.bienvenida', 'b@ejemplo.test', [], 'pt-BR');
        Correo::enviar('creator.bienvenida', 'c@ejemplo.test', [], 'es');

        $faltan = Correo::traduccionesQueFaltan();

        $this->assertCount(1, $faltan, 'solo la caida, no el envio normal');
        $this->assertSame(2, (int) $faltan->first()->envios);
        $this->assertSame('pt-BR', $faltan->first()->locale_requested);
    }

    /** Sin plantilla ni en el idioma por defecto es un fallo de la plataforma, y se ve. */
    public function test_sin_ninguna_plantilla_revienta_en_voz_alta(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no existe|No hay ninguna version/i');

        Correo::enviar('creator.inexistente', 'ana@ejemplo.test');
    }

    // ---------------------------------------------- qué se guarda y qué no

    /**
     * **La prueba de la decisión de privacidad.**
     *
     * El cuerpo renderizado NO está en la tabla. Está su huella, y la versión de
     * la plantilla es inmutable: las dos juntas demuestran qué texto salió sin
     * guardar los datos de la persona una segunda vez.
     */
    public function test_el_cuerpo_no_se_guarda_pero_su_huella_si(): void
    {
        Queue::fake();
        $this->plantilla('creator.bienvenida', 'es', '1.0',
            'Hola {{ nombre }}', 'Su documento es {{ documento }}.');

        Correo::enviar('creator.bienvenida', 'ana@ejemplo.test',
            ['nombre' => 'Ana', 'documento' => '40000001']);

        $fila = (array) DB::table('email_log')->first();

        // Ninguna columna del registro contiene el documento.
        foreach ($fila as $columna => $valor) {
            $this->assertStringNotContainsString('40000001', (string) $valor,
                "la columna «{$columna}» esta guardando el dato personal del cuerpo");
        }

        $this->assertSame(
            hash('sha256', 'Su documento es 40000001.'),
            $fila['body_sha256'],
            'la huella si, y con ella se demuestra que texto salio',
        );
    }

    /** El código y la versión se COPIAN: `BR-LE-001` aplicado al correo. */
    public function test_la_version_de_la_plantilla_se_copia_en_el_registro(): void
    {
        Queue::fake();
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Uno.', '2026-01-01');
        Correo::enviar('creator.bienvenida', 'ana@ejemplo.test');

        // Se publica otra despues: el registro tiene que seguir diciendo 1.0.
        $this->plantilla('creator.bienvenida', 'es', '2.0', 'Hola', 'Dos.', '2026-06-01');

        $this->assertSame('1.0', DB::table('email_log')->value('template_version'),
            'lo que se envio no cambia porque despues se publique otra version');
    }

    // ------------------------------------------------------------ el envío

    public function test_enviar_encola_y_no_manda_dentro_de_la_peticion(): void
    {
        Queue::fake();
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Bienvenido.');

        Correo::enviar('creator.bienvenida', 'ana@ejemplo.test');

        Queue::assertPushed(EnviarCorreo::class);
        $this->assertSame('queued', DB::table('email_log')->value('status'));
    }

    public function test_el_job_envia_y_marca_el_registro(): void
    {
        Mail::fake();
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Bienvenido.');
        $uuid = Correo::enviar('creator.bienvenida', 'ana@ejemplo.test');

        (new EnviarCorreo($uuid, 'ana@ejemplo.test', 'Hola', 'Bienvenido.'))->handle();

        $fila = DB::table('email_log')->where('uuid', $uuid)->first();

        $this->assertSame('sent', $fila->status);
        $this->assertNotNull($fila->sent_at);
        $this->assertSame(1, (int) $fila->attempts);
    }

    /**
     * Un reintento sobre un registro que ya salió **no** manda el correo dos veces.
     *
     * La comprobación va dentro del job y no antes de despacharlo, porque entre
     * las dos cosas puede pasar un reintento.
     */
    public function test_un_reintento_sobre_uno_ya_enviado_no_lo_manda_dos_veces(): void
    {
        Mail::fake();
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Bienvenido.');
        $uuid = Correo::enviar('creator.bienvenida', 'ana@ejemplo.test');
        $job = new EnviarCorreo($uuid, 'ana@ejemplo.test', 'Hola', 'Bienvenido.');

        $job->handle();
        $job->handle();

        // Se afirma sobre `attempts` y no sobre el buzon falso: `Mail::raw()` no
        // pasa por un Mailable, asi que `Mail::fake()` no lo cuenta. Y da igual:
        // `attempts` es el mecanismo REAL --la segunda llamada salio antes de
        // tocar el correo-- y afirmar sobre el mecanismo es mas fuerte que
        // afirmar sobre un contador que se puede quedar a cero por otro motivo.
        $this->assertSame(1, (int) DB::table('email_log')->where('uuid', $uuid)->value('attempts'));
        $this->assertSame('sent', DB::table('email_log')->where('uuid', $uuid)->value('status'));
    }

    /**
     * Si el envío falla, el job **relanza**: la cola decide si quedan reintentos.
     *
     * Tragarse la excepción aquí dejaría el correo en `queued` para siempre — y
     * eso es peor que `failed`: uno se ve en la pantalla de fallidos y el otro
     * parece que sigue en camino. Una mutación que cambiaba el `throw` por un
     * `return` **sobrevivía a la primera versión de estas pruebas**.
     */
    public function test_si_el_envio_falla_el_job_relanza_y_anota_el_error(): void
    {
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Bienvenido.');
        Queue::fake();
        $uuid = Correo::enviar('creator.bienvenida', 'ana@ejemplo.test');

        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('El SMTP no responde.'));

        try {
            (new EnviarCorreo($uuid, 'ana@ejemplo.test', 'Hola', 'Bienvenido.'))->handle();
            $this->fail('El job tiene que RELANZAR: si no, la cola no reintenta nunca.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SMTP no responde', $e->getMessage());
        }

        $fila = DB::table('email_log')->where('uuid', $uuid)->first();

        $this->assertSame('queued', $fila->status, 'sigue en cola: quedan reintentos');
        $this->assertSame(1, (int) $fila->attempts, 'y el intento quedo contado');
        $this->assertStringContainsString('SMTP no responde', (string) $fila->last_error);
    }

    /** Al agotarse los intentos queda `failed` **con el error**, que es lo que se ve. */
    public function test_al_agotarse_los_intentos_queda_fallido_con_el_motivo(): void
    {
        Queue::fake();
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Bienvenido.');
        $uuid = Correo::enviar('creator.bienvenida', 'ana@ejemplo.test');

        (new EnviarCorreo($uuid, 'ana@ejemplo.test', 'Hola', 'Bienvenido.'))
            ->failed(new RuntimeException('El servidor SMTP rechazo la direccion.'));

        $fila = DB::table('email_log')->where('uuid', $uuid)->first();

        $this->assertSame('failed', $fila->status);
        $this->assertNotNull($fila->failed_at);
        $this->assertStringContainsString('rechazo la direccion', (string) $fila->last_error);
    }

    /** Un `failed` sin motivo no entra ni por SQL: obligaría a mirar el log del servidor. */
    public function test_un_fallido_sin_motivo_no_entra_ni_por_sql(): void
    {
        Queue::fake();
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Bienvenido.');
        $uuid = Correo::enviar('creator.bienvenida', 'ana@ejemplo.test');

        $this->expectException(QueryException::class);

        DB::table('email_log')->where('uuid', $uuid)
            ->update(['status' => 'failed', 'failed_at' => now()]);
    }

    // ------------------------------------------------------------- pantalla

    public function test_la_pantalla_abre_por_los_fallidos(): void
    {
        Queue::fake();
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Bienvenido.');
        $uuid = Correo::enviar('creator.bienvenida', 'ana@ejemplo.test');
        DB::table('email_log')->where('uuid', $uuid)->update([
            'status' => 'failed', 'failed_at' => now(), 'last_error' => 'Buzon lleno.',
        ]);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get('/correos')
            ->assertOk()
            ->assertSee('Buzon lleno.', false);
    }

    public function test_sin_permiso_no_se_ve_el_registro(): void
    {
        $this->actingAs($this->usuarioCon('content_reviewer'))->get('/correos')->assertForbidden();
        $this->actingAs($this->usuarioCon(null))->get('/correos')->assertForbidden();
    }

    public function test_la_pantalla_de_plantillas_lista_lo_publicado(): void
    {
        $this->plantilla('creator.bienvenida', 'es', '1.0', 'Hola', 'Bienvenido.');

        $this->actingAs($this->usuarioCon('finance'))
            ->get('/correos/plantillas')
            ->assertOk()
            ->assertSee('creator.bienvenida', false)
            ->assertSee('vigente', false);
    }

    // ------------------------------------------------------------------ apoyo

    private function plantilla(
        string $codigo,
        string $idioma,
        string $version,
        string $asunto,
        string $cuerpo,
        ?string $desde = null,
    ): int {
        return Plantillas::publicar(
            codigo: $codigo, idioma: $idioma, version: $version,
            asunto: $asunto, cuerpo: $cuerpo,
            desde: $desde ?? '2026-01-01',
        );
    }
}
