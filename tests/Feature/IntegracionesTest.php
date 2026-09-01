<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Integraciones;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Las credenciales de cada API (iteración 9.17d).
 *
 * ### Lo que fija
 *
 * Que **un secreto entra y no vuelve a salir**. Ni por la pantalla, ni por la
 * bitácora, ni por el método que una vista podría llamar por descuido. Es la
 * mitad del motivo de que esta iteración exista, y lo único que hace que enseñar
 * esa pantalla a alguien no sea entregarle las claves.
 *
 * Y que **rotar no es sobrescribir**: guardar una credencial nueva revoca la
 * anterior y crea una versión, para poder volver atrás y para poder contestar
 * «¿cuándo cambió y quién la puso?».
 */
final class IntegracionesTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $conexionId;

    private string $uuid;

    private int $sociedadId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Queue::fake();

        // 9.17e: CON sociedad --un emisor electronico lleva su RUC, y desde
        // esta iteracion la base lo exige para activar-- y SIN `base_url`: la
        // direccion de SUNAT viene sembrada por entorno y no se teclea.
        $this->sociedadId = $this->entidadLegal();

        $this->uuid = Integraciones::guardarConexion(null, [
            'integration_provider_id' => (int) DB::table('integration_providers')
                ->where('code', 'sunat')->value('id'),
            'legal_entity_id' => $this->sociedadId,
            'name' => 'SUNAT de prueba',
            'environment' => 'sandbox',
            'username' => 'MODDATOS',
            'status' => 'active',
        ], 1);

        $this->conexionId = (int) Integraciones::porUuid($this->uuid)->id;
    }

    // ---------------------------------------------- el secreto no vuelve a salir

    /** **La que más importa.** `estado()` no devuelve el secreto, sólo su cola. */
    public function test_el_estado_no_devuelve_el_secreto(): void
    {
        $admin = $this->usuarioCon('admin');
        Integraciones::guardarSecreto($this->conexionId, 'api_key',
            'clave-larguisima-y-secreta-3456', (int) $admin->id);

        $estado = Integraciones::estado($this->conexionId);

        $this->assertSame('3456', $estado[0]['ultimos']);
        $this->assertStringNotContainsString('secreta', json_encode($estado, JSON_THROW_ON_ERROR));
    }

    /** Y la pantalla tampoco: sólo los cuatro últimos. */
    public function test_la_pantalla_no_ensena_el_secreto(): void
    {
        $admin = $this->usuarioCon('admin');
        Integraciones::guardarSecreto($this->conexionId, 'api_key',
            'clave-larguisima-y-secreta-3456', (int) $admin->id);

        $respuesta = $this->actingAs($admin)->get(route('integraciones.index'));

        $respuesta->assertOk();
        $respuesta->assertSee('3456');
        $respuesta->assertDontSee('clave-larguisima-y-secreta');
    }

    /** Ni la bitácora: se anota QUE cambió, nunca el valor (`BR-SEC-001`). */
    public function test_la_bitacora_no_guarda_el_secreto(): void
    {
        $admin = $this->usuarioCon('admin');
        Integraciones::guardarSecreto($this->conexionId, 'api_key',
            'clave-larguisima-y-secreta-3456', (int) $admin->id);

        $fila = DB::table('audit_logs')->where('action', 'integration.credential_set')->first();

        $this->assertNotNull($fila);
        $entero = json_encode($fila, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secreta', $entero);
        // Ni siquiera los cuatro ultimos: la bitacora la lee mas gente que la
        // pantalla, y ahi no hacen falta.
        $this->assertStringNotContainsString('3456', $entero);
    }

    /** El que sí lo devuelve es otro método, y devuelve lo que se guardó. */
    public function test_el_secreto_se_recupera_entero_por_su_propio_metodo(): void
    {
        $admin = $this->usuarioCon('admin');
        Integraciones::guardarSecreto($this->conexionId, 'api_key', 'clave-real-1234', (int) $admin->id);

        $this->assertSame('clave-real-1234',
            Integraciones::secreto($this->conexionId, 'api_key'));
    }

    /** Sin credencial, `null`: «no hay» es un estado normal, no un error. */
    public function test_sin_credencial_devuelve_nulo(): void
    {
        $this->assertNull(Integraciones::secreto($this->conexionId, 'api_key'));
    }

    // ------------------------------------------------------------------ rotar

    public function test_rotar_revoca_la_anterior_y_crea_una_version(): void
    {
        $admin = $this->usuarioCon('admin');

        Integraciones::guardarSecreto($this->conexionId, 'api_key', 'primera-1111', (int) $admin->id);
        Integraciones::guardarSecreto($this->conexionId, 'api_key', 'segunda-2222', (int) $admin->id,
            'Se filtro la anterior.');

        $filas = DB::table('integration_credentials')
            ->where('integration_connection_id', $this->conexionId)
            ->orderBy('version')->get();

        $this->assertCount(2, $filas);
        $this->assertNotNull($filas[0]->revoked_at);
        $this->assertSame('Se filtro la anterior.', $filas[0]->revoked_reason);
        $this->assertNull($filas[1]->revoked_at);
        $this->assertSame(2, (int) $filas[1]->version);

        // Y la que se usa es la nueva.
        $this->assertSame('segunda-2222', Integraciones::secreto($this->conexionId, 'api_key'));
    }

    public function test_una_credencial_vacia_no_se_guarda(): void
    {
        $this->expectExceptionMessageMatches('/vacia no es una credencial/');

        Integraciones::guardarSecreto($this->conexionId, 'api_key', '   ',
            (int) $this->usuarioCon('admin')->id);
    }

    // ----------------------------------------------------------- la conexion

    /** Una URL por http no entra: la pantalla lo dice antes que la base. */
    public function test_una_conexion_activa_necesita_url_https(): void
    {
        $this->actingAs($this->usuarioCon('admin'))->post(route('integraciones.store'), [
            'integration_provider_id' => (int) DB::table('integration_providers')
                ->where('code', 'smtp')->value('id'),
            'name' => 'Correo sin url',
            'environment' => 'production',
            'base_url' => 'http://smtp.ejemplo.com',
            'status' => 'active',
        ])->assertSessionHasErrors('base_url');
    }

    // --------------------------------------------- 9.17e: la URL no se teclea

    /**
     * **La del defecto reportado.** La dirección se hereda del proveedor.
     *
     * > «¿por qué me pide la URL? si selecciono Pruebas debe ir al URL Beta»
     *
     * Los extremos de SUNAT son fijos y públicos: no son un dato de esta
     * instalación. La conexión del `setUp` se creó **sin escribir ninguna** y
     * tiene que saber a dónde llama.
     */
    public function test_la_direccion_se_hereda_del_proveedor(): void
    {
        $this->assertNull(Integraciones::porUuid($this->uuid)->base_url);
        $this->assertSame(
            'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService',
            Integraciones::urlDe($this->conexionId),
        );
    }

    /** Y cada entorno hereda la suya, que es la mitad que faltaba. */
    public function test_cada_entorno_hereda_la_suya(): void
    {
        $uuid = Integraciones::guardarConexion(null, [
            'integration_provider_id' => (int) DB::table('integration_providers')
                ->where('code', 'sunat')->value('id'),
            'legal_entity_id' => $this->sociedadId,
            'name' => 'SUNAT producción',
            'environment' => 'production',
            'status' => 'draft',
        ], 1);

        $this->assertSame(
            'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService',
            Integraciones::urlDe((int) Integraciones::porUuid($uuid)->id),
        );
    }

    /** La propia gana: es la excepción para un OSE o una homologación. */
    public function test_la_direccion_propia_gana_sobre_la_del_proveedor(): void
    {
        Integraciones::guardarConexion($this->uuid, [
            'integration_provider_id' => (int) DB::table('integration_providers')
                ->where('code', 'sunat')->value('id'),
            'legal_entity_id' => $this->sociedadId,
            'name' => 'SUNAT de prueba',
            'environment' => 'sandbox',
            'base_url' => 'https://ose.example.test/billService',
            'status' => 'active',
        ], 1);

        $this->assertSame(
            'https://ose.example.test/billService',
            Integraciones::urlDe($this->conexionId),
        );
    }

    /**
     * Un emisor electrónico va con una sociedad, y lo impone el motor.
     *
     * Es lo que faltaba: el formulario ofrecía «Toda la plataforma» para SUNAT,
     * que no puede ser — un comprobante sale con **un** RUC.
     */
    public function test_un_emisor_electronico_activo_exige_sociedad(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/va con una sociedad/');

        Integraciones::guardarConexion(null, [
            'integration_provider_id' => (int) DB::table('integration_providers')
                ->where('code', 'sunat')->value('id'),
            'legal_entity_id' => null,
            'name' => 'SUNAT sin sociedad',
            'environment' => 'production',
            'status' => 'active',
        ], 1);
    }

    /** Y el correo sí puede ser de toda la plataforma: la regla es del propósito. */
    public function test_el_correo_si_puede_ser_de_toda_la_plataforma(): void
    {
        $uuid = Integraciones::guardarConexion(null, [
            'integration_provider_id' => (int) DB::table('integration_providers')
                ->where('code', 'smtp')->value('id'),
            'legal_entity_id' => null,
            'name' => 'Correo de la plataforma',
            'environment' => 'production',
            'base_url' => 'https://smtp.example.test',
            'status' => 'active',
        ], 1);

        $this->assertNull(Integraciones::porUuid($uuid)->legal_entity_id);
    }

    /** Los extremos sembrados salen en la pantalla, para no tener que buscarlos. */
    public function test_la_pantalla_ensena_las_direcciones_sembradas(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('integraciones.index'))
            ->assertOk()
            ->assertSee('e-beta.sunat.gob.pe')
            ->assertSee('e-factura.sunat.gob.pe');
    }

    /**
     * El certificado ESTÁ aquí, y ya no hay que decir dónde va.
     *
     * Esta prueba nació en `9.17e`, cuando el certificado vivía en otra pantalla
     * y lo único que se podía hacer era enseñar el camino. Desde `9.17f` está en
     * esta misma pestaña, así que lo que hay que defender es lo contrario: que
     * las tres cosas de emitir se vean juntas. Se cambia el enunciado en vez de
     * borrarla porque la pregunta de fondo —«¿me entero aquí de con qué se
     * firma?»— sigue siendo la misma.
     */
    public function test_las_tres_cosas_de_emitir_estan_en_la_misma_pestana(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('integraciones.index'))
            ->assertOk()
            ->assertSee('Conexión con el emisor', false)
            ->assertSee('Certificado de firma digital')
            ->assertSee('Series y folios');
    }

    // ------------------------------------------------------------- el permiso

    public function test_sin_integration_manage_no_se_entra_ni_se_guarda(): void
    {
        $usuario = $this->usuarioCon('campaign_manager');

        $this->actingAs($usuario)->get(route('integraciones.index'))->assertForbidden();
        $this->actingAs($usuario)
            ->post(route('integraciones.credencial', $this->uuid), [
                'kind' => 'api_key', 'secreto' => 'algo-1234',
            ])->assertForbidden();
    }

    // --------------------------------------------------- lo que dice el panel

    /** Una conexión activa sin credencial sale en rojo: parece configurada. */
    public function test_el_panel_avisa_de_una_activa_sin_credencial(): void
    {
        $this->actingAs($this->usuarioCon('admin'))->get(route('configuracion'))
            ->assertOk()
            ->assertSee('la primera llamada de verdad saldrá sin clave');
    }

    /** Y puesta la credencial, ese aviso desaparece. */
    public function test_puesta_la_credencial_el_aviso_desaparece(): void
    {
        $admin = $this->usuarioCon('admin');
        Integraciones::guardarSecreto($this->conexionId, 'api_key', 'clave-1234', (int) $admin->id);

        $this->actingAs($admin)->get(route('configuracion'))
            ->assertDontSee('la primera llamada de verdad saldrá sin clave');
    }
}
