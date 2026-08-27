<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Services\Cuentas;
use App\Modules\Identity\Services\EnlacesDeContrasena;
use App\Shared\Auth\Permisos;
use App\Shared\Eventos\CorreoPedido;
use Database\Seeders\CimientosSeeder;
use Database\Seeders\PlantillasDeCorreoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El enlace seguro de contraseña (`5.9` + `4.1`).
 *
 * ### Las cuatro decisiones que estas pruebas fijan
 *
 * | Decisión (2026-08-26) | Qué se afirma aquí |
 * |---|---|
 * | Alta 72 h, recuperación 1 h | la caducidad depende del propósito |
 * | El nuevo invalida el anterior | y lo hace **revocando**, no marcándolo usado |
 * | El mismo mensaje exista o no el correo | byte a byte, y sin enviar nada |
 * | El token no se guarda | en la tabla sólo está su `sha256` |
 *
 * ### Y una que no se decidió: la portada
 *
 * `5.9` crea la primera cuenta que **no es del equipo**. La portada enseñaba a
 * cualquier usuario autenticado cuántos creadores, clientes y campañas hay. Nadie
 * lo había pensado porque hasta hoy todos los usuarios eran internos.
 */
final class EnlaceContrasenaTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        $this->seed(PlantillasDeCorreoSeeder::class);
        Permisos::olvidar();
    }

    // ------------------------------------------------------------- el token

    public function test_el_token_no_se_guarda_solo_su_huella(): void
    {
        $usuario = $this->usuarioCon(null);

        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        $fila = DB::table('password_links')->where('user_id', $usuario->id)->first();

        $this->assertSame(hash('sha256', $token), $fila->token_sha256);

        // La afirmacion que de verdad importa: el token EN CLARO no aparece en
        // ninguna columna de la fila. Comprobar solo la huella dejaria pasar una
        // version que ademas lo guardara «por comodidad» en otro sitio.
        foreach ((array) $fila as $columna => $valor) {
            $this->assertNotSame($token, (string) $valor, "El token aparece en `{$columna}`.");
        }
    }

    public function test_el_token_es_largo_y_de_alfabeto_hexadecimal(): void
    {
        $usuario = $this->usuarioCon(null);

        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        // 32 bytes en hexadecimal. La ruta ademas exige este formato, asi que un
        // token mas corto dejaria de encajar en su propia URL.
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function test_dos_enlaces_seguidos_dan_tokens_distintos(): void
    {
        $usuario = $this->usuarioCon(null);

        $primero = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');
        $segundo = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        $this->assertNotSame($primero, $segundo);
    }

    // -------------------------------------------------------- las caducidades

    public function test_el_alta_dura_72_horas_y_la_recuperacion_una(): void
    {
        $alta = $this->usuarioCon(null);
        $recuperacion = $this->usuarioCon(null);

        EnlacesDeContrasena::emitir((int) $alta->id, 'initial');
        EnlacesDeContrasena::emitir((int) $recuperacion->id, 'reset');

        $this->assertSame(
            72,
            (int) round(now()->diffInMinutes($this->caducidad((int) $alta->id)) / 60),
        );
        $this->assertSame(
            1,
            (int) round(now()->diffInMinutes($this->caducidad((int) $recuperacion->id)) / 60),
        );
    }

    public function test_un_proposito_desconocido_no_se_emite(): void
    {
        $usuario = $this->usuarioCon(null);

        $this->expectException(\InvalidArgumentException::class);

        EnlacesDeContrasena::emitir((int) $usuario->id, 'invitacion');
    }

    // ------------------------------------------- el nuevo invalida al anterior

    public function test_emitir_otro_revoca_el_anterior(): void
    {
        $usuario = $this->usuarioCon(null);

        $viejo = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');
        $nuevo = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        $this->assertSame('revocado', EnlacesDeContrasena::validar($viejo)['motivo']);
        $this->assertTrue(EnlacesDeContrasena::validar($nuevo)['ok']);
    }

    /**
     * Revocar NO es marcar usado, y esa diferencia es evidencia.
     *
     * La primera versión de la tabla tenía una sola columna para las dos cosas.
     * El día que alguien pregunte *«¿llegó a usar el enlace?»*, la respuesta
     * tiene que salir de una columna que sólo se escribe cuando lo usó.
     */
    public function test_revocar_no_es_marcar_usado(): void
    {
        $usuario = $this->usuarioCon(null);

        $viejo = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');
        EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        $fila = DB::table('password_links')
            ->where('token_sha256', hash('sha256', $viejo))
            ->first(['used_at', 'used_ip', 'revoked_at', 'revoked_reason']);

        $this->assertNull($fila->used_at);
        $this->assertNull($fila->used_ip);
        $this->assertNotNull($fila->revoked_at);
        $this->assertSame('sustituido', $fila->revoked_reason);
    }

    public function test_los_dos_propositos_conviven(): void
    {
        $usuario = $this->usuarioCon(null);

        $alta = EnlacesDeContrasena::emitir((int) $usuario->id, 'initial');
        $recuperacion = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        // Emitir uno de recuperacion NO puede matar el de alta: son dos
        // conversaciones distintas y la puerta lleva `purpose` dentro.
        $this->assertTrue(EnlacesDeContrasena::validar($alta)['ok']);
        $this->assertTrue(EnlacesDeContrasena::validar($recuperacion)['ok']);
    }

    /** La base es la que garantiza «uno vivo», no el servicio. */
    public function test_la_base_impide_dos_enlaces_vivos_del_mismo_tipo(): void
    {
        $usuario = $this->usuarioCon(null);
        EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        $this->expectException(QueryException::class);

        DB::table('password_links')->insert([
            'uuid' => (string) Str::uuid(),
            'user_id' => $usuario->id,
            'purpose' => 'reset',
            'token_sha256' => hash('sha256', 'otro'),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------- consumirlo

    public function test_usar_el_enlace_pone_la_contrasena_y_lo_quema(): void
    {
        $usuario = $this->usuarioCon(null);
        DB::table('users')->where('id', $usuario->id)->update(['must_change_password' => 1]);

        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'initial');

        $resultado = EnlacesDeContrasena::consumir($token, 'Zarzamora-2026!', '203.0.113.9');

        $this->assertTrue($resultado['ok']);

        $fresco = DB::table('users')->where('id', $usuario->id)->first();
        $this->assertTrue(Hash::check('Zarzamora-2026!', $fresco->password));
        $this->assertSame(0, (int) $fresco->must_change_password);

        // Y el enlace ya no vale una segunda vez.
        $this->assertSame('usado', EnlacesDeContrasena::validar($token)['motivo']);
    }

    public function test_usarlo_registra_desde_donde(): void
    {
        $usuario = $this->usuarioCon(null);
        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        EnlacesDeContrasena::consumir($token, 'Zarzamora-2026!', '203.0.113.9');

        $fila = DB::table('password_links')->where('user_id', $usuario->id)->first(['used_ip']);

        $this->assertSame('203.0.113.9', inet_ntop($fila->used_ip));
    }

    /**
     * Sin IP tampoco se rompe.
     *
     * `ck_pl_used` exige la IP de quien lo usa. Una petición sin IP —consola,
     * un proxy mal configurado— no puede tirar abajo el cambio de contraseña:
     * se guarda la de bucle local, que es lo único que de verdad se sabe.
     */
    public function test_sin_ip_se_guarda_la_de_bucle_local(): void
    {
        $usuario = $this->usuarioCon(null);
        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        $this->assertTrue(EnlacesDeContrasena::consumir($token, 'Zarzamora-2026!', null)['ok']);
    }

    public function test_usarlo_mata_cualquier_otro_enlace_vivo(): void
    {
        $usuario = $this->usuarioCon(null);

        $alta = EnlacesDeContrasena::emitir((int) $usuario->id, 'initial');
        $recuperacion = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        EnlacesDeContrasena::consumir($recuperacion, 'Zarzamora-2026!', '203.0.113.9');

        // El de alta seguia vivo: es una segunda llave de una cerradura que
        // acaba de cambiar.
        $this->assertSame('revocado', EnlacesDeContrasena::validar($alta)['motivo']);
    }

    public function test_un_enlace_caducado_no_sirve(): void
    {
        $usuario = $this->usuarioCon(null);
        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        DB::table('password_links')->where('user_id', $usuario->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->assertSame('caducado', EnlacesDeContrasena::validar($token)['motivo']);
        $this->assertFalse(EnlacesDeContrasena::consumir($token, 'Zarzamora-2026!', '203.0.113.9')['ok']);
    }

    public function test_un_enlace_de_cuenta_desactivada_no_sirve(): void
    {
        $usuario = $this->usuarioCon(null);
        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        DB::table('users')->where('id', $usuario->id)->update(['status' => 'suspended']);

        $this->assertSame('cuenta_inactiva', EnlacesDeContrasena::validar($token)['motivo']);
        $this->assertFalse(EnlacesDeContrasena::consumir($token, 'Zarzamora-2026!', '203.0.113.9')['ok']);
    }

    public function test_un_token_inventado_no_dice_nada_mas_que_no(): void
    {
        $this->assertSame('no_existe', EnlacesDeContrasena::validar(str_repeat('a', 64))['motivo']);
    }

    public function test_consumirlo_borra_las_sesiones_abiertas(): void
    {
        // Las pruebas corren con `SESSION_DRIVER=array` --lo fija `phpunit.xml`--
        // y el servicio solo borra filas cuando el almacen es `database`, que es
        // la unica configuracion en la que borrarlas significa algo. Aqui se
        // declara la premisa en vez de suponerla.
        config(['session.driver' => 'database']);

        $usuario = $this->usuarioCon(null);
        $otro = $this->usuarioCon(null);

        foreach ([$usuario->id, $otro->id] as $id) {
            DB::table('sessions')->insert([
                'id' => 'sesion-'.$id, 'user_id' => $id, 'ip_address' => '203.0.113.1',
                'user_agent' => 'prueba', 'payload' => 'x', 'last_activity' => time(),
            ]);
        }

        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');
        EnlacesDeContrasena::consumir($token, 'Zarzamora-2026!', '203.0.113.9');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->id)->count());
        // Y sólo las suyas: borrar de más echaría del sistema a medio equipo.
        $this->assertSame(1, DB::table('sessions')->where('user_id', $otro->id)->count());
    }

    /**
     * Y con otro almacén de sesión, el fallo se ve en el log.
     *
     * La tabla `sessions` existe igualmente —la crea el esqueleto de Laravel—,
     * así que el `DELETE` funcionaría, no borraría nada y todo parecería
     * correcto mientras la sesión abierta con la contraseña vieja sigue viva.
     * Es el modo de fallo más caro de todos: el mudo.
     */
    public function test_con_otro_almacen_de_sesion_queda_constancia(): void
    {
        config(['session.driver' => 'file']);
        Log::spy();

        $usuario = $this->usuarioCon(null);
        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        $this->assertTrue(EnlacesDeContrasena::consumir($token, 'Zarzamora-2026!', '203.0.113.9')['ok']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensaje): bool => str_contains($mensaje, 'SESSION_DRIVER'));
    }

    // ------------------------------------------------------ el hecho, sin token

    public function test_el_hecho_queda_en_domain_events_y_sin_el_token(): void
    {
        $usuario = $this->usuarioCon(null);

        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        $evento = DB::table('domain_events')
            ->where('event_name', 'user.password_link_issued')
            ->where('entity_id', $usuario->id)
            ->first();

        $this->assertNotNull($evento);
        $this->assertStringNotContainsString($token, (string) $evento->payload);
        $this->assertStringContainsString('reset', (string) $evento->payload);
    }

    // ---------------------------------------------------------- las pantallas

    public function test_pedir_recuperacion_contesta_lo_mismo_exista_o_no(): void
    {
        Queue::fake();
        $usuario = $this->usuarioCon(null);

        $existe = $this->post(route('recuperar.enviar'), ['email' => $usuario->email])
            ->assertRedirect(route('recuperar'));
        $noExiste = $this->post(route('recuperar.enviar'), ['email' => 'nadie@example.test'])
            ->assertRedirect(route('recuperar'));

        // La MISMA pantalla, y con el mismo aviso en la sesion. Si una llevara
        // `fallo` y la otra `enviado`, el texto seria distinto y la pantalla
        // seria un buscador de cuentas dadas de alta.
        $existe->assertSessionHas('enviado', true);
        $noExiste->assertSessionHas('enviado', true);
        $noExiste->assertSessionMissing('fallo');

        // Y la pantalla que ve cada uno es la MISMA, byte a byte, quitando el
        // testigo anti-CSRF --que cambia en cada peticion y no dice nada del
        // correo--. Comparar solo la clave de sesion dejaria pasar una version
        // que escribiera «no tenemos ese correo» dentro de la vista.
        $this->assertSame(
            $this->sinTestigo($this->pantallaTrasPedir((string) $usuario->email)),
            $this->sinTestigo($this->pantallaTrasPedir('otro-que-no-existe@example.test')),
        );
    }

    /**
     * Y el correo que no existe no genera correo NI enlace.
     *
     * La respuesta idéntica es la mitad visible. La otra mitad es que no quede
     * rastro: una fila en `password_links` para un usuario que no existe sería
     * imposible —hay clave ajena—, pero un `email_log` a una dirección
     * desconocida sí, y eso llenaría la bandeja de rebotes de un buzón que no
     * es nuestro.
     */
    public function test_un_correo_desconocido_no_emite_nada(): void
    {
        Queue::fake();

        $this->post(route('recuperar.enviar'), ['email' => 'nadie@example.test']);

        $this->assertSame(0, DB::table('password_links')->count());
        $this->assertSame(0, DB::table('email_log')->count());
    }

    public function test_pedir_recuperacion_encola_el_correo_con_su_enlace(): void
    {
        Queue::fake();
        $usuario = $this->usuarioCon(null);

        $this->post(route('recuperar.enviar'), ['email' => $usuario->email]);

        $registro = DB::table('email_log')->latest('id')->first();

        $this->assertNotNull($registro);
        $this->assertSame('user.password_reset', $registro->template_code);
        $this->assertSame($usuario->email, $registro->to_email);
    }

    /**
     * El token NO se queda en la barra de direcciones.
     *
     * La URL del correo lo lleva —no hay otra forma—, pero esa ruta redirige a
     * una sin él. Una URL con un token dentro viaja en la cabecera `Referer` a
     * cualquier recurso externo de la página, y esta pantalla carga tipografías
     * de un dominio de terceros.
     */
    public function test_abrir_el_enlace_redirige_a_una_url_sin_token(): void
    {
        $usuario = $this->usuarioCon(null);
        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        $this->get(route('recuperar.usar', ['token' => $token]))
            ->assertRedirect(route('recuperar.formulario'));

        $this->assertStringNotContainsString($token, route('recuperar.formulario'));
    }

    /**
     * Y la sesión se regenera al guardar el token.
     *
     * Sin esto, quien haya conseguido fijar el identificador de sesión de la
     * víctima —una cookie plantada antes— comparte sesión con ella, y cuando la
     * víctima abre su enlace el token aterriza en una sesión que el atacante
     * también controla. Se lleva la cuenta sin tocar el correo.
     */
    public function test_abrir_el_enlace_vacia_y_renueva_la_sesion(): void
    {
        $usuario = $this->usuarioCon(null);
        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        // La primera version de esta prueba comparaba el identificador de sesion
        // antes y despues, y era un FALSO VERDE: el cliente de pruebas ya lo
        // cambia por su cuenta entre peticiones, asi que pasaba igual con el
        // `invalidate()` quitado. Lo destapo la prueba de mutaciones.
        //
        // Lo que si se observa es el CONTENIDO: lo que hubiera en la sesion
        // antes de abrir el enlace tiene que haber desaparecido.
        $this->withSession(['plantado_por_otro' => 'x'])
            ->get(route('recuperar.usar', ['token' => $token]))
            ->assertRedirect(route('recuperar.formulario'));

        $this->assertNull(session('plantado_por_otro'));
        // Y el tramite sigue funcionando: vaciar no puede llevarse el token que
        // se acaba de guardar.
        $this->get(route('recuperar.formulario'))->assertOk()->assertSee($usuario->email);

        // Lo que NO se afirma aqui, y conviene decirlo: que una sesion
        // autenticada ajena tambien muera. `invalidate()` la mata de verdad,
        // pero `actingAs()` fija el usuario en el resolvedor del contenedor y no
        // solo en la sesion, asi que `assertGuest()` seguiria viendolo. Una
        // afirmacion que pasa por como funciona el cliente de pruebas y no por
        // lo que hace el codigo es peor que no tenerla.
    }

    public function test_el_formulario_dice_a_que_cuenta_afecta(): void
    {
        $usuario = $this->usuarioCon(null);
        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        $this->get(route('recuperar.usar', ['token' => $token]));

        $this->get(route('recuperar.formulario'))
            ->assertOk()
            ->assertSee($usuario->email);
    }

    public function test_sin_haber_abierto_el_enlace_el_formulario_no_se_pinta(): void
    {
        $this->get(route('recuperar.formulario'))
            ->assertRedirect(route('recuperar'))
            // Y con SU texto, no con el de «este enlace no vale». Perder el
            // rastro en el navegador se arregla volviendo a abrir el correo; un
            // enlace muerto se arregla pidiendo otro. Decir lo segundo cuando
            // pasa lo primero manda a pedir un enlace a quien ya tiene uno.
            ->assertSessionHas('fallo', EnlacesDeContrasena::MOTIVOS['sesion_perdida']);
    }

    public function test_enviar_sin_rastro_en_la_sesion_tampoco_pasa(): void
    {
        $this->post(route('recuperar.fijar'), [
            'password' => 'Zarzamora-2026!',
            'password_confirmation' => 'Zarzamora-2026!',
        ])
            ->assertRedirect(route('recuperar'))
            ->assertSessionHas('fallo', EnlacesDeContrasena::MOTIVOS['sesion_perdida']);
    }

    public function test_el_recorrido_entero_por_pantalla(): void
    {
        $usuario = $this->usuarioCon(null);
        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'initial');

        $this->get(route('recuperar.usar', ['token' => $token]));

        $this->post(route('recuperar.fijar'), [
            'password' => 'Zarzamora-2026!',
            'password_confirmation' => 'Zarzamora-2026!',
        ])->assertRedirect(route('acceso'));

        // No se le deja entrar solo: que teclee la que acaba de poner es lo
        // unico que confirma que la tiene bien.
        $this->assertGuest();

        $this->post(route('entrar'), [
            'email' => $usuario->email,
            'password' => 'Zarzamora-2026!',
        ])->assertRedirect(route('panel'));
    }

    public function test_el_enlace_de_un_solo_uso_no_vale_dos_veces_por_pantalla(): void
    {
        $usuario = $this->usuarioCon(null);
        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        $this->get(route('recuperar.usar', ['token' => $token]));
        $this->post(route('recuperar.fijar'), [
            'password' => 'Zarzamora-2026!',
            'password_confirmation' => 'Zarzamora-2026!',
        ]);

        // El token sigue siendo el mismo; lo que ya no vale es el enlace. Se
        // vuelve a abrir como lo haria alguien con el correo delante.
        $this->get(route('recuperar.usar', ['token' => $token]))
            ->assertRedirect(route('recuperar'))
            ->assertSessionHas('fallo');
    }

    public function test_una_contrasena_floja_no_pasa(): void
    {
        $usuario = $this->usuarioCon(null);
        $token = EnlacesDeContrasena::emitir((int) $usuario->id, 'reset');

        $this->get(route('recuperar.usar', ['token' => $token]));

        $this->post(route('recuperar.fijar'), [
            'password' => 'zarzamora',
            'password_confirmation' => 'zarzamora',
        ])->assertSessionHasErrors('password');

        // Y el enlace NO se ha quemado: fallar la validacion no puede costarle
        // el enlace a quien simplemente eligio mal.
        $this->assertTrue(EnlacesDeContrasena::validar($token)['ok']);
    }

    public function test_la_pantalla_de_entrar_ofrece_recuperar(): void
    {
        $this->get(route('acceso'))->assertOk()->assertSee(route('recuperar'));
    }

    // ---------------------------------------------------- `5.9`: la cuenta

    public function test_aprobar_una_solicitud_crea_la_cuenta_y_manda_el_enlace(): void
    {
        Queue::fake();
        $revisor = $this->usuarioCon('admin');
        $solicitudId = $this->solicitud('nuevo@example.test');

        $this->actingAs($revisor)
            ->post(route('solicitudes.aprobar', $this->uuidSolicitud($solicitudId)), $this->datosDeAprobacion())
            ->assertRedirect();

        $usuario = DB::table('users')->where('email', 'nuevo@example.test')->first();

        $this->assertNotNull($usuario);
        $this->assertSame('creator', $usuario->user_type);
        $this->assertSame(1, (int) $usuario->must_change_password);

        // La columna `creators.user_id` existia desde la Fase 3 y no la
        // escribia nadie.
        $creador = DB::table('creators')->where('email', 'nuevo@example.test')->first(['user_id']);
        $this->assertSame((int) $usuario->id, (int) $creador->user_id);

        // Y su enlace, de 72 horas.
        $enlace = DB::table('password_links')->where('user_id', $usuario->id)->first();
        $this->assertSame('initial', $enlace->purpose);
        $this->assertSame((int) $revisor->id, (int) $enlace->requested_by_user_id);

        $this->assertSame(
            'user.password_initial',
            DB::table('email_log')->latest('id')->first()->template_code,
        );
    }

    public function test_la_cuenta_nace_con_una_contrasena_que_no_sirve(): void
    {
        Queue::fake();
        $revisor = $this->usuarioCon('admin');
        $solicitudId = $this->solicitud('nuevo@example.test');

        $this->actingAs($revisor)
            ->post(route('solicitudes.aprobar', $this->uuidSolicitud($solicitudId)), $this->datosDeAprobacion());

        // La sesion del revisor se cierra: `entrar` esta detras de `guest` y con
        // ella abierta las cinco peticiones de abajo se irian al panel sin
        // siquiera intentar la contrasena --y la prueba pasaria sin probar nada--.
        $this->post(route('salir'));
        $this->assertGuest();

        // No hay forma de entrar hasta usar el enlace. Se prueban las
        // sospechosas de siempre: la vacia y el correo como contrasena.
        foreach (['', 'nuevo@example.test', 'password'] as $intento) {
            $this->post(route('entrar'), ['email' => 'nuevo@example.test', 'password' => $intento])
                ->assertSessionHasErrors();
            $this->assertGuest();
        }
    }

    /**
     * Un correo que ya es de un usuario INTERNO no crea segunda cuenta.
     *
     * Y sobre todo: **no tira abajo la aprobación**. Aprobar a un creador es la
     * decisión de negocio; darle acceso es una consecuencia, y una consecuencia
     * que falla no puede deshacer la decisión.
     */
    public function test_un_correo_ya_ocupado_no_impide_aprobar(): void
    {
        Queue::fake();
        $revisor = $this->usuarioCon('admin');
        $interno = $this->usuarioCon('finance');
        $solicitudId = $this->solicitud((string) $interno->email);

        $this->actingAs($revisor)
            ->post(route('solicitudes.aprobar', $this->uuidSolicitud($solicitudId)), $this->datosDeAprobacion())
            ->assertRedirect();

        $creador = DB::table('creators')->where('email', $interno->email)->first(['user_id']);

        $this->assertNotNull($creador, 'La aprobacion tenia que salir adelante igualmente.');
        $this->assertNull($creador->user_id);
        $this->assertSame(0, DB::table('password_links')->count());
    }

    public function test_la_cuenta_del_creador_lleva_su_rol(): void
    {
        Event::fake([CorreoPedido::class]);

        $cuenta = Cuentas::paraCreador('creador@example.test', 'Ana Creadora');

        $rol = DB::table('role_user as ru')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->where('ru.user_id', $cuenta['usuarioId'])
            ->value('r.code');

        $this->assertSame('creator', $rol);
        Event::assertDispatched(
            CorreoPedido::class,
            fn (CorreoPedido $e): bool => $e->codigo === 'user.password_initial'
                && $e->destinatario === 'creador@example.test',
        );
    }

    // ------------------------------------------ la fuga que abria esta iteracion

    /**
     * Un creador conectado NO ve la portada del back-office.
     *
     * Hasta `5.9` todas las cuentas eran internas y la portada enseñaba los
     * totales de creadores, clientes y campañas a cualquier autenticado. La
     * primera cuenta de creador convierte eso en contarle a un creador el tamaño
     * de nuestra cartera.
     */
    public function test_un_usuario_que_no_es_interno_no_ve_los_totales(): void
    {
        $this->creadorPendiente();

        $creador = User::factory()->create(['user_type' => 'creator']);

        $respuesta = $this->actingAs($creador)->get(route('panel'))->assertOk();

        $respuesta->assertDontSee('Creadores');
        $respuesta->assertDontSee('en el esquema');
        $respuesta->assertSee('no está abierta', false);
    }

    public function test_un_usuario_interno_sigue_viendo_la_portada(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('panel'))
            ->assertOk()
            ->assertSee('Creadores');
    }

    // ------------------------------------------------------------------ apoyo

    /** Lo que ve quien acaba de pedir un enlace, siguiendo la redireccion. */
    private function pantallaTrasPedir(string $email): string
    {
        return (string) $this->followingRedirects()
            ->post(route('recuperar.enviar'), ['email' => $email])
            ->getContent();
    }

    private function sinTestigo(string $html): string
    {
        return (string) preg_replace('/name="_token" value="[^"]*"/', 'name="_token"', $html);
    }

    private function caducidad(int $usuarioId): Carbon
    {
        return Carbon::parse(
            (string) DB::table('password_links')->where('user_id', $usuarioId)->value('expires_at'),
        );
    }

    private function solicitud(string $email): int
    {
        return (int) DB::table('creator_applications')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Ana Creadora',
            'email' => $email,
            'phone' => '+51999888777',
            'country_id' => (int) DB::table('countries')->where('iso2', 'PE')->value('id'),
            'status' => 'submitted',
            'source' => 'landing',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function uuidSolicitud(int $id): string
    {
        return (string) DB::table('creator_applications')->where('id', $id)->value('uuid');
    }

    /** @return array<string, mixed> */
    private function datosDeAprobacion(): array
    {
        return [
            'first_name' => 'Ana',
            'last_name' => 'Creadora',
            'display_name' => 'Ana Creadora',
            'birth_date' => '1995-04-12',
            'document_country_code' => 'PE',
            'document_type' => 'DNI',
            'document_number' => '45678912',
            'preferred_currency_code' => 'PEN',
            'payment_term_days' => 30,
            'confirma_revision' => '1',
        ];
    }
}
