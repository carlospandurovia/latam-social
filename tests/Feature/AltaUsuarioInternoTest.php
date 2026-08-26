<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Services\Cuentas;
use App\Modules\Identity\Services\EnlacesDeContrasena;
use App\Shared\Auth\Permisos;
use App\Shared\Eventos\CorreoPedido;
use Database\Seeders\CimientosSeeder;
use Database\Seeders\PlantillasDeCorreoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El alta de usuarios internos por enlace (`T-36`).
 *
 * ### Lo que cierra
 *
 * `BR-SEC-004` es 🔴: *«nunca se transmite una contraseña en texto claro por
 * ningún canal»*. Desde `5.9` se cumplía para los creadores y **no para los
 * usuarios internos**: `usuarios:crear` la pedía por consola y alguien se la
 * dictaba.
 *
 * Y son justo las cuentas donde más importa. La base **exige dos personas
 * distintas** para lo que toca dinero (`ck_ctp_segregation`, `ck_cpm_segregation`),
 * y esa garantía se apoya en que dos `user_id` distintos sean dos personas
 * distintas. Si el administrador conoce la credencial de la segunda, la
 * separación de funciones es una fila en una tabla y nada más.
 *
 * `must_change_password` era el parche: obligaba a cambiarla *después*, dejando
 * una ventana en la que dos personas la conocían. Ahora **no hay ventana**.
 */
final class AltaUsuarioInternoTest extends TestCase
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

    public function test_el_alta_crea_la_cuenta_y_manda_el_enlace(): void
    {
        Queue::fake();

        $this->artisan('usuarios:crear', [
            'nombre' => 'Ana Aprobadora', 'email' => 'ana@cts.pe', '--rol' => 'finance',
        ])->assertExitCode(0);

        $usuario = DB::table('users')->where('email', 'ana@cts.pe')->first();

        $this->assertNotNull($usuario);
        $this->assertSame('internal', $usuario->user_type);
        $this->assertSame(1, (int) $usuario->must_change_password);

        $enlace = DB::table('password_links')->where('user_id', $usuario->id)->first();
        $this->assertSame('initial', $enlace->purpose);

        $this->assertSame(
            'user.password_initial',
            DB::table('email_log')->latest('id')->first()->template_code,
        );
    }

    /**
     * **La afirmación que da sentido a todo lo demás.**
     *
     * La cuenta nace con el hash de 32 bytes aleatorios que no se guardan, no se
     * muestran y no se devuelven. Nadie puede entrar — tampoco quien la creó.
     */
    public function test_nadie_puede_entrar_hasta_que_su_dueno_use_el_enlace(): void
    {
        Queue::fake();
        $this->artisan('usuarios:crear', [
            'nombre' => 'Ana', 'email' => 'ana@cts.pe', '--rol' => 'finance',
        ]);

        // Se prueban las sospechosas de siempre. Lo que NO se puede afirmar en
        // caja negra es que la contrasena sea ALEATORIA: una prueba no puede
        // distinguir 32 bytes del generador criptografico de una constante que
        // no conoce. Se afirma la consecuencia observable --que no se entra-- y
        // la aleatoriedad se sostiene por lectura del codigo.
        // CINCO intentos y no mas: `/entrar` lleva `throttle:5,1`, y el sexto
        // devolveria un 429 sin errores de sesion --la prueba fallaria por el
        // limitador y no por la contrasena, que es un falso rojo igual de malo
        // que un falso verde--.
        foreach (['', 'ana@cts.pe', 'password', 'secreto', '123456'] as $intento) {
            $this->post(route('entrar'), ['email' => 'ana@cts.pe', 'password' => $intento])
                ->assertSessionHasErrors();
            $this->assertGuest();
        }
    }

    public function test_el_recorrido_entero_del_usuario_nuevo(): void
    {
        Queue::fake();
        $this->artisan('usuarios:crear', [
            'nombre' => 'Ana', 'email' => 'ana@cts.pe', '--rol' => 'finance',
        ]);

        $usuario = DB::table('users')->where('email', 'ana@cts.pe')->first(['id']);
        $token = $this->tokenDe((int) $usuario->id);

        $this->get(route('recuperar.usar', ['token' => $token]));
        $this->post(route('recuperar.fijar'), [
            'password' => 'Zarzamora-2026!', 'password_confirmation' => 'Zarzamora-2026!',
        ])->assertRedirect(route('acceso'));

        $this->post(route('entrar'), ['email' => 'ana@cts.pe', 'password' => 'Zarzamora-2026!'])
            ->assertRedirect(route('panel'));

        // Y ya no le obliga a cambiarla otra vez: la puso ella.
        $this->assertSame(0, (int) DB::table('users')->where('id', $usuario->id)->value('must_change_password'));
    }

    public function test_el_rol_se_asigna_de_verdad(): void
    {
        Queue::fake();
        $this->artisan('usuarios:crear', [
            'nombre' => 'Ana', 'email' => 'ana@cts.pe', '--rol' => 'finance',
        ]);

        $rol = DB::table('role_user as ru')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->join('users as u', 'u.id', '=', 'ru.user_id')
            ->where('u.email', 'ana@cts.pe')
            ->value('r.code');

        $this->assertSame('finance', $rol);
    }

    /** Un rol inventado no crea nada: una cuenta sin rol entra y no ve nada. */
    public function test_un_rol_que_no_existe_no_crea_la_cuenta(): void
    {
        Queue::fake();

        $this->artisan('usuarios:crear', [
            'nombre' => 'Ana', 'email' => 'ana@cts.pe', '--rol' => 'jefazo',
        ])->assertExitCode(1);

        $this->assertSame(0, DB::table('users')->where('email', 'ana@cts.pe')->count());
    }

    /**
     * El servicio comprueba el rol **él mismo**, no sólo el comando.
     *
     * Sobrevivió una mutación aquí: quitar la guarda de `Cuentas` no ponía nada
     * en rojo, porque el comando ya valida el rol antes de llamar. Una guarda que
     * sólo existe en la capa de arriba es una guarda que se salta cualquier
     * llamada nueva — y `Cuentas` es la pieza que `F8` va a reutilizar para los
     * usuarios de cliente.
     */
    public function test_el_servicio_no_crea_una_cuenta_con_un_rol_que_no_existe(): void
    {
        Queue::fake();

        $resultado = Cuentas::paraInterno(
            'ana@cts.pe', 'Ana', 'jefazo',
        );

        $this->assertNull($resultado['usuarioId']);
        $this->assertSame('rol_desconocido', $resultado['motivo']);
        $this->assertSame(0, DB::table('users')->where('email', 'ana@cts.pe')->count());
    }

    public function test_un_correo_ya_ocupado_no_crea_una_segunda_cuenta(): void
    {
        Queue::fake();
        $existente = $this->usuarioCon('admin');

        $this->artisan('usuarios:crear', [
            'nombre' => 'Otra', 'email' => (string) $existente->email, '--rol' => 'finance',
        ])->assertExitCode(1);

        $this->assertSame(1, DB::table('users')->where('email', $existente->email)->count());
    }

    /**
     * Y el correo de un CREADOR tampoco vale para una cuenta interna.
     *
     * Sería la misma credencial para los dos lados del sistema, y la separación
     * de funciones del dinero se apoya en que cada cuenta sea una persona en un
     * papel.
     */
    public function test_el_correo_de_un_creador_no_vale_para_una_cuenta_interna(): void
    {
        Event::fake([CorreoPedido::class]);
        Cuentas::paraCreador('mixto@example.test', 'Un Creador');

        $this->artisan('usuarios:crear', [
            'nombre' => 'Un Interno', 'email' => 'mixto@example.test', '--rol' => 'finance',
        ])->assertExitCode(1);

        $this->assertSame(1, DB::table('users')->where('email', 'mixto@example.test')->count());
        $this->assertSame('creator',
            DB::table('users')->where('email', 'mixto@example.test')->value('user_type'));
    }

    // ------------------------------------------- reponer, ahora por enlace

    public function test_reponer_por_enlace_no_toca_la_contrasena_actual(): void
    {
        Queue::fake();
        $usuario = $this->usuarioCon('finance');
        $antes = (string) DB::table('users')->where('id', $usuario->id)->value('password');

        $this->artisan('usuarios:contrasena', [
            'email' => (string) $usuario->email, '--enlace' => true,
        ])->assertExitCode(0);

        // Deliberado: si el enlace no llega, la persona no se queda PEOR de como
        // estaba. La contrasena vieja sigue valiendo hasta que use el enlace.
        $this->assertSame($antes, (string) DB::table('users')->where('id', $usuario->id)->value('password'));
        $this->assertSame('reset',
            DB::table('password_links')->where('user_id', $usuario->id)->value('purpose'));
    }

    public function test_reponer_a_mano_sigue_existiendo_pero_avisa(): void
    {
        $usuario = $this->usuarioCon('finance');

        $this->artisan('usuarios:contrasena', [
            'email' => (string) $usuario->email, '--generar' => true,
        ])
            ->expectsOutputToContain('Lo normal es `--enlace`')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * El token en claro no se puede leer de la base —sólo está su huella—, así
     * que se recupera del evento. Es la misma limitación que tiene el sistema de
     * verdad, y probar contra ella es lo correcto.
     */
    private function tokenDe(int $usuarioId): string
    {
        $capturado = '';
        Event::listen(CorreoPedido::class, function (CorreoPedido $e) use (&$capturado): void {
            if (preg_match('#/contrasena/nueva/([a-f0-9]{64})#', (string) ($e->variables['enlace'] ?? ''), $m) === 1) {
                $capturado = $m[1];
            }
        });

        EnlacesDeContrasena::emitir($usuarioId, 'initial');

        return $capturado;
    }
}
