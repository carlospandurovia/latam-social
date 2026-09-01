<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Dónde aterriza quien acaba de entrar (`T-80`).
 *
 * ### El fallo, tal y como se reportó
 *
 * > «el entrar me lleva a NOT FOUND»
 *
 * La contraseña era correcta y la sesión se abría. Lo que fallaba era el
 * destino: `redirect()->intended()` obedece a la dirección que la sesión tenga
 * guardada **aunque esa dirección ya no exista**, y desde `9.21a` hay 149 que no
 * existen — la mudanza a `/backoffice` las movió todas.
 *
 * Toda sesión abierta antes de desplegar `9.21a` lleva guardado un `/panel`, un
 * `/creadores` o un `/clientes` que hoy son un 404. **Le pasa a todo el mundo el
 * día del despliegue, y a cada uno una sola vez**, que es la peor forma de un
 * fallo: se arregla solo antes de que nadie lo pueda reproducir, y mientras
 * tanto el primer contacto de cada usuario con el sistema es un error.
 *
 * ### Por qué la prueba ataca la sesión y no la pantalla
 *
 * Porque el estado que provoca el fallo es exactamente ése: una clave en la
 * sesión con una dirección vieja. Escribirla a mano es la única forma de
 * reproducir el día del despliegue sin viajar en el tiempo.
 */
final class AterrizajeTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private const CLAVE = 'Zarzamora-2026!';

    private string $correo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        // `usuarioCon()` crea un usuario NUEVO en cada llamada, asi que se
        // guarda el correo: pedirlo dos veces daria dos personas distintas y la
        // contrasena que se acaba de fijar seria la de la otra.
        $usuario = $this->usuarioCon('admin');
        $this->correo = (string) $usuario->email;

        DB::table('users')->where('id', $usuario->id)->update([
            'password' => Hash::make(self::CLAVE),
            'must_change_password' => 0,
            'status' => 'active',
        ]);
    }

    /** **La del fallo reportado.** Una dirección de antes de la mudanza no manda. */
    public function test_una_direccion_guardada_que_ya_no_existe_no_manda_a_un_404(): void
    {
        $this->withSession(['url.intended' => 'http://localhost/panel'])
            ->post(route('entrar'), ['email' => $this->correo(), 'password' => self::CLAVE])
            ->assertRedirect(route('panel'));

        $this->assertSame('/backoffice/panel', parse_url(route('panel'), PHP_URL_PATH));
    }

    /**
     * Y una que SÍ existe se respeta.
     *
     * Sin esta mitad, un arreglo que mandara siempre al panel también pasaría la
     * de arriba — y se llevaría por delante lo único que `intended()` sirve
     * para hacer: devolver a alguien donde estaba cuando se le pidió entrar.
     */
    public function test_una_direccion_que_sigue_existiendo_se_respeta(): void
    {
        $this->withSession(['url.intended' => route('creadores.index')])
            ->post(route('entrar'), ['email' => $this->correo(), 'password' => self::CLAVE])
            ->assertRedirect(route('creadores.index'));
    }

    /** Una dirección de otro dominio no se obedece: sería un redirect abierto. */
    public function test_una_direccion_de_fuera_no_se_obedece(): void
    {
        $this->withSession(['url.intended' => 'https://ejemplo-malicioso.test/cobrar'])
            ->post(route('entrar'), ['email' => $this->correo(), 'password' => self::CLAVE])
            ->assertRedirect(route('panel'));
    }

    /** Sin nada guardado, al panel. Es el caso normal. */
    public function test_sin_nada_guardado_va_al_panel(): void
    {
        $this->post(route('entrar'), ['email' => $this->correo(), 'password' => self::CLAVE])
            ->assertRedirect(route('panel'));
    }

    /**
     * Una ruta que existe **con otro verbo** tampoco vale.
     *
     * `salir` es `POST`. Mandar allí un `GET` es un 405 en la cara de quien
     * acaba de teclear su contraseña, que para el caso es lo mismo que un 404.
     */
    public function test_una_ruta_que_solo_acepta_post_no_vale_como_destino(): void
    {
        $this->withSession(['url.intended' => route('salir')])
            ->post(route('entrar'), ['email' => $this->correo(), 'password' => self::CLAVE])
            ->assertRedirect(route('panel'));
    }

    private function correo(): string
    {
        return $this->correo;
    }
}
