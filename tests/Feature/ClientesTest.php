<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Clientes (iteración 4.1, hoja de ruta `7.0`).
 *
 * La prueba que carga con el peso es
 * `test_no_se_activa_un_cliente_al_que_nadie_puede_facturar`: es `BR-LE-004`,
 * que dice que sin sociedad que cubra su país la operación **se bloquea con un
 * mensaje accionable** y nunca se asigna una por defecto ni se sigue en
 * silencio.
 */
final class ClientesTest extends TestCase
{
    use RefreshDatabase;

    private int $paisPE;

    private int $paisSinCobertura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');

        // Perú YA está cubierto por la semilla —`CimientosSeeder` declara que
        // CTS Perú factura PE, EC, CL, MX y US, y CTS Colombia CO—, así que
        // aquí no se declara nada: hacerlo chocaría con
        // `tg_lec_sin_solape_ins`, que desde 3.10 impide dos coberturas del
        // mismo país en fechas que se pisen.
        //
        // El país sin cobertura: se ACTIVA Argentina.
        //
        // La primera versión lo buscaba con un `whereNotIn` sobre los países ya
        // cubiertos, y habría dejado la prueba en *skipped* —que parece verde—:
        // los seis países activos de la semilla están todos cubiertos, así que
        // no encontraba ninguno.
        //
        // Activar uno inactivo no es un truco para salir del paso: **es el caso
        // real**. `BR-LE-004` existe exactamente para el día que el negocio se
        // abre a un país nuevo, alguien lo activa en el catálogo y todavía no
        // hay sociedad que pueda facturar allí.
        DB::table('countries')->where('iso2', 'AR')->update(['is_active' => 1]);
        $this->paisSinCobertura = (int) DB::table('countries')->where('iso2', 'AR')->value('id');

        $this->assertSame(0, DB::table('legal_entity_countries')
            ->where('country_id', $this->paisSinCobertura)->count(),
            'El caso de esta prueba es un pais activo SIN cobertura.');
    }

    // ------------------------------------------------------------ autorización

    public function test_ver_clientes_no_es_lo_mismo_que_crearlos(): void
    {
        $this->actingAs($this->usuarioCon('finance'))
            ->get('/clientes')
            ->assertOk();

        // `finance` tiene `client.view` y no `client.manage`.
        $this->actingAs($this->usuarioCon('finance'))
            ->get('/clientes/nuevo')
            ->assertForbidden();
    }

    public function test_quien_monta_campanas_si_los_crea(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get('/clientes/nuevo')
            ->assertOk();
    }

    // ------------------------------------------------------------------- alta

    public function test_alta_de_un_cliente_con_cobertura(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post('/clientes', $this->formulario(['status' => 'active']))
            ->assertSessionHas('exito');

        $cliente = DB::table('client_organizations')->where('client_code', 'ACME-01')->first();

        $this->assertNotNull($cliente);
        $this->assertSame('active', $cliente->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.created']);
    }

    /**
     * `BR-LE-004`: sin quien le facture, no se activa. Y se dice con palabras.
     */
    public function test_no_se_activa_un_cliente_al_que_nadie_puede_facturar(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post('/clientes', $this->formulario([
                'country_id' => $this->paisSinCobertura,
                'status' => 'active',
            ]))
            ->assertSessionHas('aviso');

        $this->assertDatabaseMissing('client_organizations', ['client_code' => 'ACME-01']);
    }

    /**
     * `DEC-073`: el mismo cliente, como PROSPECTO, sí entra.
     *
     * Es la mitad que evita que la regla se convierta en un estorbo: un cliente
     * potencial en un país que todavía no cubrimos es una oportunidad comercial
     * legítima, y prohibir apuntarla obliga a llevarla en una hoja aparte — que
     * es justo lo que este sistema viene a eliminar.
     */
    public function test_el_mismo_cliente_como_prospecto_si_se_apunta(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post('/clientes', $this->formulario([
                'country_id' => $this->paisSinCobertura,
                'status' => 'prospect',
            ]))
            ->assertSessionHas('exito');

        $this->assertDatabaseHas('client_organizations', [
            'client_code' => 'ACME-01', 'status' => 'prospect',
        ]);
    }

    public function test_activar_despues_tampoco_se_puede_sin_cobertura(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');

        $this->actingAs($gestor)->post('/clientes', $this->formulario([
            'country_id' => $this->paisSinCobertura, 'status' => 'prospect',
        ]));

        $uuid = (string) DB::table('client_organizations')->where('client_code', 'ACME-01')->value('uuid');

        $this->actingAs($gestor)
            ->put("/clientes/{$uuid}", $this->formulario([
                'country_id' => $this->paisSinCobertura, 'status' => 'active',
            ]))
            ->assertSessionHas('aviso');

        $this->assertSame('prospect', DB::table('client_organizations')->where('uuid', $uuid)->value('status'));
    }

    public function test_declarar_la_cobertura_desbloquea_la_activacion(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');

        $this->actingAs($gestor)->post('/clientes', $this->formulario([
            'country_id' => $this->paisSinCobertura, 'status' => 'prospect',
        ]));

        $uuid = (string) DB::table('client_organizations')->where('client_code', 'ACME-01')->value('uuid');

        // Lo que la pantalla dice que hay que hacer, hecho.
        $this->cubrir($this->paisSinCobertura);

        $this->actingAs($gestor)
            ->put("/clientes/{$uuid}", $this->formulario([
                'country_id' => $this->paisSinCobertura, 'status' => 'active',
            ]))
            ->assertSessionHas('exito');

        $this->assertSame('active', DB::table('client_organizations')->where('uuid', $uuid)->value('status'));
    }

    public function test_el_codigo_no_se_repite(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');

        $this->actingAs($gestor)->post('/clientes', $this->formulario());
        $this->actingAs($gestor)
            ->post('/clientes', $this->formulario(['commercial_name' => 'Otro']))
            ->assertSessionHasErrors('client_code');

        $this->assertSame(1, DB::table('client_organizations')->where('client_code', 'ACME-01')->count());
    }

    public function test_editar_deja_rastro_en_la_bitacora(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $this->actingAs($gestor)->post('/clientes', $this->formulario());

        $uuid = (string) DB::table('client_organizations')->where('client_code', 'ACME-01')->value('uuid');

        $this->actingAs($gestor)
            ->put("/clientes/{$uuid}", $this->formulario(['commercial_name' => 'ACME renombrada']))
            ->assertSessionHas('exito');

        $this->assertDatabaseHas('audit_logs', ['action' => 'client.updated']);
        $this->assertSame('ACME renombrada',
            DB::table('client_organizations')->where('uuid', $uuid)->value('commercial_name'));
    }

    // ------------------------------------------------------------------ apoyo

    /** Declara que la sociedad de la semilla factura a un país. */
    private function cubrir(int $paisId): void
    {
        DB::table('legal_entity_countries')->insert([
            'legal_entity_id' => (int) DB::table('legal_entities')->orderBy('id')->value('id'),
            'country_id' => $paisId,
            'coverage_basis' => 'service_export',
            'valid_from' => now()->subYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private function formulario(array $cambios = []): array
    {
        return array_merge([
            'commercial_name' => 'ACME',
            'client_code' => 'ACME-01',
            'country_id' => $this->paisPE,
            'status' => 'prospect',
        ], $cambios);
    }

    private function usuarioCon(string $rol): User
    {
        $usuario = User::factory()->create();
        DB::table('role_user')->insert([
            'user_id' => $usuario->id,
            'role_id' => DB::table('roles')->where('code', $rol)->value('id'),
            'assigned_at' => now(),
        ]);
        Permisos::olvidar((int) $usuario->id);

        return $usuario;
    }
}
