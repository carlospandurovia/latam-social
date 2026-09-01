<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Communication\Services\CuentaDeCorreo;
use App\Modules\Core\Services\Integraciones;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Con qué cuenta sale el correo (iteración 9.17g).
 *
 * ### Lo que fija
 *
 * **La precedencia, y que se vea.** Manda la cuenta guardada si hay una activa;
 * si no, el `.env`. No hay mezcla y no hay tercera opción. Media configuración
 * de cada sitio es la clase de cosa que produce «cambié el puerto y no pasó
 * nada», y eso se descubre a las dos horas.
 *
 * Y que **la contraseña no vuelva a salir**: entra cifrada por la puerta de
 * `9.17d` y ninguna lectura de pantalla la devuelve (`DEC-226`).
 */
final class CuentaDeCorreoTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $autorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        // Una credencial dice QUIEN la puso, y la foránea lo exige: el `1` de
        // usuario no existe en una base recién sembrada.
        $this->autorId = (int) $this->usuarioCon('admin')->id;
    }

    // ---------------------------------------------------------- precedencia

    /** Sin cuenta guardada manda el `.env`, y la pantalla lo dice. */
    public function test_sin_cuenta_guardada_manda_el_entorno(): void
    {
        $efecto = CuentaDeCorreo::enEfecto();

        $this->assertSame(CuentaDeCorreo::DEL_ENTORNO, $efecto['origen']);
        $this->assertNull(CuentaDeCorreo::vigente());
    }

    /** **La que más importa.** Con cuenta activa manda ella. */
    public function test_con_cuenta_guardada_manda_la_cuenta(): void
    {
        $this->guardarCuenta();

        $efecto = CuentaDeCorreo::enEfecto();

        $this->assertSame(CuentaDeCorreo::DE_LA_BASE, $efecto['origen']);
        $this->assertSame('smtp.ejemplo.test', $efecto['host']);
        $this->assertSame(587, $efecto['port']);
        $this->assertTrue($efecto['sale_de_aqui']);
    }

    /** Y `aplicar()` la mete de verdad en la configuración viva de Laravel. */
    public function test_aplicar_cambia_la_configuracion_viva(): void
    {
        $this->guardarCuenta();

        CuentaDeCorreo::aplicar();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.ejemplo.test', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('hola@latamsocial.test', config('mail.from.address'));
        $this->assertSame('Zarzamora-2026!', config('mail.mailers.smtp.password'));
    }

    /** Sin cuenta activa, `aplicar()` no toca nada: manda el `.env`. */
    public function test_sin_cuenta_aplicar_no_toca_nada(): void
    {
        config(['mail.mailers.smtp.host' => 'del-entorno.test']);

        CuentaDeCorreo::aplicar();

        $this->assertSame('del-entorno.test', config('mail.mailers.smtp.host'));
    }

    // ------------------------------------------------------------ el secreto

    /** La contraseña no vuelve a salir por ninguna lectura de pantalla. */
    public function test_la_contrasena_no_vuelve_a_salir(): void
    {
        $this->guardarCuenta();

        $cuenta = CuentaDeCorreo::vigente();
        $this->assertNotNull($cuenta);

        foreach ((array) $cuenta as $valor) {
            $this->assertStringNotContainsString('Zarzamora-2026!', (string) $valor);
        }

        foreach (CuentaDeCorreo::enEfecto() as $valor) {
            $this->assertStringNotContainsString('Zarzamora-2026!', (string) $valor);
        }
    }

    /**
     * Guardar sin contraseña no la borra.
     *
     * Obligarla en cada guardado haría que corregir un puerto exigiera volver a
     * teclearla, y eso acaba con la contraseña escrita en un papel.
     */
    public function test_guardar_sin_contrasena_no_la_borra(): void
    {
        $this->guardarCuenta();
        $conexionId = (int) CuentaDeCorreo::vigente()->id;

        $this->actingAs($this->usuarioCon('admin'))->post(route('correo.guardar'), [
            'name' => 'Correo de LATAM', 'host' => 'smtp.ejemplo.test', 'port' => 2525,
            'encryption' => 'tls', 'username' => 'buzon@latamsocial.test',
            'from_address' => 'hola@latamsocial.test', 'from_name' => 'LATAM Social',
        ])->assertRedirect(route('integraciones.index', ['p' => 'correo']));

        $this->assertSame(2525, (int) CuentaDeCorreo::vigente()->port);
        $this->assertSame('Zarzamora-2026!', Integraciones::secreto($conexionId, 'password'));
    }

    // ------------------------------------------------------------- la prueba

    /** El envío de prueba deja escrito que funcionó. */
    public function test_la_prueba_deja_escrito_que_funciono(): void
    {
        Mail::fake();
        $this->guardarCuenta();

        CuentaDeCorreo::probar('alguien@ejemplo.test');

        $this->assertNotNull(CuentaDeCorreo::vigente()?->last_success_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mail_settings.tested']);
    }

    /** Sin cuenta activa no hay nada que probar, y lo dice. */
    public function test_sin_cuenta_no_hay_nada_que_probar(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no hay ninguna cuenta/i');

        CuentaDeCorreo::probar('alguien@ejemplo.test');
    }

    /**
     * Y si el servidor no acepta, **queda escrito el fallo**.
     *
     * Se apunta a un puerto que rechaza al instante. El modo de fallo real de
     * este proyecto no es que el correo falle: es que falle y nadie lo sepa
     * hasta que un creador diga que no le llegó nada.
     */
    public function test_si_el_servidor_no_acepta_queda_escrito(): void
    {
        $this->guardarCuenta(['host' => '127.0.0.1', 'port' => 1, 'timeout_seconds' => 1]);

        try {
            CuentaDeCorreo::probar('alguien@ejemplo.test');
            $this->fail('Un servidor que rechaza no debería dar por buena la prueba.');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/no acept/i', $e->getMessage());
        }

        $cuenta = CuentaDeCorreo::vigente();
        $this->assertNotNull($cuenta?->last_error_at);
        $this->assertNotNull($cuenta->last_error_message);
    }

    // --------------------------------------------------------------- avisos

    /** Con el transporte en «log» el panel lo dice en rojo. */
    public function test_sin_cuenta_el_panel_lo_dice_en_rojo(): void
    {
        config(['mail.default' => 'log']);

        $this->assertStringContainsString('no sale de este servidor', $this->avisos('rojo'));
    }

    /** Y con cuenta activa, ese aviso desaparece. */
    public function test_con_cuenta_activa_el_aviso_desaparece(): void
    {
        config(['mail.default' => 'log']);
        $this->guardarCuenta();

        $this->assertStringNotContainsString('no sale de este servidor', $this->avisos('rojo'));
    }

    /** Una cuenta sin cifrar es roja: la contraseña viaja en claro. */
    public function test_una_cuenta_sin_cifrar_sale_en_rojo(): void
    {
        $this->guardarCuenta(['encryption' => null, 'port' => 25]);

        $this->assertStringContainsString('sin cifrar', $this->avisos('rojo'));
    }

    // -------------------------------------------------------------- pantalla

    public function test_la_pestana_pide_permiso_para_guardar(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post(route('correo.guardar'), [
                'name' => 'x', 'host' => 'smtp.ejemplo.test', 'port' => 587,
                'from_address' => 'hola@latamsocial.test', 'from_name' => 'LATAM',
            ])->assertStatus(403);
    }

    /**
     * 9.17i: el texto cambió porque la caja cambió.
     *
     * Antes era un párrafo —«Ahora mismo el correo sale DE la cuenta guardada
     * aquí»—; ahora es una línea de datos con su rótulo delante: «En efecto: la
     * cuenta guardada aquí». Lo que la prueba defiende no es la frase, es que
     * **la pantalla diga de dónde sale**, que es lo que hace que una precedencia
     * sirva de algo.
     */
    public function test_la_pestana_ensena_de_donde_sale_el_correo(): void
    {
        $this->guardarCuenta();

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('integraciones.index', ['p' => 'correo']))
            ->assertOk()
            ->assertSee('En efecto:')
            ->assertSee('la cuenta guardada aquí', false)
            ->assertSee('smtp.ejemplo.test');
    }

    /**
     * 9.17i: guardar ya no es una puerta de un solo sentido.
     *
     * `9.17g` activaba la cuenta al guardarla y no dejaba ninguna forma de
     * volver al `.env` sin tocar la base a mano. Apagar **no borra**: la cuenta
     * y su contraseña se quedan, para poder volver sin teclearla otra vez.
     */
    public function test_apagar_devuelve_el_correo_al_env_sin_borrar_la_cuenta(): void
    {
        $this->guardarCuenta();
        self::assertTrue(CuentaDeCorreo::enEfecto()['sale_de_aqui']);

        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('correo.conmutar'), ['encendida' => 0])
            ->assertRedirect();

        self::assertNull(CuentaDeCorreo::vigente(), 'apagada, ya no manda');
        self::assertNotNull(CuentaDeCorreo::guardada(), 'pero sigue escrita');
        self::assertSame('entorno', CuentaDeCorreo::enEfecto()['origen']);

        // Y se vuelve a encender sin volver a teclear nada.
        $this->actingAs($this->usuarioCon('admin'))
            ->post(route('correo.conmutar'), ['encendida' => 1])
            ->assertRedirect();

        self::assertNotNull(CuentaDeCorreo::vigente());
    }

    /**
     * 9.17i: guardar sobre una cuenta apagada la reescribe, no crea otra.
     *
     * Con `vigente()` —que sólo ve la activa— cada guardado después de un
     * apagado dejaba una conexión huérfana más en la tabla.
     */
    public function test_guardar_con_la_cuenta_apagada_no_crea_una_segunda(): void
    {
        $this->guardarCuenta();
        $antes = (int) DB::table('integration_connections')
            ->where('purpose_snapshot', 'email')->count();

        $admin = $this->usuarioCon('admin');

        $this->actingAs($admin)
            ->post(route('correo.conmutar'), ['encendida' => 0])->assertRedirect();

        // Por la PANTALLA, que es el camino que tenía el defecto.
        $this->actingAs($admin)->post(route('correo.guardar'), [
            'name' => 'Correo de LATAM',
            'host' => 'smtp.otro.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'buzon@latamsocial.test',
            'from_address' => 'hola@latamsocial.test',
            'from_name' => 'LATAM Social',
            'timeout_seconds' => 10,
        ])->assertRedirect();

        self::assertSame($antes, (int) DB::table('integration_connections')
            ->where('purpose_snapshot', 'email')->count());
        self::assertSame('smtp.otro.test', (string) CuentaDeCorreo::guardada()->host);
    }

    /**
     * 9.17i: el puerto y el cifrado que no casan avisan, y no impiden.
     *
     * Salió de un intento real: `smtp.gmail.com` con cifrado SSL y puerto 587.
     * Cada valor es legítimo por separado y ninguna regla los rechazaba, pero
     * juntos no conectan y el servidor sólo contesta con una espera agotada.
     * Se avisa en ámbar (`DEC-190`): la costumbre no es la ley.
     */
    public function test_ssl_con_el_puerto_de_tls_avisa_pero_deja_guardar(): void
    {
        $this->guardarCuenta(['encryption' => 'ssl', 'port' => 587]);

        self::assertNotNull(CuentaDeCorreo::vigente(), 'se guarda igual');

        $textos = array_map(static fn (object $a): string => $a->texto, CuentaDeCorreo::avisos());
        $cruce = array_filter($textos, static fn (string $t): bool => str_contains($t, 'pareja del'));

        self::assertCount(1, $cruce, 'avisa del cruce: '.implode(' | ', $textos));
        self::assertSame(
            ['ambar'],
            array_values(array_unique(array_map(
                static fn (object $a): string => $a->nivel,
                array_filter(
                    CuentaDeCorreo::avisos(),
                    static fn (object $a): bool => str_contains($a->texto, 'pareja del'),
                ),
            ))),
            'ambar y no rojo: no bloquea',
        );
    }

    // ----------------------------------------------------------- ayudantes

    /** @param array<string, mixed> $cambios */
    private function guardarCuenta(array $cambios = []): void
    {
        $conexionId = (int) Integraciones::porUuid(Integraciones::guardarConexion(null, [
            'integration_provider_id' => (int) DB::table('integration_providers')
                ->where('purpose', 'email')->value('id'),
            'legal_entity_id' => null,
            'name' => 'Correo de LATAM',
            'environment' => 'production',
            'username' => 'buzon@latamsocial.test',
            'base_url' => 'https://smtp.ejemplo.test',
            'status' => 'active',
        ], $this->autorId))->id;

        CuentaDeCorreo::guardar($conexionId, array_merge([
            'host' => 'smtp.ejemplo.test',
            'port' => 587,
            'encryption' => 'tls',
            'from_address' => 'hola@latamsocial.test',
            'from_name' => 'LATAM Social',
            'timeout_seconds' => 10,
        ], $cambios));

        Integraciones::guardarSecreto($conexionId, 'password', 'Zarzamora-2026!', $this->autorId);
    }

    private function avisos(string $nivel): string
    {
        return implode(' ', array_map(
            fn ($a): string => $a->texto,
            array_filter(CuentaDeCorreo::avisos(), fn ($a): bool => $a->nivel === $nivel),
        ));
    }
}
