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
use Illuminate\Support\Facades\Route;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * `D-2`: el armazón del centro de control, y un indicador de punta a punta.
 *
 * ### Por qué las cifras van fabricadas y no leídas
 *
 * Porque la base de producción está vacía. Con cero campañas, **cualquier**
 * fórmula devuelve cero y una equivocada se ve igual que una correcta. Aquí cada
 * número esperado se conoce antes de preguntarlo, que es la única forma de que
 * la prueba signifique algo. Es la lección de `DEC-302` y `DEC-304`, las dos del
 * mismo día.
 */
final class ResumenTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $clienteId;

    private int $marcaId;

    private int $paisPE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
        Queue::fake();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');

        $this->actingAs($this->usuarioCon('campaign_manager'))->post('/backoffice/clientes', [
            'commercial_name' => 'ACME', 'client_code' => 'ACME-01',
            'country_id' => $this->paisPE, 'status' => 'prospect',
        ]);

        $this->clienteId = (int) DB::table('client_organizations')->where('client_code', 'ACME-01')->value('id');
        $this->marcaId = (int) DB::table('client_brands')
            ->where('client_organization_id', $this->clienteId)->value('id');
    }

    // ------------------------------------------------------------ el periodo

    public function test_el_periodo_por_defecto_son_treinta_dias_hasta_hoy(): void
    {
        $filtros = FiltrosDeResumen::porDefecto();

        self::assertSame(FiltrosDeResumen::TREINTA, $filtros->periodo);
        self::assertSame(29, $this->diasEnteros($filtros->desde, $filtros->hasta));
        self::assertTrue($filtros->hasta->isToday());
    }

    /**
     * El periodo anterior mide LO MISMO que el elegido.
     *
     * Comparar siete días contra un mes daría una variación que no significa
     * nada, y nadie lo notaría: saldría un porcentaje con toda su pinta de dato.
     */
    public function test_el_periodo_anterior_dura_lo_mismo_y_termina_la_vispera(): void
    {
        $filtros = FiltrosDeResumen::desdePeticion(Request::create('/', 'GET', ['periodo' => '7d']));
        $anterior = $filtros->anterior();

        self::assertSame(6, $this->diasEnteros($anterior->desde, $anterior->hasta));
        self::assertSame(
            $filtros->desde->subDay()->toDateString(),
            $anterior->hasta->toDateString(),
        );
    }

    /** Un rango al revés se endereza en vez de devolver cero sin explicar por qué. */
    public function test_un_rango_invertido_se_endereza(): void
    {
        $filtros = FiltrosDeResumen::desdePeticion(Request::create('/', 'GET', [
            'periodo' => 'rango', 'desde' => '2026-08-31', 'hasta' => '2026-08-01',
        ]));

        self::assertSame('2026-08-01', $filtros->desde->toDateString());
        self::assertSame('2026-08-31', $filtros->hasta->toDateString());
    }

    /** Un enlace viejo con un periodo que ya no existe no deja a nadie ante un 500. */
    public function test_un_periodo_desconocido_vuelve_al_de_siempre(): void
    {
        $filtros = FiltrosDeResumen::desdePeticion(
            Request::create('/', 'GET', ['periodo' => 'decada-prodigiosa']),
        );

        self::assertSame(FiltrosDeResumen::TREINTA, $filtros->periodo);
    }

    // -------------------------------------------------------------- el número

    public function test_cuenta_solo_las_campanas_en_estado_activo(): void
    {
        $dentro = ['approved', 'recruiting', 'in_progress', 'in_review'];
        $fuera = ['draft', 'pending_approval', 'completed', 'cancelled'];

        foreach ([...$dentro, ...$fuera] as $estado) {
            $this->campanaDe($this->clienteId, $this->marcaId, [
                'status' => $estado,
                'starts_on' => CarbonImmutable::now()->subDays(5)->toDateString(),
                'ends_on' => CarbonImmutable::now()->addDays(5)->toDateString(),
            ]);
        }

        $kpi = Resumen::campanasActivas(FiltrosDeResumen::porDefecto());

        self::assertSame(count($dentro), $kpi['valor'], 'cuatro estados activos, ni uno mas');
    }

    /**
     * Una campaña larga aparece en cualquier periodo que su ventana toque.
     *
     * Con «empieza dentro del periodo» —que es como sale si nadie lo piensa— una
     * campaña de tres meses no saldría en ninguna de sus propias semanas.
     */
    public function test_una_campana_larga_sale_en_un_periodo_que_no_la_contiene(): void
    {
        $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subMonths(3)->toDateString(),
            'ends_on' => CarbonImmutable::now()->addMonths(3)->toDateString(),
        ]);

        $hoy = FiltrosDeResumen::desdePeticion(Request::create('/', 'GET', ['periodo' => 'hoy']));

        self::assertSame(1, Resumen::campanasActivas($hoy)['valor']);
    }

    public function test_una_campana_terminada_antes_del_periodo_no_cuenta(): void
    {
        $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(90)->toDateString(),
            'ends_on' => CarbonImmutable::now()->subDays(60)->toDateString(),
        ]);

        self::assertSame(0, Resumen::campanasActivas(FiltrosDeResumen::porDefecto())['valor']);
    }

    /**
     * **La que más importa de esta iteración.**
     *
     * Una campaña con tres mercados es UNA campaña. El filtro por país va con
     * `EXISTS` y no con `JOIN` exactamente por esto: con `JOIN` el mismo dato
     * saldría tres veces y el panel enseñaría el triple sin que nada fallara.
     */
    public function test_una_campana_con_varios_mercados_cuenta_una_sola_vez(): void
    {
        $campana = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(5)->toDateString(),
            'ends_on' => CarbonImmutable::now()->addDays(5)->toDateString(),
        ]);

        $otros = DB::table('countries')->orderBy('id')->limit(3)->pluck('id');
        foreach ($otros as $paisId) {
            $this->mercadoDe($campana, (int) $paisId);
        }

        self::assertSame(1, Resumen::campanasActivas(FiltrosDeResumen::porDefecto())['valor']);

        $conPais = FiltrosDeResumen::desdePeticion(
            Request::create('/', 'GET', ['pais' => (string) $otros->first()]),
        );

        self::assertSame(1, Resumen::campanasActivas($conPais)['valor']);
    }

    public function test_el_filtro_de_pais_deja_fuera_lo_que_no_es_de_ese_pais(): void
    {
        $campana = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(5)->toDateString(),
            'ends_on' => CarbonImmutable::now()->addDays(5)->toDateString(),
        ]);
        $this->mercadoDe($campana, $this->paisPE);

        $otroPais = (int) DB::table('countries')->where('id', '<>', $this->paisPE)->value('id');
        $filtros = FiltrosDeResumen::desdePeticion(
            Request::create('/', 'GET', ['pais' => (string) $otroPais]),
        );

        self::assertSame(0, Resumen::campanasActivas($filtros)['valor']);
    }

    /** Sin periodo anterior con el que comparar, la variación es null y no un 100 %. */
    public function test_sin_nada_antes_no_se_inventa_un_porcentaje(): void
    {
        $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(2)->toDateString(),
            'ends_on' => CarbonImmutable::now()->addDays(2)->toDateString(),
        ]);

        $kpi = Resumen::campanasActivas(FiltrosDeResumen::porDefecto());

        self::assertSame(1, $kpi['valor']);
        self::assertSame(0, $kpi['anterior']);
        self::assertNull($kpi['variacion']);
    }

    // ------------------------------------------------------------ la pantalla

    public function test_la_pantalla_carga_con_filtros_y_sin_ellos(): void
    {
        $admin = $this->usuarioCon('admin');

        $this->actingAs($admin)->get(route('panel'))->assertOk()->assertSee('Actualizar');
        $this->actingAs($admin)
            ->get(route('panel', ['periodo' => '7d', 'pais' => $this->paisPE]))
            ->assertOk()
            ->assertSee('Campañas activas');
    }

    /**
     * El filtro que se elige en la pantalla cambia el número de la pantalla.
     *
     * Sin esta prueba, un panel puede tener los desplegables puestos, el
     * formulario enviando y la consulta escrita, y no estar conectado. Se vería
     * perfecto.
     */
    public function test_el_filtro_elegido_cambia_lo_que_ensena_la_pantalla(): void
    {
        $campana = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(5)->toDateString(),
            'ends_on' => CarbonImmutable::now()->addDays(5)->toDateString(),
        ]);
        $this->mercadoDe($campana, $this->paisPE);

        $otroPais = (int) DB::table('countries')->where('id', '<>', $this->paisPE)->value('id');
        $admin = $this->usuarioCon('admin');

        $conPeru = $this->actingAs($admin)->get(route('panel', ['pais' => $this->paisPE]));
        $conOtro = $this->actingAs($admin)->get(route('panel', ['pais' => $otroPais]));

        $conPeru->assertOk()->assertSee('Limpiar');
        self::assertNotSame(
            $this->numeroDeCampanasActivas($conPeru->getContent()),
            $this->numeroDeCampanasActivas($conOtro->getContent()),
            'el numero de la pantalla tiene que cambiar al cambiar el pais',
        );
    }

    // ------------------------------------------------ D-4: los cinco de operación

    public function test_operacion_devuelve_cinco_tarjetas_bien_formadas(): void
    {
        $tarjetas = Resumen::operacion(FiltrosDeResumen::porDefecto());

        self::assertCount(5, $tarjetas);

        foreach ($tarjetas as $tarjeta) {
            foreach (['titulo', 'valor', 'anterior', 'variacion', 'sentido', 'tooltip', 'ruta'] as $clave) {
                self::assertArrayHasKey($clave, $tarjeta, "falta «{$clave}» en «{$tarjeta['titulo']}»");
            }
            self::assertNotSame('', $tarjeta['tooltip'], 'un KPI sin definición lo interpreta cada uno a su manera');
            self::assertNotNull(
                Route::getRoutes()->getByName($tarjeta['ruta']),
                "«{$tarjeta['titulo']}» apunta a la ruta inexistente «{$tarjeta['ruta']}»",
            );
        }
    }

    /** «Que arrancan» mira `starts_on`, y los borradores no arrancan nada. */
    public function test_las_que_arrancan_se_cuentan_por_su_fecha_de_inicio(): void
    {
        $dentro = CarbonImmutable::now()->subDays(3)->toDateString();
        $fuera = CarbonImmutable::now()->subDays(90)->toDateString();

        // `ck_camp_dates` no deja que una campaña acabe antes de empezar, y el
        // ayudante trae un `ends_on` fijo de julio: hay que mover los dos.
        $finDentro = CarbonImmutable::now()->addDays(10)->toDateString();
        $finFuera = CarbonImmutable::now()->subDays(60)->toDateString();

        $this->campanaDe($this->clienteId, $this->marcaId,
            ['status' => 'in_progress', 'starts_on' => $dentro, 'ends_on' => $finDentro]);
        $this->campanaDe($this->clienteId, $this->marcaId,
            ['status' => 'in_progress', 'starts_on' => $fuera, 'ends_on' => $finFuera]);
        $this->campanaDe($this->clienteId, $this->marcaId,
            ['status' => 'draft', 'starts_on' => $dentro, 'ends_on' => $finDentro]);
        $this->campanaDe($this->clienteId, $this->marcaId,
            ['status' => 'cancelled', 'starts_on' => $dentro, 'ends_on' => $finDentro]);

        self::assertSame(1, $this->tarjeta('Campañas que arrancan')['valor']);
    }

    /**
     * «Cerradas» mira `closed_at` y NO `ends_on`.
     *
     * Son cosas distintas: una campaña puede terminar su ventana de fechas y
     * seguir abierta semanas, con entregables por verificar y pagos sin hacer.
     * Contarla como cerrada el día que vence la fecha adelanta el cierre en los
     * informes y nadie lo nota, porque el número sube igual.
     */
    public function test_cerradas_mira_la_fecha_de_cierre_y_no_la_de_fin(): void
    {
        $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(20)->toDateString(),
            'ends_on' => CarbonImmutable::now()->subDays(3)->toDateString(),
        ]);

        self::assertSame(0, $this->tarjeta('Campañas cerradas')['valor'], 'venció, pero nadie la cerró');

        $cerrada = $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'completed',
            'starts_on' => CarbonImmutable::now()->subDays(20)->toDateString(),
            'ends_on' => CarbonImmutable::now()->subDays(3)->toDateString(),
        ]);
        DB::table('campaigns')->where('id', $cerrada)
            ->update(['closed_at' => CarbonImmutable::now()->subDay()->toDateTimeString()]);

        self::assertSame(1, $this->tarjeta('Campañas cerradas')['valor']);
    }

    /** El recorte que se aplica a un KPI se aplica a todos. */
    public function test_el_filtro_de_cliente_recorta_todos_los_indicadores(): void
    {
        $this->campanaDe($this->clienteId, $this->marcaId, [
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(3)->toDateString(),
            'ends_on' => CarbonImmutable::now()->addDays(3)->toDateString(),
        ]);

        $otroCliente = (int) DB::table('client_organizations')
            ->where('id', '<>', $this->clienteId)->value('id') ?: 999999;

        $filtros = FiltrosDeResumen::desdePeticion(
            Request::create('/', 'GET', ['cliente' => (string) $otroCliente]),
        );

        foreach (Resumen::operacion($filtros) as $tarjeta) {
            self::assertSame(0, $tarjeta['valor'], "«{$tarjeta['titulo']}» ignoró el filtro de cliente");
        }
    }

    /**
     * Con la base vacía ninguno revienta.
     *
     * Suena trivial y no lo es: «creadores participando» y «entregables
     * entregados» cruzan tres tablas, y una instalación recién sembrada no tiene
     * ni una fila en ninguna. Es el estado exacto de producción hoy.
     *
     * **Lo que esta prueba NO hace** es comprobar que cuentan bien cuando hay
     * datos: fabricar una participación y un entregable exige creador activo,
     * mercado, requisito y los disparadores de `campaign_creators`, y hoy no hay
     * ayudante para eso. Queda `T-104`; hasta entonces esos dos indicadores
     * están **verificados a medias** y así está escrito.
     */
    public function test_con_la_base_vacia_ningun_indicador_revienta(): void
    {
        foreach (Resumen::operacion(FiltrosDeResumen::porDefecto()) as $tarjeta) {
            self::assertSame(0, $tarjeta['valor']);
            self::assertNull($tarjeta['variacion']);
        }
    }

    /** @return array<string, mixed> */
    private function tarjeta(string $titulo): array
    {
        foreach (Resumen::operacion(FiltrosDeResumen::porDefecto()) as $tarjeta) {
            if ($tarjeta['titulo'] === $titulo) {
                return $tarjeta;
            }
        }

        self::fail("No hay ninguna tarjeta que se llame «{$titulo}»");
    }

    // ---------------------------------- D-5: estados, embudo, y el hueco de T-104

    /**
     * Los ocho estados salen siempre, y en el orden en que ocurren.
     *
     * Un estado que desaparece al vaciarse obliga a recordar cuáles existen para
     * notar que falta uno. «Ninguna en revisión» es una respuesta.
     */
    public function test_los_ocho_estados_salen_aunque_esten_a_cero(): void
    {
        $filas = Resumen::porEstado(FiltrosDeResumen::porDefecto());

        self::assertCount(8, $filas);
        self::assertSame(
            ['draft', 'pending_approval', 'approved', 'recruiting',
                'in_progress', 'in_review', 'completed', 'cancelled'],
            array_column($filas, 'clave'),
        );

        foreach ($filas as $fila) {
            self::assertSame(0, $fila['cantidad']);
            self::assertSame(0.0, $fila['importe']);
        }
    }

    public function test_cada_estado_lleva_su_cuenta_y_su_importe(): void
    {
        $this->campanaEnCurso(['status' => 'in_progress', 'revenue_amount' => 1500]);
        $this->campanaEnCurso(['status' => 'in_progress', 'revenue_amount' => 2500]);
        $this->campanaEnCurso(['status' => 'in_review', 'revenue_amount' => 700]);

        $porClave = collect(Resumen::porEstado(FiltrosDeResumen::porDefecto()))
            ->keyBy('clave');

        self::assertSame(2, $porClave['in_progress']['cantidad']);
        self::assertSame(4000.0, $porClave['in_progress']['importe']);
        self::assertSame(1, $porClave['in_review']['cantidad']);
        self::assertSame(0, $porClave['draft']['cantidad']);
    }

    /**
     * El embudo, con una campaña completa de verdad.
     *
     * Cada peldaño cuenta **su** unidad: campañas, participaciones, entregables,
     * publicaciones. Por eso no hay porcentajes entre ellos (`DEC-317`), y esta
     * prueba comprueba justamente que las unidades no se mezclan: una campaña,
     * una participación y un entregable dan 1 en cuatro peldaños distintos sin
     * que ninguno arrastre al siguiente.
     */
    public function test_el_embudo_cuenta_cada_peldano_en_su_unidad(): void
    {
        $campana = $this->campanaEnCurso(['status' => 'in_progress']);
        $participacion = $this->participacionDe($campana);
        $this->entregableDe($participacion, ['status' => 'approved']);

        $pasos = collect(Resumen::embudo(FiltrosDeResumen::porDefecto()))->keyBy('etiqueta');

        self::assertSame(1, $pasos['Campañas en marcha']['cantidad']);
        self::assertSame(1, $pasos['Con convocatoria abierta o pasada']['cantidad']);
        self::assertSame(1, $pasos['Creadores seleccionados']['cantidad']);
        self::assertSame(1, $pasos['Contenidos aprobados']['cantidad']);
        self::assertSame(0, $pasos['Publicaciones verificadas']['cantidad']);
        self::assertSame(0, $pasos['Campañas cerradas']['cantidad']);

        self::assertSame('participaciones', $pasos['Creadores seleccionados']['unidad']);
        self::assertSame('entregables', $pasos['Contenidos aprobados']['unidad']);
    }

    /**
     * **Cierra el hueco de `T-104`.**
     *
     * `D-4` dejó este indicador verificado a medias porque no había con qué
     * fabricar una participación. El mismo creador en dos campañas es **un**
     * creador: sin el `DISTINCT`, el panel enseñaría más red de la que hay y
     * crecería justo cuando la operación se concentra en pocos creadores.
     */
    public function test_el_mismo_creador_en_dos_campanas_cuenta_una_vez(): void
    {
        $creador = $this->creadorActivo();
        $this->participacionDe($this->campanaEnCurso(), $creador);
        $this->participacionDe($this->campanaEnCurso(), $creador);

        self::assertSame(1, $this->tarjeta('Creadores participando')['valor']);
    }

    /** El otro hueco de `T-104`: se cuenta por la fecha de entrega, no por el estado. */
    public function test_los_entregables_se_cuentan_por_cuando_se_entregaron(): void
    {
        $participacion = $this->participacionDe($this->campanaEnCurso());

        $this->entregableDe($participacion, ['status' => 'submitted']);
        $this->entregableDe($participacion, [
            'status' => 'submitted',
            'created_at' => CarbonImmutable::now()->subDays(120)->toDateTimeString(),
        ]);

        self::assertSame(
            1,
            $this->tarjeta('Entregables entregados')['valor'],
            'el de hace cuatro meses cae fuera de los treinta días',
        );
    }

    /** Una campaña en curso que toca el periodo, sin repetir seis líneas cada vez. */
    private function campanaEnCurso(array $cambios = []): int
    {
        return $this->campanaDe($this->clienteId, $this->marcaId, array_merge([
            'status' => 'in_progress',
            'starts_on' => CarbonImmutable::now()->subDays(5)->toDateString(),
            'ends_on' => CarbonImmutable::now()->addDays(20)->toDateString(),
        ], $cambios));
    }

    /**
     * Días enteros entre dos límites de periodo.
     *
     * `diffInDays` de Carbon 3 devuelve **coma flotante**, y entre el arranque
     * de un día y el final de otro sale 29,99999… — comparar eso con `29` con
     * `assertSame` falla por el tipo antes que por el número. Se compara de
     * comienzo de día a comienzo de día, que es la pregunta real.
     */
    private function diasEnteros(CarbonImmutable $desde, CarbonImmutable $hasta): int
    {
        return (int) $desde->startOfDay()->diffInDays($hasta->startOfDay());
    }

    /** El valor pintado bajo «Campañas activas», leído del HTML de verdad. */
    private function numeroDeCampanasActivas(string $html): string
    {
        preg_match('/Campañas activas.*?tabular-nums">([\d,]+)</su', $html, $coincidencia);

        return $coincidencia[1] ?? 'no encontrado';
    }
}
