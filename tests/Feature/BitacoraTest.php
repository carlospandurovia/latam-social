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
