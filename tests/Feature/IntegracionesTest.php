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

    // --------------------------------------------------- corregir y retirar (L-3a)

    /**
     * **La del defecto reportado.** Una conexión se puede corregir desde la
     * pantalla.
     *
     * La ruta `PUT` existía desde el primer día y ninguna vista apuntaba a
     * ella (`T-131`): la conexión de producción se llamaba «SUNAT PRD» siendo
     * de PRUEBAS, y el nombre es lo único que se lee de un vistazo.
     */
    public function test_una_conexion_se_corrige_desde_la_pantalla(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->put(route('integraciones.update', $this->uuid), [
                'integration_provider_id' => (int) DB::table('integration_providers')
                    ->where('code', 'sunat')->value('id'),
                'legal_entity_id' => $this->sociedadId,
                'name' => 'SUNAT pruebas (beta)',
                'environment' => 'sandbox',
                'username' => 'MODDATOS',
                'status' => 'disabled',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('integraciones.index'));

        $conexion = Integraciones::porUuid($this->uuid);

        self::assertSame('SUNAT pruebas (beta)', (string) $conexion->name);
        // Y «desactivar» es el mismo formulario: no hace falta un segundo boton
        // para lo que ya es un campo.
        self::assertSame('disabled', (string) $conexion->status);
    }

    /** El formulario de corregir llega con lo que YA hay elegido. */
    public function test_el_formulario_de_corregir_trae_los_valores_de_ahora(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('integraciones.index'))
            ->assertOk()
            ->assertSee('Corregir o retirar esta conexión', false)
            ->assertSee('MODDATOS');
    }

    /** Una conexión que nunca hizo nada se borra. */
    public function test_una_conexion_que_nunca_se_uso_se_borra(): void
    {
        self::assertNull(Integraciones::porQueNoSeBorra($this->uuid));

        $this->actingAs($this->usuarioCon('admin'))
            ->delete(route('integraciones.borrar', $this->uuid))
            ->assertRedirect(route('integraciones.index'));

        self::assertSame(0, DB::table('integration_connections')
            ->where('uuid', $this->uuid)->count());

        // Y queda escrito QUE se borró y cuál era: después no hay de dónde
        // sacar el nombre.
        $fila = DB::table('audit_logs')->where('action', 'integration.connection_deleted')->first();
        self::assertNotNull($fila);
        self::assertStringContainsString('SUNAT de prueba', (string) json_encode($fila, JSON_THROW_ON_ERROR));
    }

    /**
     * Una que guardó una credencial **no** se borra, y lo dice con palabras.
     *
     * El esquema ya lo impedía --`fk_icred_conn` es `RESTRICT`-- pero con un
     * `1451` del motor. Los dos cerrojos siguen puestos: éste comprueba el de
     * arriba, el que explica.
     */
    public function test_una_conexion_con_credenciales_no_se_borra_y_dice_por_que(): void
    {
        Integraciones::guardarSecreto($this->conexionId, 'password', 'clave-de-prueba',
            (int) $this->usuarioCon('admin')->id);

        $motivo = Integraciones::porQueNoSeBorra($this->uuid);

        self::assertNotNull($motivo);
        self::assertStringContainsString('Desactivada', $motivo);

        $this->actingAs($this->usuarioCon('admin'))
            ->delete(route('integraciones.borrar', $this->uuid))
            ->assertSessionHas('aviso');

        self::assertSame(1, DB::table('integration_connections')
            ->where('uuid', $this->uuid)->count());
    }

    /** Y una revocada tampoco cuenta como «nunca se usó»: la historia se queda. */
    public function test_una_credencial_revocada_sigue_impidiendo_el_borrado(): void
    {
        Integraciones::guardarSecreto($this->conexionId, 'password', 'clave-de-prueba',
            (int) $this->usuarioCon('admin')->id);
        Integraciones::revocarSecreto($this->conexionId, 'password', 'Prueba.');

        self::assertNotNull(Integraciones::porQueNoSeBorra($this->uuid));
    }

    /** Retirar conexiones necesita el mismo permiso que ponerlas. */
    public function test_borrar_una_conexion_exige_permiso(): void
    {
        $this->actingAs($this->usuarioCon('finance'))
            ->delete(route('integraciones.borrar', $this->uuid))
            ->assertForbidden();

        self::assertSame(1, DB::table('integration_connections')
            ->where('uuid', $this->uuid)->count());
    }

    // ------------------------------------ cada proveedor pide lo suyo (L-3b)

    /** SUNAT declara UNA clase, y es la que `Comprobantes` pide por su nombre. */
    public function test_sunat_declara_la_clave_sol_y_solo_esa(): void
    {
        $proveedorId = (int) DB::table('integration_providers')->where('code', 'sunat')->value('id');

        $admitidas = Integraciones::clasesAdmitidas($proveedorId);

        self::assertSame(['password'], array_keys($admitidas));
        self::assertStringContainsString('SOL', $admitidas['password']);
    }

    /**
     * **La que cierra `T-132`.** Guardar la clase equivocada ya no se acepta.
     *
     * Antes se aceptaba, y además **apagaba el aviso rojo**: la conexión quedaba
     * pareciendo configurada y la emisión se estrellaba al primer comprobante.
     */
    public function test_una_clase_que_el_proveedor_no_usa_se_rechaza(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('integraciones.credencial', $this->uuid), [
                'kind' => 'api_key', 'secreto' => 'clave-que-no-toca',
            ])
            ->assertSessionHasErrors('kind');

        self::assertSame(0, DB::table('integration_credentials')
            ->where('integration_connection_id', $this->conexionId)->count());
    }

    /** Y la que sí usa entra, y apaga el aviso. */
    public function test_la_clase_que_el_proveedor_usa_entra_y_apaga_el_aviso(): void
    {
        self::assertNotSame([], Integraciones::clasesQueFaltan(
            $this->conexionId, (int) Integraciones::porUuid($this->uuid)->integration_provider_id,
        ));

        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('integraciones.credencial', $this->uuid), [
                'kind' => 'password', 'secreto' => 'clave-sol-de-prueba',
            ])
            ->assertSessionHasNoErrors();

        self::assertSame([], Integraciones::clasesQueFaltan(
            $this->conexionId, (int) Integraciones::porUuid($this->uuid)->integration_provider_id,
        ));
    }

    /**
     * El aviso dice CUÁL falta, no «sin credencial».
     *
     * «Falta: Clave SOL del usuario secundario» se arregla. «Sin credencial» se
     * relee tres veces y se deja para mañana.
     */
    public function test_el_aviso_nombra_la_credencial_que_falta(): void
    {
        $texto = implode(' ', array_map(
            static fn (object $a): string => $a->texto, Integraciones::avisos(),
        ));

        self::assertStringContainsString('Clave SOL del usuario secundario', $texto);
    }

    /** Un proveedor SIN declarar no bloquea: se ofrece todo y se avisa (`DEC-190`). */
    public function test_un_proveedor_sin_declarar_ofrece_el_catalogo_entero(): void
    {
        $proveedorId = (int) DB::table('integration_providers')->where('code', 'sunat')->value('id');
        DB::table('integration_provider_credentials')
            ->where('integration_provider_id', $proveedorId)->delete();

        self::assertSame(
            array_keys(Integraciones::CLASES),
            array_keys(Integraciones::clasesAdmitidas($proveedorId)),
        );

        // Y guardar sigue siendo posible: nadie se queda sin poder configurar.
        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('integraciones.credencial', $this->uuid), [
                'kind' => 'api_key', 'secreto' => 'clave-cualquiera',
            ])
            ->assertSessionHasNoErrors();
    }

    /** La pantalla enseña la etiqueta de verdad, no «Contraseña» a secas. */
    public function test_la_pantalla_ensena_la_etiqueta_declarada(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('integraciones.index'))
            ->assertOk()
            ->assertSee('Clave SOL del usuario secundario', false);
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

    /**
     * Y puesta **la que el proveedor pide**, ese aviso desaparece.
     *
     * Esta prueba guardaba una `api_key` y afirmaba que el aviso se apagaba.
     * O sea que **tenía escrito el defecto de `T-132` como si fuera lo
     * correcto**: cualquier credencial callaba el rojo, incluida la que SUNAT
     * no usa. Pasaba en verde y por eso el agujero sobrevivió tantas
     * iteraciones. Ahora guarda la clave SOL, que es lo que `Comprobantes` lee.
     */
    public function test_puesta_la_credencial_el_aviso_desaparece(): void
    {
        $admin = $this->usuarioCon('admin');
        Integraciones::guardarSecreto($this->conexionId, 'password', 'clave-sol-1234', (int) $admin->id);

        $this->actingAs($admin)->get(route('configuracion'))
            ->assertDontSee('la primera llamada de verdad saldrá sin clave');
    }

    /**
     * **La otra mitad, y la que de verdad guarda el agujero.** Una credencial
     * de la clase EQUIVOCADA no apaga nada.
     *
     * Es el escenario caro: la conexión queda con una credencial guardada, la
     * pantalla parece configurada, y el fallo aparece en el primer comprobante
     * de verdad. Se entra por el servicio y no por el formulario a propósito:
     * el formulario ya no la ofrece, así que probar por ahí no demostraría que
     * el AVISO mira la clase.
     */
    public function test_una_credencial_de_otra_clase_no_apaga_el_aviso(): void
    {
        $admin = $this->usuarioCon('admin');
        Integraciones::guardarSecreto($this->conexionId, 'api_key', 'clave-1234', (int) $admin->id);

        $this->actingAs($admin)->get(route('configuracion'))
            ->assertSee('la primera llamada de verdad saldrá sin clave')
            ->assertSee('Clave SOL del usuario secundario', false);
    }
}
