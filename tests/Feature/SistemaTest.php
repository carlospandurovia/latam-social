<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Sistema;
use App\Shared\Auth\Permisos;
use App\Shared\Database\Vigencia;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * `D-1`: lo técnico sale del panel y se va a Configuración.
 *
 * ### Qué fija
 *
 * 1. **El panel ya no lo enseña.** Es el objetivo entero de la iteración, y sin
 *    esta prueba nada impide que alguien lo devuelva «porque venía bien».
 * 2. **La pantalla nueva sí, y detrás de un permiso.**
 * 3. **El mecanismo se LEE de `schema_constraints`, no se supone.** El panel
 *    viejo decía «se usan TRIGGER» también en un motor que aplica `CHECK`,
 *    porque la sonda se rinde fuera de consola. Esta prueba lo fija con una
 *    fila fabricada: si alguien vuelve a preguntárselo a la sonda, se pone roja.
 * 4. **No hay ámbares permanentes.** Que el motor no aplique `CHECK` no genera
 *    aviso (`DEC-282`); que la base no sea utf8mb4, sí.
 */
final class SistemaTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
        Queue::fake();
    }

    // ------------------------------------------------------- el panel adelgaza

    public function test_el_panel_ya_no_ensena_el_motor_ni_el_esquema(): void
    {
        $respuesta = $this->actingAs($this->usuarioCon('admin'))->get(route('panel'));

        $respuesta->assertOk();
        $respuesta->assertDontSee('Motor de base de datos');
        $respuesta->assertDontSee('Soporta CTE');
        $respuesta->assertDontSee('Sociedades que facturan');
        $respuesta->assertDontSee('en el esquema');
    }

    /**
     * Lo que sí se queda: los tres contadores del negocio, y llevan a algún sitio.
     *
     * Un número que no lleva al detalle es un número que hay que creerse.
     */
    public function test_el_panel_conserva_los_contadores_del_negocio(): void
    {
        $respuesta = $this->actingAs($this->usuarioCon('admin'))->get(route('panel'));

        $respuesta->assertOk();
        $respuesta->assertSee('Creadores');
        $respuesta->assertSee('Clientes');
        $respuesta->assertSee('Campañas');
        $respuesta->assertSee(route('creadores.index'));
    }

    // --------------------------------------------------------- la pantalla nueva

    public function test_la_informacion_del_sistema_vive_en_su_pantalla(): void
    {
        $respuesta = $this->actingAs($this->usuarioCon('admin'))->get(route('sistema.index'));

        $respuesta->assertOk();
        $respuesta->assertSee('Motor de base de datos');
        $respuesta->assertSee('Sociedades que facturan');
        $respuesta->assertSee('Cómo están impuestas las reglas');
    }

    public function test_sin_permiso_de_configuracion_no_se_abre(): void
    {
        $this->actingAs($this->usuarioCon(null))
            ->get(route('sistema.index'))
            ->assertForbidden();
    }

    // ------------------------------------------ el mecanismo se lee, no se supone

    /**
     * La prueba de `DEC-307`.
     *
     * `schema_constraints.mechanism` guarda con qué mecanismo se impuso cada
     * regla **en el momento de instalarla**. Esta prueba fabrica una de cada y
     * comprueba que la pantalla cuenta las dos, sin preguntarle nada al motor.
     *
     * Con la implementación vieja —`Restriccion::motorAplicaCheck()`, que fuera
     * de consola devuelve `false` sin mirar— este recuento no existiría: la
     * pantalla diría «se usan TRIGGER» y punto, hubiera o no un solo trigger.
     */
    public function test_cuenta_las_reglas_por_el_mecanismo_registrado(): void
    {
        // La tabla NO está vacía: las migraciones ya registraron sus ~370 reglas
        // con el mecanismo que este motor usó de verdad. Se mide la DIFERENCIA,
        // no el total. Afirmar «hay una de cada» sería afirmar algo falso, y
        // pasaría sólo en una base que nadie va a tener.
        $antes = Sistema::reglas()['por_mecanismo'];

        DB::table('schema_constraints')->insert([
            [
                'table_name' => 'zz_prueba', 'constraint_name' => 'ck_zz_uno',
                'expression' => 'n > 0', 'columns_involved' => 'n',
                'message' => 'prueba', 'mechanism' => 'check', 'created_at' => now(),
            ],
            [
                'table_name' => 'zz_prueba', 'constraint_name' => 'ck_zz_dos',
                'expression' => 'n < 9', 'columns_involved' => 'n',
                'message' => 'prueba', 'mechanism' => 'trigger', 'created_at' => now(),
            ],
        ]);

        $reglas = Sistema::reglas();

        $this->assertSame(
            ($antes['check'] ?? 0) + 1,
            $reglas['por_mecanismo']['check'] ?? 0,
            'la fila con mecanismo «check» tiene que sumar exactamente una',
        );
        $this->assertSame(
            ($antes['trigger'] ?? 0) + 1,
            $reglas['por_mecanismo']['trigger'] ?? 0,
            'la fila con mecanismo «trigger» tiene que sumar exactamente una',
        );
        $this->assertGreaterThanOrEqual(2, $reglas['total']);
        $this->assertGreaterThan(0, $reglas['tablas']);
    }

    // ---------------------------------------------------- avisos: sólo lo que falla

    /**
     * Que el motor no aplique `CHECK` NO es un aviso.
     *
     * Es una limitación asumida, compensada y verificada. Ponerla en ámbar para
     * siempre escondería los ámbares que sí hay que mirar: `DEC-282`.
     *
     * ### Por qué se provoca un aviso antes de mirar
     *
     * La primera versión recorría `Sistema::avisos()` con un `foreach` y
     * afirmaba dentro. En una instalación sana esa lista está **vacía**, así que
     * el bucle no se ejecutaba y la prueba **no comprobaba nada**: PHPUnit la
     * marcó como `risky` con las palabras exactas —*«This test did not perform
     * any assertions»*—.
     *
     * Es la novena vez que aparece en este proyecto la misma familia
     * (`DEC-300`): una prueba en verde por el motivo equivocado. Aquí se rompe
     * la cobertura de países a propósito para que **haya** un aviso, y entonces
     * se afirma que ninguno habla del motor. Con la lista vacía la afirmación
     * era cierta y vacía; con la lista llena, dice algo.
     */
    public function test_una_limitacion_asumida_del_motor_no_genera_aviso(): void
    {
        DB::table('legal_entity_countries')->update([
            // Cerrar la cobertura AYER es aritmetica de vigencias, tambien en una
            // fixtura: `valid_to` es inclusivo y el error de un dia aqui haria que
            // la prueba midiera otra cosa que la que dice medir.
            'valid_to' => Vigencia::cerrarElDiaAntesDe(now()->toDateString()),
        ]);

        $avisos = Sistema::avisos();

        self::assertNotEmpty($avisos, 'la premisa: tiene que haber avisos que mirar');

        $todos = implode(' | ', array_map(static fn ($a): string => $a->texto, $avisos));

        $this->assertStringNotContainsStringIgnoringCase('CHECK', $todos);
        $this->assertStringNotContainsStringIgnoringCase('CTE', $todos);
        $this->assertStringNotContainsStringIgnoringCase('ventana', $todos);
    }

    public function test_sin_cobertura_de_paises_avisa_porque_no_se_podria_facturar(): void
    {
        DB::table('legal_entity_countries')->update([
            // Cerrar la cobertura AYER es aritmetica de vigencias, tambien en una
            // fixtura: `valid_to` es inclusivo y el error de un dia aqui haria que
            // la prueba midiera otra cosa que la que dice medir.
            'valid_to' => Vigencia::cerrarElDiaAntesDe(now()->toDateString()),
        ]);

        $textos = implode(' ', array_map(static fn ($a): string => $a->texto, Sistema::avisos()));

        $this->assertStringContainsString('sociedad que lo facture', $textos);
    }

    // ------------------------------------------------ la etiqueta del encabezado

    /**
     * Fuera de producción la etiqueta está, y la franja del ancho de la pantalla
     * ya no.
     */
    public function test_fuera_de_produccion_hay_etiqueta_y_no_franja(): void
    {
        $respuesta = $this->actingAs($this->usuarioCon('admin'))->get(route('panel'));

        $respuesta->assertOk();
        $respuesta->assertSee('Entorno');
        $respuesta->assertDontSee('Ésta no es la instalación de producción');
    }
}
