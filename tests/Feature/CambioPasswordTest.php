<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cambio obligatorio de contraseña (`T-23`).
 *
 * `usuarios:crear` escribía `must_change_password = 1` desde 3.1 y **nadie lo
 * leía**: no había middleware ni pantalla. El administrador que daba de alta a
 * la persona de finanzas conocía su contraseña indefinidamente.
 *
 * Y eso no es sólo higiene: la base **exige dos personas distintas** para
 * aprobar un perfil fiscal (`ck_ctp_segregation`) y para verificar un medio de
 * pago (`ck_cpm_segregation`). Esa garantía se apoya en que dos `user_id`
 * distintos sean dos personas distintas.
 *
 * La prueba que justifica la iteración es
 * `test_repetir_la_misma_contrasena_no_cuenta`: sin esa regla, teclear la
 * temporal dos veces limpia la marca y deja válida la contraseña que conoce el
 * administrador. El requisito quedaría cumplido en la base de datos y sin
 * cumplir en la realidad.
 */
final class CambioPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPORAL = 'Temporal-2026!xyz';

    private const NUEVA = 'Bosque-Naranja-99!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
    }

    public function test_con_la_marca_puesta_no_se_llega_a_ningun_otro_sitio(): void
    {
        $this->actingAs($this->conMarca())
            ->get('/backoffice/panel')
            ->assertRedirect(route('contrasena'));
    }

    /**
     * Las tres excepciones del middleware. Sin ellas esto es un bucle de
     * redirecciones, que es la forma más rápida de dejar a alguien fuera de su
     * propia cuenta.
     */
    public function test_la_pantalla_de_cambio_y_salir_no_redirigen(): void
    {
        $usuario = $this->conMarca();

        $this->actingAs($usuario)->get('/backoffice/contrasena')->assertOk();
        $this->actingAs($usuario)->post('/backoffice/salir')->assertRedirect(route('acceso'));
    }

    public function test_cambiarla_limpia_la_marca_y_deja_pasar(): void
    {
        $usuario = $this->conMarca();

        $this->actingAs($usuario)
            ->put('/backoffice/contrasena', [
                'actual' => self::TEMPORAL,
                'password' => self::NUEVA,
                'password_confirmation' => self::NUEVA,
            ])
            ->assertRedirect(route('panel'));

        $fresco = User::find($usuario->id);

        $this->assertFalse((bool) $fresco->must_change_password);
        $this->assertTrue(Hash::check(self::NUEVA, $fresco->password), 'la nueva tiene que valer');
        $this->assertFalse(Hash::check(self::TEMPORAL, $fresco->password), 'la temporal tiene que dejar de valer');

        // Y ahora sí se llega al resto del sistema.
        $this->actingAs($fresco)->get('/backoffice/panel')->assertOk();
    }

    /**
     * **La prueba de la iteración.**
     *
     * Sin `different:actual`, teclear la temporal dos veces limpia la marca y
     * deja válida la contraseña que conoce quien creó la cuenta. Cumplido en la
     * base de datos, sin cumplir en la realidad.
     */
    public function test_repetir_la_misma_contrasena_no_cuenta(): void
    {
        $usuario = $this->conMarca();

        $this->actingAs($usuario)
            ->put('/backoffice/contrasena', [
                'actual' => self::TEMPORAL,
                'password' => self::TEMPORAL,
                'password_confirmation' => self::TEMPORAL,
            ])
            ->assertSessionHasErrors('password');

        $fresco = User::find($usuario->id);

        $this->assertTrue((bool) $fresco->must_change_password, 'la marca NO se puede limpiar asi');
        $this->assertTrue(Hash::check(self::TEMPORAL, $fresco->password));
    }

    /**
     * Se pide la contraseña actual aunque el cambio sea obligatorio: una sesión
     * abierta y desatendida no debe bastar para dejar fuera al dueño.
     */
    public function test_sin_la_contrasena_actual_no_se_cambia(): void
    {
        $usuario = $this->conMarca();

        $this->actingAs($usuario)
            ->put('/backoffice/contrasena', [
                'actual' => 'esta-no-es',
                'password' => self::NUEVA,
                'password_confirmation' => self::NUEVA,
            ])
            ->assertSessionHasErrors('actual');

        $this->assertTrue(Hash::check(self::TEMPORAL, User::find($usuario->id)->password));
    }

    public function test_una_contrasena_debil_se_rechaza(): void
    {
        $usuario = $this->conMarca();

        $this->actingAs($usuario)
            ->put('/backoffice/contrasena', [
                'actual' => self::TEMPORAL,
                'password' => 'corta1!',
                'password_confirmation' => 'corta1!',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue((bool) User::find($usuario->id)->must_change_password);
    }

    /**
     * Sin la marca, la pantalla sigue existiendo: cambiar la contraseña cuando
     * uno quiere no es un caso excepcional.
     */
    public function test_sin_la_marca_tambien_se_puede_cambiar(): void
    {
        $usuario = $this->conMarca(marcado: false);

        $this->actingAs($usuario)->get('/backoffice/panel')->assertOk();
        $this->actingAs($usuario)->get('/backoffice/contrasena')->assertOk();

        $this->actingAs($usuario)
            ->put('/backoffice/contrasena', [
                'actual' => self::TEMPORAL,
                'password' => self::NUEVA,
                'password_confirmation' => self::NUEVA,
            ])
            ->assertSessionHas('exito');
    }

    /** La bitácora anota QUE se cambió, nunca a qué. */
    public function test_la_bitacora_no_guarda_la_contrasena(): void
    {
        $usuario = $this->conMarca();

        $this->actingAs($usuario)->put('/backoffice/contrasena', [
            'actual' => self::TEMPORAL,
            'password' => self::NUEVA,
            'password_confirmation' => self::NUEVA,
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_changed']);

        $anotado = (string) DB::table('audit_logs')->where('action', 'user.password_changed')->value('changes');

        $this->assertStringNotContainsString(self::NUEVA, $anotado);
        $this->assertStringNotContainsString(self::TEMPORAL, $anotado);
    }

    // ------------------------------------------------------------------ apoyo

    private function conMarca(bool $marcado = true): User
    {
        $usuario = User::factory()->create([
            'password' => Hash::make(self::TEMPORAL),
            'must_change_password' => $marcado,
        ]);

        DB::table('role_user')->insert([
            'user_id' => $usuario->id,
            'role_id' => DB::table('roles')->where('code', 'finance')->value('id'),
            'assigned_at' => now(),
        ]);
        Permisos::olvidar((int) $usuario->id);

        return $usuario;
    }
}
