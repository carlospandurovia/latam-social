<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
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
 * ### Y la segunda mitad, que era la de verdad (`T-81`)
 *
 * La sesión guardada explicaba una parte. La otra estaba en `bootstrap/app.php`,
 * con **dos direcciones escritas a mano**:
 *
 * ```php
 * $middleware->redirectGuestsTo('/entrar');
 * $middleware->redirectUsersTo('/panel');   // <- rota desde 9.21a
 * ```
 *
 * Quien **ya tenía sesión** y pulsaba «Entrar» caía en el middleware `guest`,
 * que lo mandaba a `/panel` — que desde la mudanza a `/backoffice` no existe.
 * Ni la regla de `docs/08` (que habla de **vistas**) ni la búsqueda que se hizo
 * en `9.21a` (`app/`, `resources/`, `config/`) miraban `bootstrap/`.
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

    /**
     * Direcciones escritas a mano que NO pasan por el enrutador, con su motivo.
     *
     * Se escriben una a una, como las rutas abiertas de `tools/pruebas/RUTAS-ABIERTAS`:
     * una excepción sin motivo escrito es una excepción que crece.
     *
     * @var array<string, string>
     */
    private const FUERA_DEL_ENRUTADOR = [
        // El disco público de Laravel: lo sirve un enlace simbólico desde
        // `public/storage`, sin que ninguna ruta lo declare.
        '/storage' => 'El disco publico, servido por enlace simbolico.',
    ];

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

    // ------------------------------------------------------- los dos muros

    /**
     * **La del segundo fallo (`T-81`).** Quien ya entró y vuelve al formulario.
     *
     * Es el camino exacto que se reportó: sesión abierta, clic en «Entrar», y
     * un 404. Lo decide `redirectUsersTo` en `bootstrap/app.php`, que es un
     * archivo que ninguna prueba tocaba —`sincronizar` ni siquiera lo copiaba—.
     */
    public function test_quien_ya_entro_y_vuelve_al_formulario_va_al_panel(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('acceso'))
            ->assertRedirect(route('panel'));
    }

    /** Y quien no ha entrado, al formulario. La otra mitad del mismo sitio. */
    public function test_quien_no_ha_entrado_va_al_formulario(): void
    {
        $this->get(route('panel'))->assertRedirect(route('acceso'));
    }

    /**
     * **El guardián.** Ninguna dirección escrita a mano fuera de las rutas.
     *
     * `docs/08` prohíbe escribir URLs en las vistas, y esa regla hizo que la
     * mudanza de `9.21a` costara una línea. Lo que no cubría es todo lo demás:
     * `bootstrap/app.php` tenía dos, y una llevaba rota desde entonces.
     *
     * Esta prueba busca cadenas con pinta de dirección propia en `bootstrap/` y
     * `config/` y exige que **el enrutador las reconozca**. No prohíbe
     * escribirlas —a veces no hay alternativa— pero sí que apunten a la nada.
     */
    public function test_ninguna_direccion_escrita_a_mano_apunta_a_la_nada(): void
    {
        $muertas = [];

        foreach (self::ficherosDeArranque() as $fichero) {
            $texto = (string) file_get_contents($fichero);

            // Se ignora lo que va dentro de un comentario: esta misma prueba y
            // las cabeceras que EXPLICAN el fallo nombran `/panel` a propósito.
            $texto = (string) preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $texto);

            preg_match_all("~'(/[a-z0-9][a-z0-9/_-]*)'~i", $texto, $encontradas);

            foreach ($encontradas[1] as $camino) {
                if (isset(self::FUERA_DEL_ENRUTADOR[$camino])) {
                    continue;
                }

                if (!self::laReconoceElEnrutador($camino)) {
                    $muertas[] = basename($fichero).': '.$camino;
                }
            }
        }

        $this->assertSame([], $muertas, implode("\n", array_merge(
            ['Direcciones escritas a mano que el enrutador no reconoce:'],
            $muertas,
            ['Use route(\'nombre\'): es lo que hizo que mudar 149 pantallas costara una línea.'],
        )));
    }

    /** @return list<string> */
    private static function ficherosDeArranque(): array
    {
        $ficheros = array_merge(
            glob(base_path('bootstrap/*.php')) ?: [],
            glob(config_path('*.php')) ?: [],
        );

        // `/up` la declara el propio framework y no pasa por el enrutador de la
        // aplicación; `providers.php` no lleva direcciones.
        return array_values(array_filter(
            $ficheros,
            static fn (string $f): bool => !str_ends_with($f, 'providers.php'),
        ));
    }

    private static function laReconoceElEnrutador(string $camino): bool
    {
        // Rutas de disco, comodines de configuración y cosas que no son URL.
        if (str_contains($camino, '//') || preg_match('~\.(php|css|js|json|log)$~', $camino)) {
            return true;
        }

        try {
            Route::getRoutes()
                ->match(Request::create($camino, 'GET'));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function correo(): string
    {
        return $this->correo;
    }
}
