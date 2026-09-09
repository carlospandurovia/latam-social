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
 * `D-14`: publicaciones y permanencia en el panel.
 *
 * ### Lo que este bloque NO es
 *
 * No es la «sección 7». El rendimiento —alcance, impresiones, interacciones—
 * sigue sin fuente y sigue fuera del panel. Estas cifras no dicen cómo funcionó
 * el contenido: dicen **si el trato se cumplió**, que es otra pregunta.
 *
 * ### La que de verdad importa
 *
 * `test_sin_ventanas_cerradas_no_hay_porcentaje`. Un cumplimiento del 100 %
 * calculado sobre cero ventanas cerradas se lee como «todo perfecto» cuando lo
 * que pasa es que no ha terminado nada. Es la misma familia que «no se inventa
 * un cero» de `D-4` y que el porcentaje vetado de `D-13`.
 */
final class PublicacionesDelPanelTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $clienteId;

    private int $campanaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
        Queue::fake();

        $paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');

        $this->clienteId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'commercial_name' => 'ACME', 'client_code' => 'ACME-01',
            'country_id' => $paisPE, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $marcaId = (int) DB::table('client_brands')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_organization_id' => $this->clienteId,
            'name' => 'Marca ACME', 'slug' => 'marca-acme', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->campanaId = $this->campanaDe($this->clienteId, $marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(20)->toDateString(),
            'ends_on' => CarbonImmutable::now()->addDays(20)->toDateString(),
        ]);
        $this->mercadoDe($this->campanaId, $paisPE);
    }

    // ------------------------------------------------------------ las tres

    public function test_cada_cifra_cuenta_por_su_propia_fecha(): void
    {
        // Publicada hace 3 días y verificada hace 3: cuenta en las dos.
        $this->publicacion(['published_at' => CarbonImmutable::now()->subDays(3)]);

        // Publicada hace 3 días y CAÍDA hoy: cuenta en registradas y en caídas.
        $this->publicacion([
            'status' => 'removed',
            'published_at' => CarbonImmutable::now()->subDays(3),
            'removed_at' => CarbonImmutable::now(),
        ]);

        $tarjetas = $this->tarjetas();

        self::assertSame(2, $tarjetas['Publicaciones registradas']);
        self::assertSame(2, $tarjetas['Publicaciones verificadas']);
        self::assertSame(1, $tarjetas['Publicaciones caídas']);
    }

    public function test_una_publicacion_de_fuera_del_periodo_no_cuenta(): void
    {
        $this->publicacion(['published_at' => CarbonImmutable::now()->subDays(120)]);

        self::assertSame(0, $this->tarjetas()['Publicaciones registradas']);
    }

    // -------------------------------------------------------- la permanencia

    public function test_el_cumplimiento_mira_las_ventanas_cerradas_en_el_periodo(): void
    {
        $this->publicacion(['status' => 'fulfilled', 'fulfilled_at' => CarbonImmutable::now()]);
        $this->publicacion(['status' => 'fulfilled', 'fulfilled_at' => CarbonImmutable::now()]);
        $this->publicacion([
            'status' => 'removed',
            'removed_at' => CarbonImmutable::now(),
        ]);

        $permanencia = Resumen::publicaciones(FiltrosDeResumen::porDefecto())['permanencia'];

        // 2 de 3 = 66,7 %. El número se conoce de antemano.
        self::assertSame(2, $permanencia['cumplidas']);
        self::assertSame(1, $permanencia['caidas']);
        self::assertSame(66.7, $permanencia['porcentaje']);
    }

    public function test_sin_ventanas_cerradas_no_hay_porcentaje(): void
    {
        // Una publicación viva, con su ventana todavía abierta.
        $this->publicacion();

        $permanencia = Resumen::publicaciones(FiltrosDeResumen::porDefecto())['permanencia'];

        // Un 100 % de cero se leería como «todo bien» donde lo que pasa es que
        // no ha terminado nada.
        self::assertNull($permanencia['porcentaje']);
        self::assertSame(0, $permanencia['cumplidas']);
        self::assertSame(0, $permanencia['caidas']);
    }

    public function test_lo_que_se_vigila_hoy_no_se_recorta_por_periodo(): void
    {
        // Publicada hace cuatro meses —fuera del periodo por omisión— y con la
        // ventana todavía abierta: se está vigilando HOY, que es la pregunta.
        $this->publicacion([
            'published_at' => CarbonImmutable::now()->subDays(120),
            'permanence_until' => CarbonImmutable::now()->addDays(10)->toDateString(),
        ]);

        $publicaciones = Resumen::publicaciones(FiltrosDeResumen::porDefecto());

        self::assertSame(0, $publicaciones['tarjetas'][0]['valor'], 'No se publicó en el periodo.');
        self::assertSame(1, $publicaciones['permanencia']['vigilando']);
    }

    public function test_una_ventana_ya_vencida_no_sigue_en_vigilancia(): void
    {
        $this->publicacion([
            'published_at' => CarbonImmutable::now()->subDays(60),
            'permanence_until' => CarbonImmutable::now()->subDays(5)->toDateString(),
        ]);

        self::assertSame(0, Resumen::publicaciones(FiltrosDeResumen::porDefecto())['permanencia']['vigilando']);
    }

    // ----------------------------------------------------------- el recorte

    public function test_el_filtro_de_cliente_recorta_las_publicaciones(): void
    {
        $this->publicacion();

        $deOtro = FiltrosDeResumen::desdePeticion(
            Request::create('/', 'GET', ['cliente' => (string) ($this->clienteId + 999)]),
        );

        self::assertSame($this->clienteId + 999, $deOtro->clienteId, 'El filtro tiene que haber calado.');
        self::assertSame(0, Resumen::publicaciones($deOtro)['tarjetas'][0]['valor']);
    }

    // ---------------------------------------------------------- el permiso

    public function test_quien_no_puede_ver_campanas_no_recibe_el_bloque(): void
    {
        $sinPermisos = $this->usuarioCon(null);

        self::assertFalse($sinPermisos->can('campaign.view'));

        $html = (string) $this->actingAs($sinPermisos)->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringNotContainsString('Permanencia cumplida', $html);
    }

    /**
     * Quien hace este trabajo lo ve.
     *
     * `content_reviewer` **tiene** `campaign.view` —medido en el sembrador, no
     * supuesto—, y es quien verifica las publicaciones. Un bloque sobre su
     * propio trabajo que él no puede ver sería el peor reparto posible.
     */
    public function test_el_revisor_de_contenido_si_lo_recibe(): void
    {
        $this->publicacion();

        $revisor = $this->usuarioCon('content_reviewer');

        self::assertTrue($revisor->can('campaign.view'));

        $html = (string) $this->actingAs($revisor)->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringContainsString('Permanencia cumplida', $html);
        self::assertStringContainsString('en vigilancia hoy', $html);
    }

    // -------------------------------------------------------------- apoyo

    /** @return array<string, int> */
    private function tarjetas(): array
    {
        $valores = [];

        foreach (Resumen::publicaciones(FiltrosDeResumen::porDefecto())['tarjetas'] as $tarjeta) {
            $valores[$tarjeta['titulo']] = $tarjeta['valor'];
        }

        return $valores;
    }

    /** @param array<string, mixed> $cambios */
    private function publicacion(array $cambios = []): int
    {
        $participacionId = $this->participacionDe($this->campanaId);
        // ENTREGADO, no aprobado: `publicacionDe()` fabrica la versión y la
        // aprueba, y una versión no se puede crear sobre un entregable que ya
        // está aprobado (`tg_dv_entregable_abierto`).
        $entregableId = $this->entregableDe($participacionId, ['status' => 'submitted']);

        return $this->publicacionDe($entregableId, $cambios);
    }
}
