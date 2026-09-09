<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\FiltrosDeResumen;
use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Semaforo;
use App\Shared\Auth\Permisos;
use Carbon\CarbonImmutable;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * `D-6`: el semáforo de campañas, y que sus umbrales son configuración.
 *
 * ### La prueba que justifica la iteración entera
 *
 * `test_cambiar_el_umbral_cambia_el_nivel`. La misma campaña, el mismo día, y
 * el color cambia porque cambió una fila de la base. Es la única forma de
 * demostrar que `DEC-190` se cumplió de verdad: un `7` escrito en el código
 * pasaría todas las demás pruebas de este fichero.
 *
 * ### Y la que evita el error clásico de un panel
 *
 * `test_tres_mercados_no_multiplican_los_entregables`. Con `JOIN`s en vez de
 * subconsultas, una campaña con tres mercados enseñaría el triple de
 * entregables y el avance saldría idéntico —porque numerador y denominador se
 * multiplican igual—, así que el fallo sería **invisible en el porcentaje** y
 * sólo se vería en el conteo de al lado. Por eso se comprueban los dos.
 */
final class SemaforoTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $clienteId;

    private int $marcaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
        Queue::fake();

        $paisId = (int) DB::table('countries')->where('iso2', 'PE')->value('id');

        // Por la pantalla y no con un `insert`: el cliente y su marca los crea
        // la aplicacion con sus reglas, y una prueba que se los inventa a mano
        // prueba contra unas filas que la aplicacion nunca escribiria.
        $this->actingAs($this->usuarioCon('campaign_manager'))->post('/backoffice/clientes', [
            'commercial_name' => 'ACME', 'client_code' => 'ACME-01',
            'country_id' => $paisId, 'status' => 'prospect',
        ]);

        $this->clienteId = (int) DB::table('client_organizations')
            ->where('client_code', 'ACME-01')->value('id');
        $this->marcaId = (int) DB::table('client_brands')
            ->where('client_organization_id', $this->clienteId)->value('id');
    }

    // ------------------------------------------------------------- umbrales

    public function test_los_umbrales_llegan_sembrados_con_los_valores_de_partida(): void
    {
        $umbrales = Semaforo::umbrales();

        self::assertTrue($umbrales['configurado'], 'La migración tiene que dejar la fila puesta.');
        self::assertSame(7, $umbrales['dias']);
        self::assertSame(60, $umbrales['avance']);
        self::assertSame(5, $umbrales['convocatoria']);
    }

    public function test_sin_fila_los_umbrales_siguen_completos_y_se_avisa_en_ambar(): void
    {
        DB::table('tracking_thresholds')->delete();

        $umbrales = Semaforo::umbrales();

        self::assertFalse($umbrales['configurado']);
        self::assertSame(Semaforo::PARTIDA['dias'], $umbrales['dias']);
        self::assertSame(Semaforo::PARTIDA['avance'], $umbrales['avance']);

        $avisos = Semaforo::avisos();

        self::assertCount(1, $avisos);
        // Ambar y nunca rojo: el panel funciona igual. `DEC-190` prohibe
        // expresamente que una configuracion que falta se lea como un bloqueo.
        self::assertSame('ambar', $avisos[0]->nivel);
    }

    public function test_un_avance_minimo_en_cero_se_avisa_porque_apaga_media_regla(): void
    {
        Semaforo::guardar([
            'risk_days_before_end' => 7,
            'min_progress_pct' => 0,
            'recruiting_days_before_start' => 5,
        ], null);

        $avisos = Semaforo::avisos();

        self::assertCount(1, $avisos);
        self::assertStringContainsString('0 %', $avisos[0]->texto);
    }

    // --------------------------------------------------------- la regla pura

    public function test_una_campana_que_paso_su_cierre_sale_en_rojo(): void
    {
        $veredicto = Semaforo::nivel(
            $this->campana(['fin' => '2026-03-01', 'avance' => 100.0]),
            ['dias' => 7, 'avance' => 60, 'convocatoria' => 5],
            '2026-03-02',
        );

        self::assertSame(Semaforo::ROJA, $veredicto['nivel']);
        self::assertStringContainsString('01/03/2026', $veredicto['motivo']);
    }

    public function test_el_limite_de_publicacion_vencido_con_entregables_pendientes_sale_en_rojo(): void
    {
        $veredicto = Semaforo::nivel(
            $this->campana([
                'fin' => '2026-04-30', 'limite_publicacion' => '2026-03-01',
                'sin_publicar' => 2, 'avance' => 100.0,
            ]),
            ['dias' => 7, 'avance' => 60, 'convocatoria' => 5],
            '2026-03-02',
        );

        self::assertSame(Semaforo::ROJA, $veredicto['nivel']);
        self::assertStringContainsString('2 entregables', $veredicto['motivo']);
    }

    public function test_el_limite_de_publicacion_vencido_sin_pendientes_no_es_rojo(): void
    {
        // La mitad que se olvida: la fecha pasó, pero todo está publicado. Eso
        // no es un retraso, es una campaña que cumplió.
        $veredicto = Semaforo::nivel(
            $this->campana([
                'fin' => '2026-04-30', 'limite_publicacion' => '2026-03-01',
                'sin_publicar' => 0, 'avance' => 100.0,
            ]),
            ['dias' => 7, 'avance' => 60, 'convocatoria' => 5],
            '2026-03-02',
        );

        self::assertSame(Semaforo::VERDE, $veredicto['nivel']);
    }

    public function test_cerca_del_cierre_y_con_poco_avance_sale_en_ambar(): void
    {
        $veredicto = Semaforo::nivel(
            $this->campana(['fin' => '2026-03-05', 'avance' => 40.0]),
            ['dias' => 7, 'avance' => 60, 'convocatoria' => 5],
            '2026-03-01',
        );

        self::assertSame(Semaforo::AMBAR, $veredicto['nivel']);
        self::assertStringContainsString('Faltan 4 días', $veredicto['motivo']);
    }

    public function test_lejos_del_cierre_el_avance_bajo_todavia_no_es_riesgo(): void
    {
        $veredicto = Semaforo::nivel(
            $this->campana(['fin' => '2026-06-30', 'avance' => 10.0]),
            ['dias' => 7, 'avance' => 60, 'convocatoria' => 5],
            '2026-03-01',
        );

        self::assertSame(Semaforo::VERDE, $veredicto['nivel']);
    }

    public function test_una_convocatoria_incompleta_cerca_del_arranque_sale_en_ambar(): void
    {
        $veredicto = Semaforo::nivel(
            $this->campana([
                'estado' => 'recruiting', 'inicio' => '2026-03-04', 'fin' => '2026-06-30',
                'aceptados' => 2, 'objetivo' => 5, 'avance' => null,
            ]),
            ['dias' => 7, 'avance' => 60, 'convocatoria' => 5],
            '2026-03-01',
        );

        self::assertSame(Semaforo::AMBAR, $veredicto['nivel']);
        self::assertStringContainsString('2 de 5 creadores', $veredicto['motivo']);
    }

    public function test_sin_cupo_declarado_la_convocatoria_no_se_juzga(): void
    {
        // Sin `target_creators` no hay contra que comparar. Inventar un objetivo
        // seria pintar un ambar que nadie puede resolver.
        $veredicto = Semaforo::nivel(
            $this->campana([
                'estado' => 'recruiting', 'inicio' => '2026-03-04', 'fin' => '2026-06-30',
                'aceptados' => 0, 'objetivo' => 0, 'avance' => null,
            ]),
            ['dias' => 7, 'avance' => 60, 'convocatoria' => 5],
            '2026-03-01',
        );

        self::assertSame(Semaforo::VERDE, $veredicto['nivel']);
    }

    public function test_una_campana_cerrada_no_se_vigila(): void
    {
        $veredicto = Semaforo::nivel(
            $this->campana(['estado' => 'completed', 'fin' => '2026-01-01']),
            ['dias' => 7, 'avance' => 60, 'convocatoria' => 5],
            '2026-03-01',
        );

        self::assertSame(Semaforo::VERDE, $veredicto['nivel']);
        self::assertSame('Cerrada.', $veredicto['motivo']);
    }

    public function test_el_avance_no_se_mide_antes_de_arrancar_y_es_cero_despues(): void
    {
        self::assertNull(Semaforo::avance(0, 0, '2026-04-01', '2026-03-01'));
        self::assertSame(0.0, Semaforo::avance(0, 0, '2026-02-01', '2026-03-01'));
        self::assertSame(50.0, Semaforo::avance(4, 2, '2026-02-01', '2026-03-01'));
    }

    // ------------------------------------------------- la prueba que importa

    public function test_cambiar_el_umbral_cambia_el_nivel(): void
    {
        // Una campaña que cierra en 10 días con el 40 % hecho. Con el umbral de
        // partida --7 días-- todavía no se mira: verde. Subiendo el umbral a 15
        // entra en la ventana y el mismo dato, el mismo día, se pone ámbar.
        $campanaId = $this->campanaConEntregables(
            cierraEn: 10, entregables: 5, aprobados: 2,
        );

        $filtros = FiltrosDeResumen::porDefecto();

        $antes = $this->filaDe(Semaforo::campanas($filtros), $campanaId);
        self::assertSame(Semaforo::VERDE, $antes['nivel'],
            'Con 7 días de umbral, una campaña que cierra en 10 no se mira todavía.');

        Semaforo::guardar([
            'risk_days_before_end' => 15,
            'min_progress_pct' => 60,
            'recruiting_days_before_start' => 5,
        ], null);

        $despues = $this->filaDe(Semaforo::campanas($filtros), $campanaId);

        self::assertSame(Semaforo::AMBAR, $despues['nivel'],
            'El umbral es configuración: si esto no cambia, hay un número escrito en el código.');
        self::assertSame(40.0, $despues['avance']);
    }

    // ------------------------------------------------------------ la consulta

    public function test_tres_mercados_no_multiplican_los_entregables(): void
    {
        $campanaId = $this->campanaConEntregables(cierraEn: 20, entregables: 4, aprobados: 1);

        // Dos mercados mas. Con `JOIN` en vez de subconsulta, los 4 entregables
        // pasarian a 12 y los 5 de cupo a 15.
        $puestos = DB::table('campaign_markets')->where('campaign_id', $campanaId)->pluck('country_id');
        $otros = DB::table('countries')->where('is_active', 1)
            ->whereNotIn('id', $puestos)->orderBy('id')->limit(2)->pluck('id');

        // La premisa, comprobada: sin tres paises sembrados esta prueba no
        // probaria lo que dice su nombre y lo diria como un numero raro.
        self::assertCount(2, $otros, 'Hacen falta al menos tres países activos sembrados.');

        foreach ($otros as $paisId) {
            $this->mercadoDe($campanaId, (int) $paisId);
        }

        $fila = $this->filaDe(Semaforo::campanas(FiltrosDeResumen::porDefecto()), $campanaId);

        self::assertSame(4, $fila['entregables']);
        self::assertSame(1, $fila['logrados']);
        self::assertSame(25.0, $fila['avance']);
        self::assertSame(15, $fila['objetivo'], 'Tres mercados de 5 son 15 de cupo, sumados una vez.');
        self::assertSame(1, $fila['aceptados'], 'Un creador en tres mercados sigue siendo uno.');
    }

    public function test_la_tabla_no_se_recorta_por_periodo(): void
    {
        // Terminó hace tres meses y sigue en curso: es el caso que esta tabla
        // existe para enseñar, y el periodo por defecto --30 días-- lo dejaria
        // fuera si este bloque se recortase como los KPIs (`DEC-320`).
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(120)->toDateString(),
            'ends_on' => CarbonImmutable::now()->subDays(90)->toDateString(),
        ]);

        $resultado = Semaforo::campanas(FiltrosDeResumen::porDefecto());
        $fila = $this->filaDe($resultado, $campanaId);

        self::assertSame(Semaforo::ROJA, $fila['nivel']);
        self::assertSame(1, $resultado['conteo'][Semaforo::ROJA]);
    }

    public function test_el_filtro_por_color_recorta_las_filas_pero_no_los_contadores(): void
    {
        $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(60)->toDateString(),
            'ends_on' => CarbonImmutable::now()->subDays(30)->toDateString(),
        ]);
        $this->campanaConEntregables(cierraEn: 60, entregables: 2, aprobados: 2);

        $todas = Semaforo::campanas(FiltrosDeResumen::porDefecto());
        self::assertCount(2, $todas['filas']);

        $rojas = Semaforo::campanas(FiltrosDeResumen::porDefecto(), Semaforo::ROJA);

        self::assertCount(1, $rojas['filas']);
        self::assertSame(2, $rojas['total'], 'El total no lo toca el filtro de color.');
        self::assertSame(1, $rojas['conteo'][Semaforo::VERDE], 'Ni los contadores.');
    }

    public function test_las_campanas_en_borrador_no_entran_en_el_seguimiento(): void
    {
        $this->campanaDe($this->clienteId, $this->marcaId, ['status' => 'draft']);

        self::assertSame(0, Semaforo::campanas(FiltrosDeResumen::porDefecto())['total']);
    }

    // ------------------------------------------------------------- pantallas

    public function test_la_pantalla_de_umbrales_guarda_y_lo_dice(): void
    {
        $usuario = $this->usuarioCon('campaign_manager');

        // La premisa, comprobada y no supuesta: si `campaign_manager` no
        // tuviera `campaign.manage`, esta prueba diria «403» y no «no guarda».
        self::assertTrue($usuario->can('campaign.manage'));

        $this->actingAs($usuario)->get('/backoffice/umbrales')->assertOk();

        $this->actingAs($usuario)->put('/backoffice/umbrales', [
            'risk_days_before_end' => 12,
            'min_progress_pct' => 75,
            'recruiting_days_before_start' => 3,
        ])->assertRedirect();

        self::assertSame(
            ['dias' => 12, 'avance' => 75, 'convocatoria' => 3, 'configurado' => true],
            Semaforo::umbrales(),
        );
    }

    public function test_un_umbral_fuera_de_rango_se_rechaza_con_una_frase(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->put('/backoffice/umbrales', [
                'risk_days_before_end' => 4000,
                'min_progress_pct' => 60,
                'recruiting_days_before_start' => 5,
            ])
            ->assertSessionHasErrors('risk_days_before_end');

        self::assertSame(7, Semaforo::umbrales()['dias'], 'Y no se guardó nada.');
    }

    public function test_quien_no_puede_ver_campanas_no_recibe_la_tabla(): void
    {
        // `finance` NO sirve de sujeto para esta prueba, y averiguarlo costó un
        // fallo: **sí lleva `campaign.view`** --y `campaign.view_margin`--.
        // Tiene sentido: finanzas emite las facturas de las campañas. Se afirma
        // aquí para que quede escrito y para que esta prueba se rompa el día que
        // cambie, en vez de quedar como una suposición en un comentario.
        self::assertTrue(
            $this->usuarioCon('finance')->can('campaign.view'),
            'Si finanzas deja de ver campañas, esta prueba necesita otro sujeto.',
        );

        // El sujeto correcto: alguien del equipo **sin ningún rol**. Llega al
        // panel --la ruta no pide permiso, a propósito: un creador tiene que
        // poder entrar y ver su pantalla de espera-- y no puede ver campañas.
        $sinPermisos = $this->usuarioCon(null);

        self::assertFalse($sinPermisos->can('campaign.view'));

        // `refresh()` y no `$sinPermisos->user_type` a secas. La factoria **no
        // escribe** esa columna: el valor lo pone el DEFAULT de la base, asi
        // que el modelo recien creado la lleva en `null` aunque la fila diga
        // `internal`. Sin refrescar, esto afirma sobre un objeto rancio --y de
        // hecho fallo asi la primera vez--. Es la misma razon por la que
        // `PanelController` escribe `($usuario->user_type ?? 'internal')`.
        self::assertSame('internal', $sinPermisos->refresh()->user_type,
            'Tiene que ser interno: un creador se bifurca antes y no probaría nada.');

        $this->campanaConEntregables(cierraEn: 10, entregables: 2, aprobados: 0);

        $html = $this->actingAs($sinPermisos)->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringNotContainsString('Campañas que requieren seguimiento', (string) $html);
    }

    public function test_finanzas_si_recibe_la_tabla_porque_puede_ver_campanas(): void
    {
        // La otra mitad, sin la cual la de arriba pasaría también con el bloque
        // roto para todo el mundo. Es la lección de `DEC-300`: una prueba que
        // sólo comprueba la ausencia no distingue «escondido a quien no debe
        // verlo» de «no se pinta nunca».
        $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(60)->toDateString(),
            'ends_on' => CarbonImmutable::now()->subDays(30)->toDateString(),
        ]);

        $html = (string) $this->actingAs($this->usuarioCon('finance'))
            ->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringContainsString('Campañas que requieren seguimiento', $html);
    }

    public function test_el_panel_pinta_la_tabla_con_su_motivo(): void
    {
        $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(60)->toDateString(),
            'ends_on' => CarbonImmutable::now()->subDays(30)->toDateString(),
        ]);

        $html = (string) $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringContainsString('Campañas que requieren seguimiento', $html);
        self::assertStringContainsString('Retrasadas', $html);
        self::assertStringContainsString('sigue abierta', $html);
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Una campaña de mentira para `nivel()`, con todos los campos puestos.
     *
     * @param array<string, mixed> $cambios
     * @return array{estado: string, inicio: string, fin: ?string, limite_publicacion: ?string,
     *               avance: ?float, aceptados: int, objetivo: int, sin_publicar: int}
     */
    private function campana(array $cambios = []): array
    {
        $c = array_merge([
            'estado' => 'in_progress',
            'inicio' => '2026-01-01',
            'fin' => '2026-06-30',
            'limite_publicacion' => null,
            'avance' => 100.0,
            'aceptados' => 5,
            'objetivo' => 5,
            'sin_publicar' => 0,
        ], $cambios);

        return [
            'estado' => (string) $c['estado'],
            'inicio' => (string) $c['inicio'],
            'fin' => $c['fin'] === null ? null : (string) $c['fin'],
            'limite_publicacion' => $c['limite_publicacion'] === null
                ? null : (string) $c['limite_publicacion'],
            'avance' => $c['avance'] === null ? null : (float) $c['avance'],
            'aceptados' => (int) $c['aceptados'],
            'objetivo' => (int) $c['objetivo'],
            'sin_publicar' => (int) $c['sin_publicar'],
        ];
    }

    /** Una campaña de verdad, en curso, con un creador y sus entregables. */
    private function campanaConEntregables(int $cierraEn, int $entregables, int $aprobados): int
    {
        $campanaId = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(5)->toDateString(),
            'ends_on' => CarbonImmutable::now()->addDays($cierraEn)->toDateString(),
        ]);

        $this->mercadoDe($campanaId);
        $this->requisitoDe($campanaId);
        $participacionId = $this->participacionDe($campanaId);

        for ($i = 0; $i < $entregables; $i++) {
            $this->entregableDe($participacionId, [
                'status' => $i < $aprobados ? 'approved' : 'pending',
            ]);
        }

        return $campanaId;
    }

    /**
     * La fila de una campaña dentro del resultado, o el fallo dicho con palabras.
     *
     * @param array{filas: list<array<string, mixed>>, ...} $resultado
     * @return array<string, mixed>
     */
    private function filaDe(array $resultado, int $campanaId): array
    {
        foreach ($resultado['filas'] as $fila) {
            if ($fila['id'] === $campanaId) {
                return $fila;
            }
        }

        $this->fail("La campaña {$campanaId} no salió en el seguimiento.");
    }
}
