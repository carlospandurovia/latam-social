<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\FiltrosDeResumen;
use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Resumen;
use App\Shared\Auth\Permisos;
use Carbon\CarbonImmutable;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * `D-7`: el bloque comercial.
 *
 * ### La prueba que fija la decisión de diseño
 *
 * `test_un_prospecto_cuenta_en_el_periodo_en_que_llego_aunque_se_convierta_despues`.
 * Un prospecto de marzo que se convierte en mayo cuenta en **marzo**. Si contara
 * en mayo, la conversión de un periodo cambiaría según el día en que se mire, y
 * un número que se mueve solo no sirve para decidir nada.
 *
 * ### Y la que impide dibujar una mentira
 *
 * `test_el_reparto_saca_los_cinco_estados_aunque_esten_a_cero` fija que esto es
 * una **distribución** y no un embudo: los cinco estados salen siempre y no hay
 * porcentaje entre ellos, porque `client_leads` guarda un solo estado —el de
 * hoy— y no por dónde pasó cada uno (`T-113`).
 */
final class ComercialTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $paisPE;

    private int $paisOtro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
        Queue::fake();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');
        $this->paisOtro = (int) DB::table('countries')->where('is_active', 1)
            ->where('id', '<>', $this->paisPE)->orderBy('id')->value('id');

        self::assertNotSame(0, $this->paisOtro, 'Hacen falta al menos dos países activos.');
    }

    // -------------------------------------------------------------- conteos

    public function test_los_prospectos_se_cuentan_por_cuando_llegaron(): void
    {
        $this->prospecto(dias: 5);
        $this->prospecto(dias: 20);
        // Fuera del periodo por omision (30 dias).
        $this->prospecto(dias: 90);

        self::assertSame(2, $this->kpi('Prospectos recibidos')['valor']);
    }

    public function test_un_prospecto_cuenta_en_el_periodo_en_que_llego_aunque_se_convierta_despues(): void
    {
        // Llego hace 20 dias y HOY ya es cliente. Cuenta en este periodo, que es
        // cuando llego --no en el periodo en que se convirtio--.
        $this->prospecto(dias: 20, convertido: true);

        $ahora = Resumen::prospectosPorEstado(FiltrosDeResumen::porDefecto());

        self::assertSame(1, $ahora['total']);
        self::assertSame(1, $ahora['convertidos']);
        self::assertSame(100.0, $ahora['conversion']);
    }

    public function test_los_convertidos_se_cuentan_por_el_cliente_y_no_por_el_estado(): void
    {
        // Un prospecto marcado a mano como `qualified` pero que YA tiene su
        // cliente creado. El hecho es la fila del cliente; el estado se puede
        // mover a mano y quedarse atras.
        $this->prospecto(dias: 3, convertido: true, estado: 'qualified');

        self::assertSame(1, $this->kpi('Prospectos convertidos')['valor']);
    }

    public function test_los_clientes_nuevos_se_cuentan_por_su_fecha_de_alta(): void
    {
        $this->cliente('ACME-01', dias: 10);
        $this->cliente('ACME-02', dias: 200);

        self::assertSame(1, $this->kpi('Clientes nuevos')['valor']);
    }

    public function test_las_solicitudes_de_creador_se_cuentan_por_su_fecha(): void
    {
        $this->solicitud(dias: 4);
        $this->solicitud(dias: 4);
        $this->solicitud(dias: 120);

        self::assertSame(2, $this->kpi('Solicitudes de creador')['valor']);
    }

    // ------------------------------------------------------------- el reparto

    public function test_el_reparto_saca_los_cinco_estados_aunque_esten_a_cero(): void
    {
        $this->prospecto(dias: 2);

        $reparto = Resumen::prospectosPorEstado(FiltrosDeResumen::porDefecto());

        self::assertCount(5, $reparto['filas'], 'Los cinco estados salen siempre.');
        self::assertSame(
            ['new', 'contacted', 'qualified', 'converted', 'discarded'],
            array_column($reparto['filas'], 'clave'),
        );
        self::assertSame(1, $reparto['total']);
    }

    public function test_la_conversion_no_se_inventa_cuando_no_llego_nadie(): void
    {
        $reparto = Resumen::prospectosPorEstado(FiltrosDeResumen::porDefecto());

        self::assertSame(0, $reparto['total']);
        // `null` y no `0.0`: «0 % de conversión» sobre cero prospectos es un
        // juicio sobre algo que no ocurrió.
        self::assertNull($reparto['conversion']);
    }

    public function test_el_filtro_de_pais_recorta_los_prospectos(): void
    {
        $this->prospecto(dias: 3);
        $this->prospecto(dias: 3, paisId: $this->paisOtro);

        // El filtro se construye por la MISMA puerta que usa la pantalla
        // --`desdePeticion`--: el constructor es privado a proposito, y ademas
        // asi la prueba recorre el parseo de la peticion y no solo la consulta.
        $todos = FiltrosDeResumen::porDefecto();
        $peru = FiltrosDeResumen::desdePeticion(
            Request::create('/', 'GET', ['pais' => (string) $this->paisPE]),
        );

        self::assertSame($this->paisPE, $peru->paisId, 'El filtro tiene que haber calado.');
        self::assertSame(2, Resumen::prospectosPorEstado($todos)['total']);
        self::assertSame(1, Resumen::prospectosPorEstado($peru)['total']);
    }

    // -------------------------------------------------------------- pantalla

    public function test_el_panel_pinta_el_bloque_comercial(): void
    {
        $this->prospecto(dias: 3, convertido: true);

        $html = (string) $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringContainsString('Prospectos recibidos', $html);
        self::assertStringContainsString('En qué quedaron los prospectos del periodo', $html);
        // La frase que impide leerlo como un embudo. Si alguien la quita, esta
        // prueba lo dice.
        self::assertStringContainsString('No es un embudo', $html);
    }

    // ---------------------------------------------------------------- apoyo

    private function prospecto(
        int $dias,
        bool $convertido = false,
        ?string $estado = null,
        ?int $paisId = null,
    ): int {
        $cuando = CarbonImmutable::now()->subDays($dias);
        $clienteId = $convertido
            ? $this->cliente('CNV-'.mb_substr((string) Str::uuid(), 0, 6), $dias)
            : null;

        $estado ??= $convertido ? 'converted' : 'new';

        return (int) DB::table('client_leads')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_name' => 'Marca de prueba',
            'contact_name' => 'Persona de prueba',
            'email' => 'contacto'.mb_substr((string) Str::uuid(), 0, 8).'@ejemplo.com',
            'country_id' => $paisId ?? $this->paisPE,
            'status' => $estado,
            'client_organization_id' => $clienteId,
            // `ck_clead_revisado`: todo lo que no es `new` exige revisor y fecha.
            'reviewed_at' => $estado === 'new' ? null : $cuando,
            'reviewed_by_user_id' => $estado === 'new'
                ? null
                : (int) $this->usuarioCon('campaign_manager')->id,
            'submitted_at' => $cuando,
            'created_at' => $cuando,
            'updated_at' => $cuando,
        ]);
    }

    private function cliente(string $codigo, int $dias): int
    {
        $cuando = CarbonImmutable::now()->subDays($dias);

        return (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_code' => $codigo,
            'commercial_name' => 'Cliente '.$codigo,
            'country_id' => $this->paisPE,
            'status' => 'active',
            'created_at' => $cuando,
            'updated_at' => $cuando,
        ]);
    }

    private function solicitud(int $dias): int
    {
        $cuando = CarbonImmutable::now()->subDays($dias);

        return (int) DB::table('creator_applications')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Creador de prueba',
            'email' => 'creador'.mb_substr((string) Str::uuid(), 0, 8).'@ejemplo.com',
            'country_id' => $this->paisPE,
            'status' => 'submitted',
            'submitted_at' => $cuando,
            'created_at' => $cuando,
            'updated_at' => $cuando,
        ]);
    }

    /**
     * La tarjeta con ese título, o el fallo dicho con palabras.
     *
     * @return array<string, mixed>
     */
    private function kpi(string $titulo): array
    {
        foreach (Resumen::comercial(FiltrosDeResumen::porDefecto()) as $tarjeta) {
            if ($tarjeta['titulo'] === $titulo) {
                return $tarjeta;
            }
        }

        $this->fail("No hay ninguna tarjeta comercial titulada «{$titulo}».");
    }
}
