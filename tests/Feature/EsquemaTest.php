<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Config\Aviso;
use App\Shared\Config\Esquema;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * ¿Sabe el sistema que le falta migrar? (iteración 9.17j, `T-84`).
 *
 * ### Por qué existe esta prueba
 *
 * Por una mañana entera. Se desplegó el código de `9.17g` sin correr
 * `php artisan migrate`, y guardar una cuenta de correo devolvió un `SQLSTATE`
 * en crudo con el mensaje de la regla **anterior** — que se llama igual y dice
 * lo mismo que la nueva. Para saber cuál estaba instalada hubo que comparar las
 * condiciones de las dos versiones del disparador.
 *
 * El defecto no era el mensaje: era que **el sistema no sabía que le faltaba
 * migrar**, teniendo delante las dos únicas listas que hacen falta para saberlo.
 */
final class EsquemaTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
    }

    /** Con la base al día no hay nada que decir, y no se dice nada. */
    public function test_al_dia_no_avisa_de_nada(): void
    {
        self::assertSame([], Esquema::pendientes());
        self::assertSame([], Esquema::desconocidas());
        self::assertNull(Esquema::aviso());

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('panel'))
            ->assertOk()
            ->assertDontSee('a medio desplegar');
    }

    /**
     * Y con una sin aplicar lo dice **en la pantalla**, no en un log.
     *
     * Se quita una fila de `migrations` en vez de inventar un archivo: es
     * exactamente el estado en el que quedó la instalación real —el archivo
     * está, la base no lo tiene— y no depende de que el fichero falso sobreviva
     * a la limpieza de la prueba.
     */
    public function test_una_migracion_sin_aplicar_sale_en_rojo_en_el_panel(): void
    {
        $quitada = (string) DB::table('migrations')->orderByDesc('id')->value('migration');
        DB::table('migrations')->where('migration', $quitada)->delete();

        self::assertSame([$quitada], Esquema::pendientes());

        $aviso = Esquema::aviso();
        self::assertNotNull($aviso);
        self::assertSame(Aviso::ROJO, $aviso->nivel);
        self::assertStringContainsString($quitada, $aviso->texto);
        // La orden EXACTA, porque un aviso que no dice como se arregla obliga a
        // buscarla, y quien lo lee suele estar con prisa.
        self::assertStringContainsString('php artisan migrate', $aviso->texto);
        // El plural pierde la tilde. Lo pillo mirar la pantalla, no una prueba;
        // se afirma para que no vuelva.
        self::assertStringNotContainsString('migraciónes', $aviso->texto);
        self::assertStringContainsString('1 migración sin aplicar', $aviso->texto);

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('panel'))
            ->assertOk()
            ->assertSee('El sistema está a medio desplegar', false)
            ->assertSee($quitada);
    }

    /**
     * Sale en TODAS las pantallas del panel, no solo en la portada.
     *
     * Es el punto: con el esquema atrasado puede fallar cualquiera. Si el aviso
     * viviera en un área de configuración, quien está en Integraciones —que fue
     * donde reventó— no lo vería.
     */
    public function test_sale_tambien_en_la_pantalla_donde_reviento(): void
    {
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('integraciones.index', ['p' => 'correo']))
            ->assertOk()
            ->assertSee('El sistema está a medio desplegar', false);
    }

    /**
     * La otra dirección: la base sabe cosas que este código no.
     *
     * Pasa al volver a una rama anterior. No rompe nada —por eso es ámbar— pero
     * dice que lo que se está mirando no es lo que hay desplegado.
     */
    public function test_una_aplicada_que_ya_no_esta_en_el_codigo_avisa_en_ambar(): void
    {
        DB::table('migrations')->insert([
            'migration' => '2099_01_01_000000_de_una_rama_que_ya_no_existe',
            'batch' => 99,
        ]);

        self::assertSame(
            ['2099_01_01_000000_de_una_rama_que_ya_no_existe'],
            Esquema::desconocidas(),
        );

        $aviso = Esquema::aviso();
        self::assertNotNull($aviso);
        self::assertSame(Aviso::AMBAR, $aviso->nivel);

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('panel'))
            ->assertOk()
            ->assertSee('Lo desplegado no es lo que hay en la base', false);
    }

    /**
     * Y lo que falta MANDA sobre lo que sobra.
     *
     * Con las dos cosas a la vez, la que rompe es la primera: el código llamando
     * a tablas que no existen. La otra puede esperar.
     */
    public function test_lo_que_falta_manda_sobre_lo_que_sobra(): void
    {
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();
        DB::table('migrations')->insert([
            'migration' => '2099_01_01_000000_de_una_rama_que_ya_no_existe',
            'batch' => 99,
        ]);

        $aviso = Esquema::aviso();
        self::assertNotNull($aviso);
        self::assertSame(Aviso::ROJO, $aviso->nivel);
        self::assertStringContainsString('por detrás del código', $aviso->texto);
    }
}
