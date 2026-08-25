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
 * Marcas del cliente (iteración 4.2).
 *
 * Dos propiedades cargan con la iteración:
 *
 * - `test_dar_de_alta_un_cliente_crea_su_primera_marca` — `DEC-074`: el modelo
 *   distingue cliente de marca por buenas razones, pero el caso simple no debe
 *   costar dos formularios.
 * - `test_el_slug_se_desambigua_solo` — `uq_cb_slug` es único **globalmente**, y
 *   quien da de alta un cliente no eligió el slug ni sabe qué hay cogido en
 *   otros clientes. No puede recibir un error por eso.
 */
final class MarcasTest extends TestCase
{
    use RefreshDatabase;

    private int $paisPE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');
    }

    public function test_dar_de_alta_un_cliente_crea_su_primera_marca(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post('/clientes', $this->cliente())
            ->assertSessionHas('exito');

        $clienteId = (int) DB::table('client_organizations')->where('client_code', 'ACME-01')->value('id');
        $marcas = DB::table('client_brands')->where('client_organization_id', $clienteId)->get();

        $this->assertCount(1, $marcas);
        $this->assertSame('ACME', $marcas->first()->name);
        $this->assertSame('acme', $marcas->first()->slug);
        $this->assertSame('active', $marcas->first()->status);
    }

    /**
     * El slug es único GLOBALMENTE, no por cliente.
     *
     * Dos clientes distintos que se llamen igual —o dos marcas homónimas de
     * clientes distintos— son perfectamente posibles, y el segundo en llegar no
     * puede llevarse un error de unicidad por un campo que nunca vio.
     */
    public function test_el_slug_se_desambigua_solo(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');

        $this->actingAs($gestor)->post('/clientes', $this->cliente());
        $this->actingAs($gestor)->post('/clientes', $this->cliente([
            'client_code' => 'ACME-02',
        ]))->assertSessionHas('exito');

        $slugs = DB::table('client_brands')->orderBy('id')->pluck('slug')->all();

        $this->assertSame(['acme', 'acme-2'], $slugs);
    }

    public function test_una_marca_nueva_con_sus_categorias(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $categorias = DB::table('categories')->orderBy('id')->limit(2)->pluck('id')->all();

        $this->actingAs($gestor)
            ->post("/clientes/{$uuid}/marcas", [
                'name' => 'Segunda marca',
                'status' => 'active',
                'categorias' => $categorias,
            ])
            ->assertSessionHas('exito');

        $marca = DB::table('client_brands')->where('name', 'Segunda marca')->first();

        $this->assertNotNull($marca);
        $this->assertSame('segunda-marca', $marca->slug);
        $this->assertSame(2, DB::table('client_brand_categories')->where('client_brand_id', $marca->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_brand.created']);
    }

    public function test_el_nombre_no_se_repite_dentro_del_mismo_cliente(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        // «ACME» ya existe: la creó el alta del cliente.
        $this->actingAs($gestor)
            ->post("/clientes/{$uuid}/marcas", ['name' => 'ACME', 'status' => 'active'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, DB::table('client_brands')->where('name', 'ACME')->count());
    }

    /**
     * Pero sí se repite entre clientes distintos: dos empresas pueden vender
     * una marca con el mismo nombre, y `uq_cb_name` es por cliente.
     */
    public function test_el_mismo_nombre_en_otro_cliente_si_vale(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post('/clientes', $this->cliente([
            'commercial_name' => 'Otra empresa', 'client_code' => 'OTRA-01',
        ]));
        $otro = (string) DB::table('client_organizations')->where('client_code', 'OTRA-01')->value('uuid');

        $this->actingAs($gestor)
            ->post("/clientes/{$otro}/marcas", ['name' => 'ACME', 'status' => 'active'])
            ->assertSessionHas('exito');

        $this->assertSame(2, DB::table('client_brands')->where('name', 'ACME')->count());
        // Y sus slugs son distintos, porque ese sí es global.
        $this->assertSame(2, DB::table('client_brands')->where('name', 'ACME')->distinct()->count('slug'));

        unset($uuid);
    }

    /**
     * El slug NO se rehace por editar cualquier cosa.
     *
     * Es parte de la identidad de la marca: si algún día está en una URL,
     * cambiarlo por tocar el sitio web rompería el enlace.
     */
    public function test_editar_la_web_no_mueve_el_slug(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);
        $marca = DB::table('client_brands')->first();

        $this->actingAs($gestor)
            ->put("/clientes/{$uuid}/marcas/{$marca->uuid}", [
                'name' => 'ACME', 'status' => 'active', 'website' => 'https://acme.test',
            ])
            ->assertSessionHas('exito');

        $this->assertSame('acme', DB::table('client_brands')->where('id', $marca->id)->value('slug'));
    }

    public function test_cambiar_el_nombre_si_rehace_el_slug(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);
        $marca = DB::table('client_brands')->first();

        $this->actingAs($gestor)
            ->put("/clientes/{$uuid}/marcas/{$marca->uuid}", ['name' => 'ACME Perú', 'status' => 'active'])
            ->assertSessionHas('exito');

        $this->assertSame('acme-peru', DB::table('client_brands')->where('id', $marca->id)->value('slug'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_brand.updated']);
    }

    /**
     * La URL de un cliente no sirve para tocar la marca de otro.
     */
    public function test_no_se_edita_la_marca_de_otro_cliente_por_la_url(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $this->crearCliente($gestor);

        $this->actingAs($gestor)->post('/clientes', $this->cliente([
            'commercial_name' => 'Otra empresa', 'client_code' => 'OTRA-01',
        ]));
        $otroUuid = (string) DB::table('client_organizations')->where('client_code', 'OTRA-01')->value('uuid');
        $marcaAjena = DB::table('client_brands')->where('name', 'ACME')->first();

        $this->actingAs($gestor)
            ->get("/clientes/{$otroUuid}/marcas/{$marcaAjena->uuid}/editar")
            ->assertNotFound();
    }

    public function test_ver_no_es_editar(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($this->usuarioCon('finance'))
            ->get("/clientes/{$uuid}/marcas/nueva")
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ apoyo

    private function crearCliente(User $quien): string
    {
        $this->actingAs($quien)->post('/clientes', $this->cliente());

        return (string) DB::table('client_organizations')->where('client_code', 'ACME-01')->value('uuid');
    }

    /**
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private function cliente(array $cambios = []): array
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
