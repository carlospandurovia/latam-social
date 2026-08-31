<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\ConFixturas;
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
    use ConFixturas;
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
            ->post('/backoffice/clientes', $this->cliente())
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

        $this->actingAs($gestor)->post('/backoffice/clientes', $this->cliente());
        $this->actingAs($gestor)->post('/backoffice/clientes', $this->cliente([
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
            ->post("/backoffice/clientes/{$uuid}/marcas", [
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
            ->post("/backoffice/clientes/{$uuid}/marcas", ['name' => 'ACME', 'status' => 'active'])
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

        $this->actingAs($gestor)->post('/backoffice/clientes', $this->cliente([
            'commercial_name' => 'Otra empresa', 'client_code' => 'OTRA-01',
        ]));
        $otro = (string) DB::table('client_organizations')->where('client_code', 'OTRA-01')->value('uuid');

        $this->actingAs($gestor)
            ->post("/backoffice/clientes/{$otro}/marcas", ['name' => 'ACME', 'status' => 'active'])
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
            ->put("/backoffice/clientes/{$uuid}/marcas/{$marca->uuid}", [
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
            ->put("/backoffice/clientes/{$uuid}/marcas/{$marca->uuid}", ['name' => 'ACME Perú', 'status' => 'active'])
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

        $this->actingAs($gestor)->post('/backoffice/clientes', $this->cliente([
            'commercial_name' => 'Otra empresa', 'client_code' => 'OTRA-01',
        ]));
        $otroUuid = (string) DB::table('client_organizations')->where('client_code', 'OTRA-01')->value('uuid');
        $marcaAjena = DB::table('client_brands')->where('name', 'ACME')->first();

        $this->actingAs($gestor)
            ->get("/backoffice/clientes/{$otroUuid}/marcas/{$marcaAjena->uuid}/editar")
            ->assertNotFound();
    }

    public function test_ver_no_es_editar(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($this->usuarioCon('finance'))
            ->get("/backoffice/clientes/{$uuid}/marcas/nueva")
            ->assertForbidden();
    }

    /**
     * Un nombre comercial largo no puede tumbar el alta del cliente.
     *
     * `client_organizations.commercial_name` admite 160 y `client_brands.name`
     * son 120. Como el alta crea la primera marca con el mismo nombre
     * (`DEC-074`), un cliente con nombre de 121 a 160 caracteres reventaba con
     * `1406 Data too long`, y al ir dentro de una transacción **se perdía el
     * cliente entero**: un 500, no un mensaje. La marca se recorta a 120.
     */
    public function test_un_nombre_comercial_largo_no_tumba_el_alta(): void
    {
        $largo = str_repeat('A', 155);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post('/backoffice/clientes', $this->cliente(['commercial_name' => $largo]))
            ->assertSessionHas('exito');

        $cliente = DB::table('client_organizations')->where('client_code', 'ACME-01')->first();
        $marca = DB::table('client_brands')->where('client_organization_id', $cliente->id)->first();

        $this->assertSame($largo, $cliente->commercial_name, 'el cliente conserva su nombre entero');
        $this->assertSame(120, mb_strlen((string) $marca->name), 'la marca se recorta al ancho de su columna');
    }

    /**
     * Editar una marca sin mandar la sección de categorías no las borra.
     *
     * `sincronizarCategorias()` empieza por un `delete()`, y un
     * `<input type="checkbox">` sin marcar no se manda: sin el testigo oculto,
     * cualquier petición que no trajera `categorias` apagaba la detección de
     * conflictos de marca (`BR-CAMPAIGN-007`) de esa marca, en silencio.
     */
    public function test_editar_sin_mandar_categorias_no_las_borra(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);
        $marca = DB::table('client_brands')->first();
        $categorias = DB::table('categories')->orderBy('id')->limit(2)->pluck('id')->all();

        $this->actingAs($gestor)->put("/backoffice/clientes/{$uuid}/marcas/{$marca->uuid}", [
            'name' => 'ACME', 'status' => 'active',
            'categorias' => $categorias, 'categorias_enviadas' => '1',
        ]);
        $this->assertSame(2, DB::table('client_brand_categories')->where('client_brand_id', $marca->id)->count());

        // Sin `categorias_enviadas`: la seccion no venia, no se toca.
        $this->actingAs($gestor)->put("/backoffice/clientes/{$uuid}/marcas/{$marca->uuid}", [
            'name' => 'ACME Peru', 'status' => 'active',
        ])->assertSessionHas('exito');

        $this->assertSame(2, DB::table('client_brand_categories')->where('client_brand_id', $marca->id)->count(),
            'una peticion sin la seccion de categorias no puede borrarlas');
    }

    /**
     * **Guardar sin tocar las categorías no anota un cambio de categorías.**
     *
     * `$antes` salía de `pluck('category_id')` **sin castear**, y `$despues` ya
     * venía a `int`. Con `!==`, `['1'] !== [1]` es siempre cierto: la bitácora
     * anotaría un cambio de categorías cada vez que se guarda la marca, haya
     * cambiado o no. Y una bitácora que dice que algo cambió cuando no cambió
     * es peor que una que se lo calla: enseña a no leerla.
     *
     * ### Lo que esta prueba NO puede demostrar
     *
     * **Depende del driver.** Que `pluck()` devuelva `'1'` o `1` lo decide PDO:
     * con `PDO::ATTR_EMULATE_PREPARES` o con drivers antiguos son cadenas; en
     * el contenedor donde se escribió esto son enteros nativos, y ahí el
     * defecto no se dispara. Se comprobó: quitando el casteo, esta prueba
     * **sigue verde**.
     *
     * O sea que no se sabe si el fallo estaba vivo en alguna máquina o sólo
     * latente en todas. Lo que sí se sabe es que casteando los dos lados la
     * comparación deja de depender del driver, y eso es lo que fija esta
     * prueba: la intención, no la reproducción.
     *
     * Salió de PHPStan quejándose del `collect()` de al lado — el fallo de
     * tipos y el de negocio eran el mismo.
     */
    public function test_guardar_sin_tocar_las_categorias_no_anota_un_cambio(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);
        $marca = DB::table('client_brands')->first();
        $categorias = DB::table('categories')->orderBy('id')->limit(2)->pluck('id')->all();

        $this->actingAs($gestor)->put("/backoffice/clientes/{$uuid}/marcas/{$marca->uuid}", [
            'name' => 'ACME', 'status' => 'active',
            'categorias' => $categorias, 'categorias_enviadas' => '1',
        ]);

        // La marca de agua: solo interesan las anotaciones POSTERIORES al
        // segundo guardado. La primera vez las categorias SI cambiaron --de
        // ninguna a dos-- y anotarlo esta bien; mirar las dos juntas haria que
        // esta prueba fallara por el motivo equivocado.
        $antes = (int) DB::table('audit_logs')->max('id');

        // Se vuelve a guardar EXACTAMENTE lo mismo.
        $this->actingAs($gestor)->put("/backoffice/clientes/{$uuid}/marcas/{$marca->uuid}", [
            'name' => 'ACME', 'status' => 'active',
            'categorias' => $categorias, 'categorias_enviadas' => '1',
        ])->assertSessionHas('aviso');

        $anotaciones = DB::table('audit_logs')
            ->where('id', '>', $antes)
            ->where('action', 'client_brand.updated')
            ->pluck('changes');

        foreach ($anotaciones as $cambio) {
            $this->assertStringNotContainsString('categorias', (string) $cambio,
                'no cambiaron las categorias: anotarlo enseña a no leer la bitacora');
        }
    }

    /**
     * Pero desmarcarlas TODAS sigue siendo posible: para eso está el testigo.
     * Si «ninguna» no se pudiera expresar, la regla nueva sería otra trampa.
     */
    public function test_desmarcar_todas_las_categorias_si_las_borra(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);
        $marca = DB::table('client_brands')->first();

        $this->actingAs($gestor)->put("/backoffice/clientes/{$uuid}/marcas/{$marca->uuid}", [
            'name' => 'ACME', 'status' => 'active', 'categorias_enviadas' => '1',
            'categorias' => DB::table('categories')->orderBy('id')->limit(1)->pluck('id')->all(),
        ]);
        $this->assertSame(1, DB::table('client_brand_categories')->where('client_brand_id', $marca->id)->count());

        $this->actingAs($gestor)->put("/backoffice/clientes/{$uuid}/marcas/{$marca->uuid}", [
            'name' => 'ACME', 'status' => 'paused', 'categorias_enviadas' => '1',
        ])->assertSessionHas('exito');

        $this->assertSame(0, DB::table('client_brand_categories')->where('client_brand_id', $marca->id)->count());
    }

    // ------------------------------------------------------------------ apoyo

    private function crearCliente(User $quien): string
    {
        $this->actingAs($quien)->post('/backoffice/clientes', $this->cliente());

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
}
