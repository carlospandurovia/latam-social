<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Audit\Bitacora;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * La pantalla de bitácora y la red de redacción (iteración 3.3).
 */
final class BitacoraTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
    }

    // ------------------------------------------------- lo que se puede pintar

    /**
     * Una entrada con **listas** dentro no tumba la pantalla.
     *
     * Encontrado usando la aplicación. `MarcasController` guarda
     * `categorias => ['antes' => [1,2], 'despues' => [3]]` —correcto: una marca
     * tiene varias categorías y el cambio interesante es la lista entera— y la
     * vista hacía `{{ $v['antes'] }}` a pelo. Con un array eso es
     * `htmlspecialchars(): must be of type string, array given`: **un 500 que se
     * lleva por delante la página entera de la bitácora**.
     *
     * Bastaba UNA fila así para no poder ver ninguna. Y la bitácora es
     * precisamente lo que se mira cuando algo ha ido mal.
     */
    public function test_una_entrada_con_listas_dentro_se_pinta(): void
    {
        $this->entrada(['categorias' => ['antes' => [1, 2], 'despues' => [3]]]);

        $this->actingAs($this->usuarioCon('admin'))
            ->get('/bitacora')
            ->assertOk()
            ->assertSee('1, 2')
            ->assertSee('categorias');
    }

    /** Y una que ni siquiera tiene la forma `antes`/`despues`. */
    public function test_una_entrada_con_forma_rara_tampoco_la_tumba(): void
    {
        $this->entrada(['origen' => 'importacion masiva']);

        $this->actingAs($this->usuarioCon('admin'))
            ->get('/bitacora')
            ->assertOk()
            ->assertSee('importacion masiva');
    }

    public function test_una_lista_vacia_no_se_pinta_como_corchetes(): void
    {
        $this->entrada(['categorias' => ['antes' => [], 'despues' => [7]]]);

        $this->actingAs($this->usuarioCon('admin'))
            ->get('/bitacora')
            ->assertOk()
            ->assertDontSee('[]');
    }

    /**
     * Escribe una entrada de bitácora con el `changes` que se le dé.
     *
     * Directo a la tabla y no por `Bitacora::registrar()`: lo que se prueba es
     * que la PANTALLA aguanta lo que hay guardado, incluidas las filas viejas.
     *
     * @param array<string, mixed> $cambios
     */
    private function entrada(array $cambios): void
    {
        DB::table('audit_logs')->insert([
            'action' => 'client_brand.updated',
            'entity_type' => 'client_brand',
            'entity_id' => 1,
            'changes' => json_encode($cambios, JSON_THROW_ON_ERROR),
            // `audit_logs` no tiene `created_at`: `occurred_at` ES su fecha, y
            // la tabla no lleva marcas de tiempo de Eloquent a proposito --es
            // evidencia de solo insercion, no una entidad que se edite--.
            'occurred_at' => now(),
        ]);
    }

    // ------------------------------------------------------------ autorización

    public function test_la_bitacora_es_solo_para_quien_puede_auditar(): void
    {
        $this->actingAs($this->usuarioCon('finance'))->get('/bitacora')->assertForbidden();
        $this->actingAs($this->usuarioCon('campaign_manager'))->get('/bitacora')->assertForbidden();
        $this->actingAs($this->usuarioCon('admin'))->get('/bitacora')->assertOk();
    }

    // -------------------------------------------------------------- el listado

    public function test_muestra_las_entradas_y_lo_que_cambio(): void
    {
        $this->actingAs($this->usuarioCon('admin'));
        Bitacora::registrar('creator.updated', 'creator', 7, [
            'city' => ['antes' => 'Lima', 'despues' => 'Cusco'],
        ]);

        $this->get('/bitacora')
            ->assertOk()
            ->assertSee('creator.updated')
            ->assertSee('Cusco')
            ->assertSee('Lima');
    }

    public function test_filtra_por_tipo_de_entidad(): void
    {
        $this->actingAs($this->usuarioCon('admin'));
        Bitacora::registrar('creator.updated', 'creator', 1, ['a' => ['antes' => 1, 'despues' => 2]]);
        Bitacora::registrar('client.updated', 'client', 1, ['b' => ['antes' => 3, 'despues' => 4]]);

        $this->get('/bitacora?tipo=creator')->assertOk()->assertSee('creator.updated')->assertDontSee('client.updated');
    }

    /** Prefijo y no contención: `like '%x%'` no usa índice. */
    public function test_filtra_la_accion_por_prefijo(): void
    {
        $this->actingAs($this->usuarioCon('admin'));
        Bitacora::registrar('creator.updated', 'creator', 1);
        Bitacora::registrar('client.updated', 'client', 1);

        $this->get('/bitacora?accion=creator.')->assertOk()
            ->assertSee('creator.updated')->assertDontSee('client.updated');
    }

    /**
     * La bitácora dice quién era esa persona ENTONCES. Si hoy se llama de otro
     * modo, la entrada de ayer no cambia.
     */
    public function test_el_actor_queda_congelado_aunque_cambie_de_nombre(): void
    {
        $usuario = $this->usuarioCon('admin');
        $this->actingAs($usuario);
        Bitacora::registrar('creator.updated', 'creator', 1);

        $etiqueta = (string) DB::table('audit_logs')->value('actor_label');
        $this->assertStringContainsString($usuario->email, $etiqueta);

        DB::table('users')->where('id', $usuario->id)->update(['name' => 'Nombre Nuevo']);

        $this->assertSame($etiqueta, (string) DB::table('audit_logs')->value('actor_label'));
    }

    // ------------------------------------------------------------- la redacción

    /**
     * Red de seguridad: aunque alguien audite la columna equivocada, el valor no
     * llega a la tabla. Un `account_number_encrypted` en claro dentro de la
     * bitácora anularía el cifrado de la tabla de origen.
     */
    #[DataProvider('camposSensibles')]
    public function test_los_campos_sensibles_no_se_escriben(string $campo): void
    {
        $this->actingAs($this->usuarioCon('admin'));
        Bitacora::registrar('prueba', 'creator', 1, [
            $campo => ['antes' => 'valor-secreto-viejo', 'despues' => 'valor-secreto-nuevo'],
        ]);

        $registrado = (string) DB::table('audit_logs')->where('action', 'prueba')->value('changes');

        $this->assertStringNotContainsString('valor-secreto', $registrado);
        $this->assertStringContainsString('[redactado]', $registrado);
        // El nombre del campo SÍ queda: saber que alguien lo tocó es auditoría.
        $this->assertStringContainsString($campo, $registrado);
    }

    /** @return list<array{string}> */
    public static function camposSensibles(): array
    {
        return [
            ['password'],
            ['remember_token'],
            ['account_number_encrypted'],
            ['account_number_fingerprint'],
            ['api_key'],
            ['card_cvv'],
        ];
    }

    public function test_un_campo_normal_si_se_registra_entero(): void
    {
        $this->actingAs($this->usuarioCon('admin'));
        Bitacora::registrar('prueba', 'creator', 1, [
            'city' => ['antes' => 'Lima', 'despues' => 'Cusco'],
        ]);

        $registrado = (string) DB::table('audit_logs')->where('action', 'prueba')->value('changes');
        $this->assertStringContainsString('Cusco', $registrado);
        $this->assertStringNotContainsString('[redactado]', $registrado);
    }

    /** El CHECK `ck_audit_logs_changes` exige JSON válido; no basta con que parezca. */
    public function test_lo_registrado_es_json_valido(): void
    {
        $this->actingAs($this->usuarioCon('admin'));
        Bitacora::registrar('prueba', 'creator', 1, [
            'ciudad' => ['antes' => null, 'despues' => 'Ñuñoa "con comillas"'],
        ]);

        $registrado = (string) DB::table('audit_logs')->where('action', 'prueba')->value('changes');
        $this->assertIsArray(json_decode($registrado, true));
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }
}
