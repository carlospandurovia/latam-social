<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Communication\Services\CuentaDeCorreo;
use App\Modules\Core\Services\Integraciones;
use App\Shared\Auth\Permisos;
use App\Shared\Config\Aviso;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El correo no sale de una instalación que no es la de verdad (9.22b, `DEC-029`).
 *
 * ### El agujero que esto tapa
 *
 * Desde `9.17g` la cuenta SMTP vive **en la base**, y eso fue un acierto: era el
 * último ajuste que obligaba a entrar a la máquina. Abrió también esto: una copia
 * del volcado de producción en un servidor de pruebas trae dentro la cuenta de
 * correo buena, y el sistema **manda correos de verdad a los creadores** sin que
 * nadie haya configurado nada.
 *
 * Y no es un correo suelto. `9.19b` escribe a **cada creador activo** al publicar
 * una versión de los términos. El destinatario es un tercero, y un correo mandado
 * no se retira.
 *
 * ### Desviar, no negarse
 *
 * `9.22a` se **niega** cuando el envío a la administración no toca. Aquí se
 * **desvía**, y son remedios distintos porque los fallos lo son: no emitir un
 * comprobante deja el trabajo a medias; no mandar un correo de prueba no rompe
 * nada, y con el capturador el mensaje sigue escrito. Negarse aquí convertiría
 * cada pantalla que manda un correo en un error.
 */
final class CorreoDesviadoTest extends TestCase
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

        $this->autorId = (int) $this->usuarioCon('admin')->id;
    }

    // ------------------------------------------------------------- el desvío

    /**
     * **La que da nombre a la iteración.** La cuenta de producción de un volcado
     * restaurado no manda desde una máquina de pruebas.
     */
    public function test_la_cuenta_de_produccion_no_manda_desde_pruebas(): void
    {
        config(['instalacion.entorno' => 'staging']);
        $this->guardarCuenta('production');

        $motivo = CuentaDeCorreo::desviado();

        self::assertNotNull($motivo, 'una cuenta de produccion no manda desde preproduccion');
        self::assertStringContainsString('Preproducción', $motivo, 'y el motivo dice desde que maquina');

        CuentaDeCorreo::aplicar();

        self::assertSame('log', config('mail.default'),
            'el correo se escribe en el registro y no sale');
        self::assertNotSame('smtp.ejemplo.test', config('mail.mailers.smtp.host'),
            'y la cuenta de produccion NO llega a la configuracion viva');
    }

    /**
     * **Y la misma cuenta sí manda desde producción.**
     *
     * Sin ésta, la anterior podría estar pasando por cualquier otra cosa —que la
     * cuenta no esté activa, que falte la contraseña—. Lo único que cambia entre
     * las dos es `instalacion.entorno`.
     */
    public function test_la_misma_cuenta_si_manda_desde_produccion(): void
    {
        config(['instalacion.entorno' => 'production']);
        $this->guardarCuenta('production');

        self::assertNull(CuentaDeCorreo::desviado());

        CuentaDeCorreo::aplicar();

        self::assertSame('smtp', config('mail.default'));
        self::assertSame('smtp.ejemplo.test', config('mail.mailers.smtp.host'));
    }

    /**
     * Una conexión de **PRUEBAS** guardada en el panel sí manda desde una
     * máquina de pruebas: es el camino legítimo para ensayar, y cerrarlo dejaría
     * sin poder probar el correo precisamente donde hay que probarlo.
     */
    public function test_una_cuenta_de_pruebas_si_manda_desde_pruebas(): void
    {
        config(['instalacion.entorno' => 'staging']);
        $this->guardarCuenta('sandbox');

        self::assertNull(CuentaDeCorreo::desviado());

        CuentaDeCorreo::aplicar();

        self::assertSame('smtp', config('mail.default'));
        self::assertSame('smtp.ejemplo.test', config('mail.mailers.smtp.host'));
    }

    /**
     * El otro camino que muerde: **sin cuenta en la base y con el `.env` de
     * producción copiado** para levantar el servidor de pruebas deprisa.
     */
    public function test_el_env_de_produccion_copiado_tampoco_manda(): void
    {
        config(['instalacion.entorno' => 'staging', 'mail.default' => 'smtp']);

        $motivo = CuentaDeCorreo::desviado();

        self::assertNotNull($motivo, 'el .env tambien se desvia');
        self::assertStringContainsString('.env', $motivo);

        CuentaDeCorreo::aplicar();

        self::assertSame('log', config('mail.default'));
    }

    /**
     * **Y el desvío no se desactiva a sí mismo en la segunda vuelta.**
     *
     * `aplicar()` reescribe `mail.default` a `log`. Si el motivo se recalculara
     * leyendo ese valor, la segunda llamada concluiría que ya no hace falta
     * desviar —y aplicaría la cuenta que acababa de rechazar—. Un desvío así
     * funciona en la prueba y falla en el arranque real, que es donde se llama
     * más de una vez.
     *
     * El primer intento se acordaba en una **propiedad estática**, y eso puso
     * roja una prueba de `9.17b` que no tiene nada que ver: una estática
     * sobrevive a la petición, así que en la suite lo que decidía una prueba lo
     * heredaba la siguiente. Ahora se acuerda en la configuración, que se rehace
     * en cada petición — la vida que esta decisión tiene.
     */
    public function test_aplicar_dos_veces_sigue_desviando(): void
    {
        config(['instalacion.entorno' => 'staging', 'mail.default' => 'smtp']);

        CuentaDeCorreo::aplicar();
        CuentaDeCorreo::aplicar();

        self::assertNotNull(CuentaDeCorreo::desviado(), 'sigue desviado en la segunda vuelta');
        self::assertSame('log', config('mail.default'));
    }

    /** Con la anulación abierta el correo vuelve a salir: es el mismo interruptor. */
    public function test_la_anulacion_devuelve_el_correo(): void
    {
        config([
            'instalacion.entorno' => 'staging',
            'instalacion.permitir_conexiones_de_produccion' => true,
        ]);
        $this->guardarCuenta('production');

        self::assertNull(CuentaDeCorreo::desviado());

        CuentaDeCorreo::aplicar();

        self::assertSame('smtp', config('mail.default'));
    }

    // -------------------------------------------------- lo que NO se puede hacer

    /**
     * **Probar la cuenta con el correo desviado habría dicho «funciona».**
     *
     * El capturador nunca falla, así que `probar()` habría terminado bien y
     * habría estampado `last_success_at` —la fecha en la que el sistema afirma
     * que esa cuenta funcionaba— sin haber mandado nada. Una prueba que no puede
     * fallar no es una prueba; una que además deja escrito que salió bien es
     * peor que no tenerla.
     */
    public function test_no_se_puede_probar_una_cuenta_desviada(): void
    {
        config(['instalacion.entorno' => 'staging']);
        $this->guardarCuenta('production');

        try {
            CuentaDeCorreo::probar('alguien@ejemplo.test');
            self::fail('probar() dijo que funcionaba sin haber mandado nada');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('No se puede probar la cuenta desde aquí', $e->getMessage());
        }

        self::assertNull(CuentaDeCorreo::vigente()?->last_success_at,
            'y NO queda estampado que la cuenta funcionaba');
    }

    // -------------------------------------------------------------- lo que se ve

    /**
     * Desviado es un estado **propio**: ámbar, no rojo.
     *
     * En un servidor de pruebas el desvío es el estado correcto. Pintarlo del
     * mismo rojo que «el correo está mal configurado» haría que se intentara
     * arreglar lo que está bien — y, peor, que un rojo permanente acabase
     * haciendo que tampoco se lea el de producción, que sí importa.
     */
    public function test_desviado_avisa_en_ambar_y_no_en_rojo(): void
    {
        config(['instalacion.entorno' => 'staging']);
        $this->guardarCuenta('production');

        $avisos = CuentaDeCorreo::avisos();
        $niveles = array_map(static fn (Aviso $a): string => $a->nivel, $avisos);

        self::assertContains(Aviso::AMBAR, $niveles);
        self::assertNotContains(Aviso::ROJO, $niveles,
            'desviado no es «mal configurado»: en pruebas es el estado correcto');
    }

    /** Y `enEfecto()` no puede seguir diciendo que el correo sale de aquí. */
    public function test_en_efecto_deja_de_afirmar_que_sale(): void
    {
        config(['instalacion.entorno' => 'staging']);
        $this->guardarCuenta('production');

        $efecto = CuentaDeCorreo::enEfecto();

        self::assertFalse($efecto['sale_de_aqui'],
            'afirmar «sale de aqui» con el mensaje en un archivo hace perder la tarde '
            .'buscandolo en la bandeja del destinatario');
        self::assertSame('log', $efecto['transporte']);
        self::assertNotNull($efecto['desviado']);
    }

    /** La pantalla de integraciones lo dice, y con su chapa propia. */
    public function test_la_pantalla_lo_dice(): void
    {
        config(['instalacion.entorno' => 'staging']);
        $this->guardarCuenta('production');

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('integraciones.index', ['p' => 'correo']))
            ->assertOk()
            ->assertSee('Desviado al registro', false)
            ->assertSee('es de PRODUCCIÓN y esta instalación es «Preproducción»', false);
    }

    // --------------------------------------------------------------- utilería

    private function guardarCuenta(string $entorno): void
    {
        $conexionId = (int) Integraciones::porUuid(Integraciones::guardarConexion(null, [
            'integration_provider_id' => (int) DB::table('integration_providers')
                ->where('purpose', 'email')->value('id'),
            'legal_entity_id' => null,
            'name' => 'Correo de LATAM',
            'environment' => $entorno,
            'username' => 'buzon@latamsocial.test',
            'base_url' => 'https://smtp.ejemplo.test',
            'status' => 'active',
        ], $this->autorId))->id;

        CuentaDeCorreo::guardar($conexionId, [
            'host' => 'smtp.ejemplo.test',
            'port' => 587,
            'encryption' => 'tls',
            'from_address' => 'hola@latamsocial.test',
            'from_name' => 'LATAM Social',
            'timeout_seconds' => 10,
        ]);

        Integraciones::guardarSecreto($conexionId, 'password', 'Zarzamora-2026!', $this->autorId);
    }
}
