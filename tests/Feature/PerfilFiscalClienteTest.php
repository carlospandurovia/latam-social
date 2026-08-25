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
 * La identidad fiscal del cliente (iteración 4.4).
 *
 * De aquí salen `receiver_legal_name_snapshot` y `receiver_tax_id_snapshot` de
 * `invoices`: el nombre y el RUC que se imprimen en una factura.
 *
 * La prueba que justifica la iteración entera es
 * `test_abrir_un_periodo_cierra_el_anterior_el_dia_antes`. `valid_to` es
 * **inclusivo**, y cerrar el anterior con el `valid_from` del siguiente los deja
 * solapados un día. Ese fallo ha aparecido en **seis** sitios de este proyecto,
 * y en cada uno la consecuencia fue la misma: para el día del relevo hay dos
 * respuestas a «¿con qué identidad se factura hoy?».
 */
final class PerfilFiscalClienteTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $paisPE;

    private int $paisCO;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');
        $this->paisCO = (int) DB::table('countries')->where('iso2', 'CO')->value('id');
    }

    public function test_registrar_la_primera_identidad_fiscal(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)
            ->post("/clientes/{$uuid}/fiscal", $this->fiscal())
            ->assertSessionHas('exito');

        $perfil = DB::table('client_tax_profiles')->where('tax_id_number', '20123456789')->first();

        $this->assertNotNull($perfil);
        $this->assertNull($perfil->valid_to, 'la primera identidad nace vigente');
        $this->assertSame(30, (int) $perfil->payment_term_days);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_tax_profile.created']);
    }

    /**
     * **La prueba de la iteración.**
     *
     * El anterior se cierra el día ANTES, no el mismo día. Si se cerrara el
     * mismo día, el 1 de junio habría dos identidades fiscales vigentes y la
     * factura de ese día podría salir con cualquiera de las dos.
     */
    public function test_abrir_un_periodo_cierra_el_anterior_el_dia_antes(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post("/clientes/{$uuid}/fiscal", $this->fiscal());

        $this->actingAs($gestor)
            ->post("/clientes/{$uuid}/fiscal", $this->fiscal([
                'legal_name' => 'ACME SAC',
                'tax_id_number' => '20999999999',
                'valid_from' => '2026-06-01',
            ]))
            ->assertSessionHas('exito');

        $anterior = DB::table('client_tax_profiles')->where('tax_id_number', '20123456789')->first();
        $nuevo = DB::table('client_tax_profiles')->where('tax_id_number', '20999999999')->first();

        $this->assertSame('2026-05-31', (string) $anterior->valid_to, 'el dia ANTES, no el mismo dia');
        $this->assertNull($nuevo->valid_to);

        // Y el mensaje dice la fecha de cierre. Es el dato que decide con qué
        // identidad se factura el día del relevo: callarlo sería esconder justo
        // lo que seis veces se hizo mal.
        $this->assertStringContainsString('2026-05-31', (string) session('exito'));
    }

    /**
     * No queda ningún día con dos identidades vigentes a la vez.
     *
     * Es la propiedad de verdad, comprobada consultando la base como lo hará
     * la facturación: «¿qué identidad aplica el día X?» tiene que devolver una.
     */
    public function test_ningun_dia_tiene_dos_identidades_aplicables(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post("/clientes/{$uuid}/fiscal", $this->fiscal());
        $this->actingAs($gestor)->post("/clientes/{$uuid}/fiscal", $this->fiscal([
            'tax_id_number' => '20999999999', 'valid_from' => '2026-06-01',
        ]));

        foreach (['2026-05-30', '2026-05-31', '2026-06-01', '2026-06-02'] as $dia) {
            $aplicables = DB::table('client_tax_profiles')
                ->where('country_id', $this->paisPE)
                ->where('valid_from', '<=', $dia)
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $dia))
                ->count();

            $this->assertSame(1, $aplicables, "el {$dia} deberia tener exactamente una identidad aplicable");
        }
    }

    /**
     * `DEC-071`: el periodo nuevo tiene que empezar DESPUÉS que el vigente.
     *
     * Y se contesta con palabras, no con el `45000` de `ck_ctxp_dates` que
     * saldría al intentar cerrar el anterior «el día antes» de su propio inicio.
     */
    public function test_un_periodo_que_empieza_antes_que_el_vigente_se_rechaza_con_palabras(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post("/clientes/{$uuid}/fiscal", $this->fiscal());

        $this->actingAs($gestor)
            ->post("/clientes/{$uuid}/fiscal", $this->fiscal([
                'tax_id_number' => '20999999999', 'valid_from' => '2025-06-01',
            ]))
            ->assertSessionHas('aviso');

        $this->assertStringContainsString('tiene que empezar despues', (string) session('aviso'));
        $this->assertSame(1, DB::table('client_tax_profiles')->count(), 'no se creo nada');
    }

    /** El mismo día que el vigente también se rechaza: no sólo antes. */
    public function test_un_periodo_que_empieza_el_mismo_dia_que_el_vigente_se_rechaza(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post("/clientes/{$uuid}/fiscal", $this->fiscal());

        $this->actingAs($gestor)
            ->post("/clientes/{$uuid}/fiscal", $this->fiscal(['tax_id_number' => '20999999999']))
            ->assertSessionHas('aviso');

        $this->assertSame(1, DB::table('client_tax_profiles')->count());
    }

    /**
     * El mismo documento no puede ser la identidad vigente de dos clientes en
     * el mismo país, y el aviso **nombra al otro cliente**: lo que ese choque
     * significa casi siempre es que la misma empresa está dada de alta dos veces.
     */
    public function test_el_mismo_ruc_en_otro_cliente_avisa_y_dice_cual(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);
        $this->actingAs($gestor)->post("/clientes/{$uuid}/fiscal", $this->fiscal());

        $this->actingAs($gestor)->post('/clientes', $this->cliente([
            'commercial_name' => 'Otra empresa', 'client_code' => 'OTRA-01',
        ]));
        $otro = (string) DB::table('client_organizations')->where('client_code', 'OTRA-01')->value('uuid');

        $this->actingAs($gestor)
            ->post("/clientes/{$otro}/fiscal", $this->fiscal())
            ->assertSessionHas('aviso');

        $this->assertStringContainsString('ACME', (string) session('aviso'));
        $this->assertSame(1, DB::table('client_tax_profiles')->count());
    }

    /**
     * Pero el mismo cliente sí puede tener identidad en varios países: es el
     * caso `P-02` de docs 2.2, el grupo que factura desde dos filiales.
     */
    public function test_el_mismo_cliente_tiene_identidad_en_varios_paises(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post("/clientes/{$uuid}/fiscal", $this->fiscal());
        $this->actingAs($gestor)
            ->post("/clientes/{$uuid}/fiscal", $this->fiscal([
                'country_id' => $this->paisCO,
                'legal_name' => 'ACME Colombia SAS',
                'tax_id_type' => 'NIT',
                'tax_id_number' => '900111111',
            ]))
            ->assertSessionHas('exito');

        $this->assertSame(2, DB::table('client_tax_profiles')->whereNull('valid_to')->count());
    }

    public function test_corregir_el_vigente_no_abre_periodo(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);
        $this->actingAs($gestor)->post("/clientes/{$uuid}/fiscal", $this->fiscal());

        $perfil = DB::table('client_tax_profiles')->first();

        $this->actingAs($gestor)
            ->put("/clientes/{$uuid}/fiscal/{$perfil->id}", $this->fiscal([
                'legal_name' => 'ACME Sociedad Anonima Cerrada',
            ], correccion: true))
            ->assertSessionHas('exito');

        $this->assertSame(1, DB::table('client_tax_profiles')->count(), 'corregir no crea filas');
        $this->assertSame(
            'ACME Sociedad Anonima Cerrada',
            DB::table('client_tax_profiles')->where('id', $perfil->id)->value('legal_name'),
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_tax_profile.updated']);
    }

    /**
     * `DEC-078`: un periodo cerrado está congelado. Es el registro de quién era
     * el cliente entre esas fechas y de ahí se explica una factura pasada.
     */
    public function test_un_periodo_cerrado_no_se_corrige(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($gestor)->post("/clientes/{$uuid}/fiscal", $this->fiscal());
        $this->actingAs($gestor)->post("/clientes/{$uuid}/fiscal", $this->fiscal([
            'tax_id_number' => '20999999999', 'valid_from' => '2026-06-01',
        ]));

        $cerrado = DB::table('client_tax_profiles')->whereNotNull('valid_to')->first();
        $this->assertNotNull($cerrado, 'la premisa: tiene que haber uno cerrado');

        $this->actingAs($gestor)
            ->get("/clientes/{$uuid}/fiscal/{$cerrado->id}/corregir")
            ->assertNotFound();

        $this->actingAs($gestor)
            ->put("/clientes/{$uuid}/fiscal/{$cerrado->id}", $this->fiscal(['legal_name' => 'Reescrita'], correccion: true))
            ->assertNotFound();

        $this->assertSame(
            'ACME Sociedad Anonima',
            DB::table('client_tax_profiles')->where('id', $cerrado->id)->value('legal_name'),
        );
    }

    /** La URL de un cliente no sirve para tocar el perfil fiscal de otro. */
    public function test_no_se_corrige_el_perfil_de_otro_cliente_por_la_url(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);
        $this->actingAs($gestor)->post("/clientes/{$uuid}/fiscal", $this->fiscal());
        $ajeno = DB::table('client_tax_profiles')->first();

        $this->actingAs($gestor)->post('/clientes', $this->cliente([
            'commercial_name' => 'Otra empresa', 'client_code' => 'OTRA-01',
        ]));
        $otro = (string) DB::table('client_organizations')->where('client_code', 'OTRA-01')->value('uuid');

        $this->actingAs($gestor)
            ->get("/clientes/{$otro}/fiscal/{$ajeno->id}/corregir")
            ->assertNotFound();
    }

    public function test_el_plazo_de_pago_fuera_de_rango_se_rechaza_en_el_formulario(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        // Sin esta validación el valor llegaría a `ck_ctxp_term` y el operador
        // vería un 45000 en vez de un mensaje.
        $this->actingAs($gestor)
            ->post("/clientes/{$uuid}/fiscal", $this->fiscal(['payment_term_days' => 200]))
            ->assertSessionHasErrors('payment_term_days');

        $this->assertSame(0, DB::table('client_tax_profiles')->count());
    }

    /**
     * `client.tax.manage` es un permiso propio, no `client.manage`.
     *
     * `content_reviewer` tiene rol interno y ninguno de los dos: sirve para
     * comprobar que la puerta existe. La decisión de negocio fue dárselo a
     * `finance` y a `campaign_manager`, y las dos se comprueban abajo.
     */
    public function test_el_permiso_fiscal_es_propio(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');
        $uuid = $this->crearCliente($gestor);

        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->get("/clientes/{$uuid}/fiscal/nuevo")
            ->assertForbidden();
    }

    public function test_finanzas_tambien_registra_la_identidad_fiscal(): void
    {
        $uuid = $this->crearCliente($this->usuarioCon('campaign_manager'));

        // La otra mitad de la decisión: finanzas emite la factura, así que tiene
        // que poder corregir la identidad con la que se emite.
        $this->actingAs($this->usuarioCon('finance'))
            ->post("/clientes/{$uuid}/fiscal", $this->fiscal())
            ->assertSessionHas('exito');

        $this->assertSame(1, DB::table('client_tax_profiles')->count());
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private function fiscal(array $cambios = [], bool $correccion = false): array
    {
        $base = [
            'legal_name' => 'ACME Sociedad Anonima',
            'tax_id_type' => 'RUC',
            'tax_id_number' => '20123456789',
            'address_line1' => 'Av Siempre Viva 100',
            'city' => 'Lima',
            'payment_term_days' => 30,
        ];

        // En la corrección el formulario no pide país ni fecha: son la
        // identidad de la serie de periodos.
        if (!$correccion) {
            $base['country_id'] = $this->paisPE;
            $base['valid_from'] = '2026-01-01';
        }

        return array_merge($base, $cambios);
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
