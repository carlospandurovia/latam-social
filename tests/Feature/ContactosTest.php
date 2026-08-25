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
 * Contactos del cliente (iteración 4.3).
 *
 * `uq_contacts_primary` deja **un principal activo por cliente y tipo**. La
 * suite SQL `4.3-contactos.sh` ya comprueba que la base lo impone; lo que se
 * comprueba aquí es lo otro, que es lo que le toca a la aplicación: que ese
 * límite **nunca llegue al operador en forma de `Duplicate entry`**.
 *
 * Son las tres maniobras que en SQL crudo dan `1062` —subir a un suplente,
 * reactivar a quien conservaba la marca, mover a alguien a un tipo ocupado— y
 * las tres tienen aquí su prueba de que por la pantalla salen bien.
 */
final class ContactosTest extends TestCase
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

    public function test_alta_de_un_contacto(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)
            ->post("/clientes/{$uuid}/contactos", $this->contacto())
            ->assertSessionHas('exito');

        $fila = DB::table('contacts')->where('contact_email', 'ana@acme.test')->first();

        $this->assertNotNull($fila);
        $this->assertSame('commercial', $fila->contact_type);
        $this->assertSame('active', $fila->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_contact.created']);
    }

    /**
     * La que descubre si las de rechazo mienten.
     *
     * Los cuatro tipos son puestos distintos: un cliente puede tener cuatro
     * principales a la vez. Si `uq_contacts_primary` no llevara `contact_type`,
     * todas las pruebas de rechazo seguirían verdes y esta sería la única roja.
     */
    public function test_cada_tipo_tiene_su_propio_principal(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        foreach (['commercial', 'billing', 'legal', 'operations'] as $i => $tipo) {
            $this->actingAs($gestor)
                ->post("/clientes/{$uuid}/contactos", $this->contacto([
                    'full_name' => "Principal {$tipo}",
                    'contact_email' => "{$tipo}@acme.test",
                    'contact_type' => $tipo,
                    'is_primary' => '1',
                ]))
                ->assertSessionHas('exito');

            unset($i);
        }

        $this->assertSame(4, DB::table('contacts')
            ->where('is_primary', 1)->where('status', 'active')->count());
    }

    /**
     * Y varios NO principales del mismo tipo conviven sin estorbarse: la puerta
     * los deja en `NULL` y los `NULL` no chocan entre sí.
     */
    public function test_varios_suplentes_del_mismo_tipo_conviven(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        foreach (['uno', 'dos', 'tres'] as $n) {
            $this->actingAs($gestor)
                ->post("/clientes/{$uuid}/contactos", $this->contacto([
                    'full_name' => "Suplente {$n}",
                    'contact_email' => "{$n}@acme.test",
                ]))
                ->assertSessionHas('exito');
        }

        $this->assertSame(3, DB::table('contacts')->where('contact_type', 'commercial')->count());
    }

    /**
     * El relevo (`DEC-075`): dar de alta a un principal donde ya había uno no
     * es un error, es un relevo. Y se dice a quién se relevó.
     */
    public function test_un_principal_nuevo_releva_al_anterior_y_lo_dice(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post("/clientes/{$uuid}/contactos", $this->contacto([
            'full_name' => 'Ana Primera', 'is_primary' => '1',
        ]));

        $respuesta = $this->actingAs($gestor)->post("/clientes/{$uuid}/contactos", $this->contacto([
            'full_name' => 'Beto Segundo',
            'contact_email' => 'beto@acme.test',
            'is_primary' => '1',
        ]));

        $respuesta->assertSessionHas('exito');
        // No basta con que no reviente: el operador tiene que enterarse de a
        // quién acaba de desplazar, o el relevo es un cambio invisible.
        $this->assertStringContainsString('Ana Primera', (string) session('exito'));

        $this->assertSame(0, (int) DB::table('contacts')->where('full_name', 'Ana Primera')->value('is_primary'));
        $this->assertSame(1, (int) DB::table('contacts')->where('full_name', 'Beto Segundo')->value('is_primary'));
        // Y sigue habiendo exactamente uno.
        $this->assertSame(1, DB::table('contacts')
            ->where('contact_type', 'commercial')->where('is_primary', 1)->where('status', 'active')->count());
    }

    /**
     * Maniobra 1 de las tres que dan `1062` en SQL crudo: subir a un suplente
     * mientras el puesto está ocupado.
     */
    public function test_subir_a_un_suplente_releva_al_que_estaba(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post("/clientes/{$uuid}/contactos", $this->contacto([
            'full_name' => 'Ana Primera', 'is_primary' => '1',
        ]));
        $this->actingAs($gestor)->post("/clientes/{$uuid}/contactos", $this->contacto([
            'full_name' => 'Fito Suplente', 'contact_email' => 'fito@acme.test',
        ]));

        $fito = DB::table('contacts')->where('full_name', 'Fito Suplente')->first();

        $this->actingAs($gestor)
            ->put("/clientes/{$uuid}/contactos/{$fito->uuid}", $this->contacto([
                'full_name' => 'Fito Suplente', 'contact_email' => 'fito@acme.test',
                'is_primary' => '1',
            ]))
            ->assertSessionHas('exito');

        $this->assertSame(0, (int) DB::table('contacts')->where('full_name', 'Ana Primera')->value('is_primary'));
        $this->assertSame(1, (int) DB::table('contacts')->where('id', $fito->id)->value('is_primary'));
    }

    /**
     * Maniobra 2: reactivar a quien conservaba `is_primary = 1`.
     *
     * Desactivar libera el puesto pero **no le borra la marca** —es un dato, no
     * un permiso—. Si mientras tanto otro lo ocupó, volver a activarlo choca en
     * la base. Por la pantalla, releva.
     */
    public function test_reactivar_a_un_antiguo_principal_no_revienta(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post("/clientes/{$uuid}/contactos", $this->contacto([
            'full_name' => 'Ana Primera', 'is_primary' => '1',
        ]));
        $ana = DB::table('contacts')->where('full_name', 'Ana Primera')->first();

        // Se da de baja: el puesto queda libre y conserva la marca.
        $this->actingAs($gestor)->put("/clientes/{$uuid}/contactos/{$ana->uuid}", $this->contacto([
            'full_name' => 'Ana Primera', 'is_primary' => '1', 'status' => 'inactive',
        ]));
        $this->assertSame(1, (int) DB::table('contacts')->where('id', $ana->id)->value('is_primary'));

        // Otro ocupa el puesto.
        $this->actingAs($gestor)->post("/clientes/{$uuid}/contactos", $this->contacto([
            'full_name' => 'Beto Segundo', 'contact_email' => 'beto@acme.test', 'is_primary' => '1',
        ]));

        // Y ahora Ana vuelve. En SQL crudo esto es un 1062.
        $this->actingAs($gestor)
            ->put("/clientes/{$uuid}/contactos/{$ana->uuid}", $this->contacto([
                'full_name' => 'Ana Primera', 'is_primary' => '1', 'status' => 'active',
            ]))
            ->assertSessionHas('exito');

        $this->assertSame(0, (int) DB::table('contacts')->where('full_name', 'Beto Segundo')->value('is_primary'));
        $this->assertSame(1, DB::table('contacts')
            ->where('contact_type', 'commercial')->where('is_primary', 1)->where('status', 'active')->count());
    }

    /**
     * Maniobra 3: mover al principal de un tipo a otro tipo que ya tiene
     * principal.
     */
    public function test_mover_a_un_principal_a_un_tipo_ocupado_releva(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post("/clientes/{$uuid}/contactos", $this->contacto([
            'full_name' => 'Ana Comercial', 'is_primary' => '1',
        ]));
        $this->actingAs($gestor)->post("/clientes/{$uuid}/contactos", $this->contacto([
            'full_name' => 'Carla Factura', 'contact_email' => 'carla@acme.test',
            'contact_type' => 'billing', 'is_primary' => '1',
        ]));

        $carla = DB::table('contacts')->where('full_name', 'Carla Factura')->first();

        $this->actingAs($gestor)
            ->put("/clientes/{$uuid}/contactos/{$carla->uuid}", $this->contacto([
                'full_name' => 'Carla Factura', 'contact_email' => 'carla@acme.test',
                'contact_type' => 'commercial', 'is_primary' => '1',
            ]))
            ->assertSessionHas('exito');

        $this->assertSame(0, (int) DB::table('contacts')->where('full_name', 'Ana Comercial')->value('is_primary'));
        $this->assertSame('commercial', DB::table('contacts')->where('id', $carla->id)->value('contact_type'));
    }

    /**
     * Desactivar al principal libera el puesto sin que haya que acordarse de
     * bajarle la marca antes. Es lo que compra meter `status` en la puerta.
     */
    public function test_desactivar_al_principal_libera_el_puesto(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post("/clientes/{$uuid}/contactos", $this->contacto([
            'full_name' => 'Ana Primera', 'is_primary' => '1',
        ]));
        $ana = DB::table('contacts')->where('full_name', 'Ana Primera')->first();

        $this->actingAs($gestor)->put("/clientes/{$uuid}/contactos/{$ana->uuid}", $this->contacto([
            'full_name' => 'Ana Primera', 'is_primary' => '1', 'status' => 'inactive',
        ]));

        $this->actingAs($gestor)
            ->post("/clientes/{$uuid}/contactos", $this->contacto([
                'full_name' => 'Beto Segundo', 'contact_email' => 'beto@acme.test', 'is_primary' => '1',
            ]))
            ->assertSessionHas('exito');

        // Y el mensaje NO nombra a Ana: no se relevó a nadie, el puesto estaba
        // libre. Anunciar un relevo que no ocurrió es tan malo como callarlo.
        $this->assertStringNotContainsString('Ana Primera', (string) session('exito'));
    }

    public function test_un_tipo_que_no_existe_se_rechaza_en_el_formulario(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        // Sin esta validación el valor llegaría a `ck_contacts_type` y el
        // operador vería un 45000 en vez de un mensaje.
        $this->actingAs($gestor)
            ->post("/clientes/{$uuid}/contactos", $this->contacto(['contact_type' => 'ventas']))
            ->assertSessionHasErrors('contact_type');

        $this->assertSame(0, DB::table('contacts')->count());
    }

    public function test_el_correo_tiene_que_parecer_un_correo(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)
            ->post("/clientes/{$uuid}/contactos", $this->contacto(['contact_email' => 'esto no es un correo']))
            ->assertSessionHasErrors('contact_email');
    }

    /**
     * Pero el correo repetido SÍ vale: es un canal comercial compartido
     * (`facturacion@cliente.com`), no una identidad de acceso. No se añade una
     * regla que la base no tiene (`Q-53`).
     */
    public function test_el_mismo_correo_en_dos_contactos_es_legitimo(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post("/clientes/{$uuid}/contactos", $this->contacto([
            'full_name' => 'Ana', 'contact_email' => 'facturacion@acme.test', 'contact_type' => 'billing',
        ]));
        $this->actingAs($gestor)
            ->post("/clientes/{$uuid}/contactos", $this->contacto([
                'full_name' => 'Beto', 'contact_email' => 'facturacion@acme.test', 'contact_type' => 'billing',
            ]))
            ->assertSessionHas('exito');

        $this->assertSame(2, DB::table('contacts')->where('contact_email', 'facturacion@acme.test')->count());
    }

    /**
     * La URL de un cliente no sirve para tocar el contacto de otro.
     */
    public function test_no_se_edita_el_contacto_de_otro_cliente_por_la_url(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post("/clientes/{$uuid}/contactos", $this->contacto());
        $ajeno = DB::table('contacts')->first();

        $this->actingAs($gestor)->post('/clientes', $this->cliente([
            'commercial_name' => 'Otra empresa', 'client_code' => 'OTRA-01',
        ]));
        $otro = (string) DB::table('client_organizations')->where('client_code', 'OTRA-01')->value('uuid');

        $this->actingAs($gestor)
            ->get("/clientes/{$otro}/contactos/{$ajeno->uuid}/editar")
            ->assertNotFound();
    }

    public function test_ver_no_es_editar(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($this->usuarioCon('finance'))
            ->get("/clientes/{$uuid}/contactos/nuevo")
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private function contacto(array $cambios = []): array
    {
        return array_merge([
            'full_name' => 'Ana Torres',
            'contact_email' => 'ana@acme.test',
            'phone' => '999888777',
            'position' => 'Gerente de marketing',
            'contact_type' => 'commercial',
            'status' => 'active',
        ], $cambios);
    }

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
}
