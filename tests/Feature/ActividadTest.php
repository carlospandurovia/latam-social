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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * `D-10`: la actividad reciente.
 *
 * ### La que protege un dato
 *
 * `test_el_detalle_del_cambio_no_sale_en_la_portada`. `audit_logs.changes` lleva
 * el antes y el después de cada campo, y ahí viaja un correo, un domicilio o un
 * importe. La portada dice **qué, quién y cuándo**; el detalle está a un clic en
 * la bitácora, que es donde ese contenido ya tiene pantalla y permiso.
 */
final class ActividadTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
        Queue::fake();
    }

    public function test_sale_lo_mas_reciente_primero_y_solo_del_periodo(): void
    {
        $this->apunte('vieja.accion', dias: 90);
        $this->apunte('media.accion', dias: 10);
        $this->apunte('nueva.accion', dias: 1);

        $lineas = Resumen::actividad(FiltrosDeResumen::porDefecto());

        self::assertCount(2, $lineas, 'La de hace 90 días queda fuera del periodo.');
        self::assertSame('nueva.accion', $lineas[0]['accion']);
        self::assertSame('media.accion', $lineas[1]['accion']);
    }

    public function test_no_se_pasa_del_tope_aunque_haya_mas(): void
    {
        for ($i = 0; $i < Resumen::ACTIVIDAD + 5; $i++) {
            $this->apunte('accion.'.$i, dias: 1);
        }

        self::assertCount(
            Resumen::ACTIVIDAD,
            Resumen::actividad(FiltrosDeResumen::porDefecto()),
            'Es una portada, no la bitácora entera.',
        );
    }

    public function test_lo_que_hizo_el_sistema_se_dice_con_palabras(): void
    {
        // `actor_label` vacio significa que no lo hizo una persona. Dejarlo en
        // blanco pondria una linea sin autor, que se lee como un fallo.
        $this->apunte('cron.corrio', dias: 1, quien: null);

        self::assertSame('el sistema', Resumen::actividad(FiltrosDeResumen::porDefecto())[0]['quien']);
    }

    public function test_el_detalle_del_cambio_no_sale_en_la_portada(): void
    {
        $this->apunte('cliente.actualizado', dias: 1, cambios: [
            'billing_email' => ['antes' => 'viejo@cliente.com', 'despues' => 'nuevo@cliente.com'],
        ]);

        $linea = Resumen::actividad(FiltrosDeResumen::porDefecto())[0];

        self::assertArrayNotHasKey('cambios', $linea);
        self::assertStringNotContainsString('cliente.com', implode(' ', $linea));

        // Y tampoco por el HTML: el bloque se pinta entero y el correo no está.
        $html = (string) $this->actingAs($this->usuarioCon('admin'))
            ->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringContainsString('Lo último que ha pasado', $html);
        self::assertStringNotContainsString('nuevo@cliente.com', $html);
    }

    public function test_quien_no_puede_ver_la_bitacora_no_recibe_el_bloque(): void
    {
        $gestor = $this->usuarioCon('campaign_manager');

        // La premisa, comprobada: `audit.view` es de administracion.
        self::assertFalse($gestor->can('audit.view'));

        $this->apunte('algo.paso', dias: 1);

        $consultas = 0;
        DB::listen(static function ($consulta) use (&$consultas): void {
            if (str_contains($consulta->sql, 'audit_logs')) {
                $consultas++;
            }
        });

        $html = (string) $this->actingAs($gestor)->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringNotContainsString('Lo último que ha pasado', $html);
        self::assertSame(0, $consultas, 'Ni se pinta ni se consulta.');
    }

    // ---------------------------------------------------------------- apoyo

    /** @param array<string, mixed> $cambios */
    private function apunte(string $accion, int $dias, ?string $quien = 'Ana Torres', array $cambios = []): void
    {
        $cuando = CarbonImmutable::now()->subDays($dias);

        DB::table('audit_logs')->insert([
            'actor_label' => $quien,
            'action' => $accion,
            'entity_type' => 'client_organization',
            'entity_id' => 1,
            'changes' => $cambios === [] ? null : json_encode($cambios, JSON_UNESCAPED_UNICODE),
            'occurred_at' => $cuando,
        ]);
    }
}
