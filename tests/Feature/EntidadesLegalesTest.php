<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Core\Services\Cobertura;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Sociedades del grupo y cobertura de facturación (iteración 4.5).
 *
 * La pantalla que `BR-LE-004` lleva nombrando desde 4.1 —*«dé de alta la
 * cobertura en Entidades legales»*— y que hasta ahora no existía (`Q-51`).
 *
 * La prueba que justifica la iteración es
 * `test_dar_de_baja_cierra_las_coberturas_y_libera_el_pais`. Sin eso, dar de
 * baja la sociedad que cubre un país lo deja **sin cubrir y sin poder
 * cubrirse**: `uq_lec_country` ocupa el sitio mire o no el estado de la
 * sociedad, pero quien resuelve la facturación sólo cuenta las activas.
 */
final class EntidadesLegalesTest extends TestCase
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

    public function test_alta_de_una_sociedad(): void
    {
        $this->actingAs($this->admin())
            ->post('/entidades', $this->sociedad())
            ->assertSessionHas('exito');

        $entidad = DB::table('legal_entities')->where('code', 'E45-A')->first();

        $this->assertNotNull($entidad);
        $this->assertSame('active', $entidad->status);
        // Nace sin cubrir nada, y el mensaje lo dice: una sociedad sin cobertura
        // no puede emitir una sola factura, y eso no puede ser una sorpresa.
        $this->assertSame(0, DB::table('legal_entity_countries')->where('legal_entity_id', $entidad->id)->count());
        $this->assertStringContainsString('no cubre ningun pais', (string) session('exito'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'legal_entity.created']);
    }

    public function test_declarar_cobertura_de_un_pais(): void
    {
        $admin = $this->admin();
        $uuid = $this->crearSociedad($admin);
        $pais = $this->paisSinCobertura();

        $this->actingAs($admin)
            ->post("/entidades/{$uuid}/cobertura", [
                'country_id' => $pais,
                'coverage_basis' => 'service_export',
                'valid_from' => '2026-01-01',
            ])
            ->assertSessionHas('exito');

        $this->assertSame(1, Cobertura::quienCubre($pais, '2026-06-01')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'legal_entity.coverage_opened']);
    }

    /**
     * El relevo: `valid_to` es INCLUSIVO, así que la anterior se cierra **el día
     * antes**. Si se cerrara el mismo día, ese día habría dos sociedades
     * facturando el mismo país.
     */
    public function test_el_relevo_cierra_la_anterior_el_dia_antes(): void
    {
        $admin = $this->admin();
        $pais = $this->paisSinCobertura();

        $primera = $this->crearSociedad($admin);
        $this->cubrir($admin, $primera, $pais, '2026-01-01');

        $segunda = $this->crearSociedad($admin, ['code' => 'E45-B', 'tax_id_number' => '20450000002']);
        $this->actingAs($admin)
            ->post("/entidades/{$segunda}/cobertura", [
                'country_id' => $pais,
                'coverage_basis' => 'local_entity',
                'valid_from' => '2026-06-01',
            ])
            ->assertSessionHas('exito');

        $cerrada = DB::table('legal_entity_countries')
            ->where('legal_entity_id', DB::table('legal_entities')->where('uuid', $primera)->value('id'))
            ->first();

        $this->assertSame('2026-05-31', (string) $cerrada->valid_to, 'el dia ANTES, no el mismo dia');
        $this->assertStringContainsString('2026-05-31', (string) session('exito'));

        // La propiedad de verdad: ningún día tiene dos sociedades facturando.
        foreach (['2026-05-30', '2026-05-31', '2026-06-01', '2026-06-02'] as $dia) {
            $this->assertSame(1, Cobertura::quienCubre($pais, $dia)->count(), "el {$dia} deberia tener una sola");
        }
    }

    public function test_una_cobertura_que_empieza_antes_que_la_vigente_se_rechaza_con_palabras(): void
    {
        $admin = $this->admin();
        $pais = $this->paisSinCobertura();

        $primera = $this->crearSociedad($admin);
        $this->cubrir($admin, $primera, $pais, '2026-06-01');

        $segunda = $this->crearSociedad($admin, ['code' => 'E45-B', 'tax_id_number' => '20450000002']);
        $this->actingAs($admin)
            ->post("/entidades/{$segunda}/cobertura", [
                'country_id' => $pais,
                'coverage_basis' => 'local_entity',
                'valid_from' => '2026-01-01',
            ])
            ->assertSessionHas('aviso');

        $this->assertStringContainsString('tiene que empezar despues', (string) session('aviso'));
        $this->assertSame(1, DB::table('legal_entity_countries')->where('country_id', $pais)->count(),
            'no se creo nada');
    }

    /**
     * **La prueba de la iteración.**
     *
     * Dar de baja cierra las coberturas abiertas (`DEC-081`). Sin eso el país
     * queda incomunicado: ninguna sociedad activa lo cubre y ninguna otra puede
     * tomarlo, porque la fila abierta de la inactiva sigue ocupando el sitio.
     */
    public function test_dar_de_baja_cierra_las_coberturas_y_libera_el_pais(): void
    {
        $admin = $this->admin();
        $pais = $this->paisSinCobertura();

        $primera = $this->crearSociedad($admin);
        $this->cubrir($admin, $primera, $pais, '2026-01-01');

        $this->actingAs($admin)
            ->post("/entidades/{$primera}/baja", ['hasta' => '2026-06-30', 'estado' => 'inactive'])
            ->assertSessionHas('exito');

        $this->assertSame('inactive', DB::table('legal_entities')->where('uuid', $primera)->value('status'));
        // Acotada al pais de la prueba: `CimientosSeeder` deja abiertas las
        // coberturas de CTS-PE y CTS-CO, y contarlas todas mediría el seeder.
        $this->assertSame(0, DB::table('legal_entity_countries')
            ->where('country_id', $pais)->whereNull('valid_to')->count(),
            'la cobertura tiene que quedar cerrada, o el pais queda bloqueado');

        // Y el mensaje dice qué queda descubierto y desde cuándo: el 30 de junio
        // todavía se podía facturar, así que el primer día sin cobertura es el 1
        // de julio. Ese día de diferencia es el error que este proyecto ha
        // cometido seis veces, contado al revés.
        $this->assertStringContainsString('2026-07-01', (string) session('exito'));

        // La consecuencia que importa: otra sociedad ya puede tomar el país.
        $segunda = $this->crearSociedad($admin, ['code' => 'E45-B', 'tax_id_number' => '20450000002']);
        $this->actingAs($admin)
            ->post("/entidades/{$segunda}/cobertura", [
                'country_id' => $pais,
                'coverage_basis' => 'service_export',
                'valid_from' => '2026-07-01',
            ])
            ->assertSessionHas('exito');

        $this->assertSame(1, Cobertura::quienCubre($pais, '2026-08-01')->count());
    }

    /**
     * Una cobertura que empieza DESPUÉS de la baja no se puede cerrar en esa
     * fecha (`ck_lec_dates`) ni borrar (es evidencia). Se dice y no se toca nada.
     */
    public function test_no_se_da_de_baja_si_deja_una_cobertura_imposible_de_cerrar(): void
    {
        $admin = $this->admin();
        $pais = $this->paisSinCobertura();

        $uuid = $this->crearSociedad($admin);
        $this->cubrir($admin, $uuid, $pais, '2027-01-01');

        $this->actingAs($admin)
            ->post("/entidades/{$uuid}/baja", ['hasta' => '2026-06-30', 'estado' => 'inactive'])
            ->assertSessionHas('aviso');

        $this->assertStringContainsString('empieza DESPUES', (string) session('aviso'));
        $this->assertSame('active', DB::table('legal_entities')->where('uuid', $uuid)->value('status'));
        $this->assertNull(
            DB::table('legal_entity_countries')->where('country_id', $pais)->value('valid_to'),
            'no se toco nada',
        );
    }

    /** Disolver exige decir cuándo: `ck_le_dissolved`. Lo pone el controlador. */
    public function test_disolver_registra_la_fecha(): void
    {
        $admin = $this->admin();
        $uuid = $this->crearSociedad($admin);

        $this->actingAs($admin)
            ->post("/entidades/{$uuid}/baja", ['hasta' => '2026-12-31', 'estado' => 'dissolved'])
            ->assertSessionHas('exito');

        $entidad = DB::table('legal_entities')->where('uuid', $uuid)->first();

        $this->assertSame('dissolved', $entidad->status);
        $this->assertSame('2026-12-31', (string) $entidad->dissolved_on);
    }

    /**
     * Una sociedad inactiva no puede declarar cobertura: ocuparía el sitio de un
     * país sin poder facturarlo, que es exactamente el bloqueo que esta
     * iteración arregla. Fabricarlo desde la pantalla sería absurdo.
     */
    public function test_una_sociedad_inactiva_no_declara_cobertura(): void
    {
        $admin = $this->admin();
        $pais = $this->paisSinCobertura();
        $uuid = $this->crearSociedad($admin);
        $this->actingAs($admin)->post("/entidades/{$uuid}/baja", ['hasta' => '2026-06-30', 'estado' => 'inactive']);

        $this->actingAs($admin)
            ->post("/entidades/{$uuid}/cobertura", [
                'country_id' => $pais,
                'coverage_basis' => 'service_export',
                'valid_from' => '2026-07-01',
            ])
            ->assertSessionHas('aviso');

        $this->assertSame(0, DB::table('legal_entity_countries')->where('country_id', $pais)->count());
    }

    public function test_el_codigo_no_se_repite(): void
    {
        $admin = $this->admin();
        $this->crearSociedad($admin);

        $this->actingAs($admin)
            ->post('/entidades', $this->sociedad(['tax_id_number' => '20450009999']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, DB::table('legal_entities')->where('code', 'E45-A')->count());
    }

    /** La misma empresa no se da de alta dos veces: `uq_le_taxid`. */
    public function test_el_mismo_documento_en_el_mismo_pais_no_se_repite(): void
    {
        $admin = $this->admin();
        $this->crearSociedad($admin);

        $this->actingAs($admin)
            ->post('/entidades', $this->sociedad(['code' => 'E45-B']))
            ->assertSessionHasErrors('tax_id_number');
    }

    /**
     * `legal_entity.manage` es de `admin` y de nadie más (decisión de negocio,
     * 2026-08-25). `finance` emite desde estas sociedades, no las crea.
     */
    public function test_el_permiso_es_solo_de_admin(): void
    {
        foreach (['finance', 'campaign_manager'] as $rol) {
            $this->actingAs($this->usuarioCon($rol))
                ->get('/entidades')
                ->assertForbidden();
        }
    }

    /**
     * Editar una sociedad SÍ comprueba `uq_le_taxid`.
     *
     * El país de constitución no se pide en la edición, así que la regla leía
     * `country_id` de la petición, obtenía `null`, y buscaba `country_id = 0`:
     * **la unicidad quedaba desactivada en toda edición** y el `1062` llegaba
     * crudo. Ahora el país se lee de la propia sociedad.
     */
    public function test_al_editar_no_se_puede_poner_el_documento_de_otra_sociedad(): void
    {
        $admin = $this->admin();
        $this->crearSociedad($admin);
        $segunda = $this->crearSociedad($admin, ['code' => 'E45-B', 'tax_id_number' => '20450000002']);

        $this->actingAs($admin)
            ->put("/entidades/{$segunda}", $this->sociedad([
                'tax_id_number' => '20450000001',
            ], edicion: true))
            ->assertSessionHasErrors('tax_id_number');

        $this->assertSame('20450000002',
            DB::table('legal_entities')->where('uuid', $segunda)->value('tax_id_number'));
    }

    /**
     * `ck_le_dates` exige `dissolved_on >= incorporated_on`. Sin veto, disolver
     * con una fecha anterior a la constitución daba un `45000` crudo.
     */
    public function test_no_se_disuelve_antes_de_constituirse(): void
    {
        $admin = $this->admin();
        $uuid = $this->crearSociedad($admin, ['incorporated_on' => '2020-05-01']);

        $this->actingAs($admin)
            ->post("/entidades/{$uuid}/baja", ['hasta' => '2019-12-31', 'estado' => 'dissolved'])
            ->assertSessionHas('aviso');

        $this->assertStringContainsString('no puede dejar de existir antes de existir', (string) session('aviso'));
        $this->assertSame('active', DB::table('legal_entities')->where('uuid', $uuid)->value('status'));
    }

    /**
     * Redeclarar la cobertura de un país que la sociedad ya cubre no la anuncia
     * a sí misma como relevada.
     */
    public function test_redeclarar_la_propia_cobertura_no_se_releva_a_si_misma(): void
    {
        $admin = $this->admin();
        $pais = $this->paisSinCobertura();
        $uuid = $this->crearSociedad($admin);
        $this->cubrir($admin, $uuid, $pais, '2026-01-01');

        $this->actingAs($admin)
            ->post("/entidades/{$uuid}/cobertura", [
                'country_id' => $pais,
                'coverage_basis' => 'local_entity',
                'valid_from' => '2026-06-01',
            ])
            ->assertSessionHas('exito');

        $mensaje = (string) session('exito');
        $this->assertStringNotContainsString('E45-A deja de cubrirlo', $mensaje,
            'una sociedad no se releva a si misma');
        $this->assertStringContainsString('Su cobertura anterior', $mensaje);
    }

    /**
     * Una fecha sin ceros no debe colarse hasta la base.
     *
     * `'2026-2-1' > '2026-11-01'` es cierto como cadena, así que la guarda de
     * relevo la dejaba pasar y el cierre calculado caía antes del `valid_from`
     * del periodo cerrado: `ck_lec_dates`, un `45000`. Se cierra en dos sitios
     * —`date_format:Y-m-d` aquí y la normalización en `Vigencia`—, y esta
     * prueba fija el de fuera.
     */
    public function test_una_fecha_sin_ceros_se_rechaza_en_el_formulario(): void
    {
        $admin = $this->admin();
        $pais = $this->paisSinCobertura();
        $uuid = $this->crearSociedad($admin);

        $this->actingAs($admin)
            ->post("/entidades/{$uuid}/cobertura", [
                'country_id' => $pais,
                'coverage_basis' => 'service_export',
                'valid_from' => '2026-2-1',
            ])
            ->assertSessionHasErrors('valid_from');

        $this->assertSame(0, DB::table('legal_entity_countries')->where('country_id', $pais)->count());
    }

    /**
     * **`abiertaEnPais()` mira el sitio, no el estado de quien lo ocupa.**
     *
     * El disparador de no-solapamiento no sabe de estados: una fila con
     * `valid_to IS NULL` ocupa el país aunque su sociedad esté inactiva. Si el
     * controlador sólo mirase las activas vería el sitio libre, dejaría pasar
     * el alta sin relevar a nadie, y el `45000` del disparador llegaría en
     * crudo a la pantalla.
     *
     * El estado se pone a mano a propósito: es exactamente lo que el sistema
     * producía antes de `DEC-081` —dar de baja sin cerrar coberturas— y sigue
     * siendo alcanzable desde fuera de esta pantalla. La aplicación tiene que
     * coincidir con la regla de la base, no con una versión más optimista.
     *
     * Esta prueba nació de una mutación: se volvió a meter el `where('le.status',
     * 'active')` en `Cobertura::abiertaEnPais()` y las quince pruebas de 4.5
     * siguieron verdes. La suite SQL sí lo veía —el disparador rechaza— pero
     * ninguna prueba comprobaba que la capa PHP lo evita ANTES.
     */
    public function test_una_cobertura_abierta_de_una_sociedad_inactiva_sigue_ocupando_el_pais(): void
    {
        $admin = $this->admin();
        $pais = $this->paisSinCobertura();

        $primera = $this->crearSociedad($admin);
        $this->cubrir($admin, $primera, $pais, '2026-01-01');

        DB::table('legal_entities')->where('uuid', $primera)->update(['status' => 'inactive']);

        $ocupada = Cobertura::abiertaEnPais($pais);
        $this->assertNotNull($ocupada, 'la fila abierta sigue ocupando el sitio');
        $this->assertSame('inactive', $ocupada->status, 'y se ve que su sociedad esta inactiva');

        // Nadie ACTIVO cubre el pais: las dos preguntas son distintas y el
        // codigo tiene una funcion para cada una.
        $this->assertTrue(Cobertura::quienCubre($pais, '2026-08-01')->isEmpty());

        $segunda = $this->crearSociedad($admin, ['code' => 'E45-B', 'tax_id_number' => '20450000002']);

        // 1. Empezar ANTES que la ocupada se veta con palabras, nombrandola.
        $this->actingAs($admin)
            ->post("/entidades/{$segunda}/cobertura", [
                'country_id' => $pais,
                'coverage_basis' => 'service_export',
                'valid_from' => '2025-12-01',
            ])
            ->assertSessionHas('aviso');

        $this->assertStringContainsString('E45-A', (string) session('aviso'));
        $this->assertSame(1, DB::table('legal_entity_countries')
            ->where('country_id', $pais)->count(), 'no se inserto nada');

        // 2. Empezar DESPUES releva: la anterior se cierra el dia antes y la
        //    nueva queda abierta. Sin mirar a la inactiva, `abrir()` no cerraria
        //    nada y el disparador rechazaria el INSERT.
        $this->actingAs($admin)
            ->post("/entidades/{$segunda}/cobertura", [
                'country_id' => $pais,
                'coverage_basis' => 'service_export',
                'valid_from' => '2026-07-01',
            ])
            ->assertSessionHas('exito');

        $filas = DB::table('legal_entity_countries as lec')
            ->join('legal_entities as le', 'le.id', '=', 'lec.legal_entity_id')
            ->where('lec.country_id', $pais)
            ->orderBy('lec.valid_from')
            ->get(['le.code', 'lec.valid_from', 'lec.valid_to']);

        $this->assertCount(2, $filas);
        $this->assertSame('E45-A', $filas[0]->code);
        // El dia ANTES, no el mismo dia: `valid_to` es inclusivo. Es el error
        // que este proyecto ha cometido seis veces.
        $this->assertSame('2026-06-30', (string) $filas[0]->valid_to);
        $this->assertSame('E45-B', $filas[1]->code);
        $this->assertNull($filas[1]->valid_to);
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Un país que nadie cubre todavía.
     *
     * Se comprueba, no se supone: `CimientosSeeder` declara cobertura para los
     * países activos, y una prueba que asume lo contrario se pone verde o roja
     * por el motivo equivocado. Es la lección de `ClientesTest` en 4.1.
     */
    private function paisSinCobertura(): int
    {
        $id = (int) DB::table('countries')->insertGetId([
            'iso2' => 'Z1', 'iso3' => 'ZZ1', 'numeric_code' => '901',
            'name' => 'Pais de prueba 4.5', 'phone_code' => '+901',
            'default_currency_code' => (string) DB::table('currencies')->value('code'),
            'timezone' => 'America/Lima', 'is_active' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(0, DB::table('legal_entity_countries')->where('country_id', $id)->count());

        return $id;
    }

    private function cubrir(User $quien, string $uuid, int $pais, string $desde): void
    {
        $this->actingAs($quien)->post("/entidades/{$uuid}/cobertura", [
            'country_id' => $pais,
            'coverage_basis' => 'service_export',
            'valid_from' => $desde,
        ]);
    }

    /**
     * @param array<string, mixed> $cambios
     */
    private function crearSociedad(User $quien, array $cambios = []): string
    {
        $datos = $this->sociedad($cambios);
        $this->actingAs($quien)->post('/entidades', $datos);

        return (string) DB::table('legal_entities')->where('code', $datos['code'])->value('uuid');
    }

    /**
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private function sociedad(array $cambios = [], bool $edicion = false): array
    {
        // En la edicion el formulario NO manda `code` ni `country_id`: son la
        // identidad de la sociedad. La prueba tiene que mandar lo mismo que el
        // formulario, o estaria probando otra cosa.
        if ($edicion) {
            $base = $this->sociedad($cambios);
            unset($base['code'], $base['country_id']);

            return $base;
        }

        return array_merge([
            'code' => 'E45-A',
            'legal_name' => 'Sociedad de prueba SAC',
            'country_id' => (int) DB::table('countries')->where('iso2', 'PE')->value('id'),
            'tax_id_type' => 'RUC',
            'tax_id_number' => '20450000001',
            'address_line1' => 'Av Siempre Viva 100',
            'city' => 'Lima',
            'default_currency_code' => (string) DB::table('currencies')->value('code'),
            'timezone' => 'America/Lima',
        ], $cambios);
    }

    private function admin(): User
    {
        return $this->usuarioCon('admin');
    }
}
