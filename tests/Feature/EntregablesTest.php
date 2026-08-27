<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Campaign\Services\Invitaciones;
use App\Modules\Campaign\Services\ListaCorta;
use App\Modules\Content\Services\Entregables;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Database\Seeders\PlantillasDeCorreoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Los entregables (iteración 8.1).
 *
 * ### Las tres decisiones que estas pruebas fijan
 *
 * | Decisión (2026-08-26) | Qué se afirma |
 * |---|---|
 * | Se generan solos al aceptar | y del brief **efectivo**, que resuelve `N-03` |
 * | Enlace, e imagen opcional | y el enlace es `https://`, lo exige la base |
 * | Faltan hashtags → no se envía | y se le dice **cuáles** |
 *
 * ### Y la que se cobró la arquitectura
 *
 * `Campaign` no puede conocer `Content`, así que el panel de `7.7` no cuenta
 * entregables: los enlaza. Y la generación va por evento, igual que el correo.
 */
final class EntregablesTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $campanaId;

    private string $uuid;

    private int $paisPE;

    private int $mercadoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        $this->seed(PlantillasDeCorreoSeeder::class);
        Permisos::olvidar();

        $this->paisPE = (int) DB::table('countries')->where('iso2', 'PE')->value('id');

        $clienteId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'commercial_name' => 'ACME', 'client_code' => 'ACME-01',
            'country_id' => $this->paisPE, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $marcaId = (int) DB::table('client_brands')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_organization_id' => $clienteId,
            'name' => 'Marca ACME', 'slug' => 'marca-acme', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->campanaId = $this->campanaDe($clienteId, $marcaId, [
            'status' => 'recruiting', 'revenue_amount' => 15000, 'creator_budget_amount' => 5000,
            'starts_on' => now()->addDays(10)->toDateString(),
            'ends_on' => now()->addMonths(2)->toDateString(),
        ]);
        $this->uuid = (string) DB::table('campaigns')->where('id', $this->campanaId)->value('uuid');
        $this->mercadoId = $this->mercadoDe($this->campanaId, $this->paisPE, ['target_creators' => null]);
    }

    // ------------------------------------------------ se generan al aceptar

    /** **La afirmación que descubre si las demás mienten.** El caso normal funciona. */
    public function test_aceptar_crea_los_entregables_del_brief(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 3, 'deadline_offset_days' => 7]);

        $id = $this->aceptado(500.0);

        $entregables = Entregables::de($id);

        $this->assertCount(3, $entregables);
        $this->assertSame([1, 2, 3], $entregables->pluck('sequence_number')->map(fn ($n): int => (int) $n)->all());
        $this->assertSame('pending', $entregables->first()->status);
    }

    /**
     * El plazo son **4** días aquí y **12** en la prueba siguiente, a propósito.
     *
     * Las dos usaban 7. Con 7 en las dos, sustituir `deadline_offset_days` por
     * un 7 a pelo dejaba las dos verdes, y la mutación sobrevivía: la prueba
     * afirmaba una fecha correcta sin comprobar de dónde salía el plazo. Dos
     * números distintos, y ninguna constante los satisface a la vez.
     */
    public function test_la_fecha_limite_sale_del_arranque_mas_el_plazo(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1, 'deadline_offset_days' => 4]);

        $id = $this->aceptado(500.0);

        $this->assertSame(
            now()->addDays(14)->toDateString(),
            (string) Entregables::de($id)->first()->due_on,
            'arranque (+10) mas el plazo del requisito (+4)',
        );
    }

    /**
     * Si la campaña ya arrancó, el plazo cuenta desde hoy.
     *
     * Un plazo calculado hacia atrás nace vencido, y `ck_del_due_futuro` lo
     * rechaza — con razón: eso no es un plazo, es un error de cálculo que nadie
     * mira hasta que la lista entera sale en rojo.
     */
    public function test_una_campana_ya_arrancada_cuenta_el_plazo_desde_hoy(): void
    {
        Queue::fake();
        DB::table('campaigns')->where('id', $this->campanaId)
            ->update(['starts_on' => now()->subDays(30)->toDateString()]);
        $this->requisitoDe($this->campanaId, ['quantity' => 1, 'deadline_offset_days' => 12]);

        $id = $this->aceptado(500.0);

        $this->assertSame(now()->addDays(12)->toDateString(), (string) Entregables::de($id)->first()->due_on);
    }

    /** `N-03`: el brief del mercado **reemplaza** al general, y las etiquetas también. */
    public function test_se_usa_el_brief_del_mercado_cuando_lo_hay(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 5, 'hashtags' => '#General']);
        $this->requisitoDe($this->campanaId, [
            'campaign_market_id' => $this->mercadoId, 'quantity' => 2, 'hashtags' => '#Peru',
        ]);

        $id = $this->aceptado(500.0);

        $entregables = Entregables::de($id);
        $this->assertCount(2, $entregables, 'el del mercado REEMPLAZA al general, no se suma');
        $this->assertSame('#Peru', $entregables->first()->hashtags);
    }

    public function test_generar_es_idempotente(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 2]);
        $id = $this->aceptado(500.0);

        $this->assertSame(0, Entregables::generarPara($id), 'ya los tenia');
        $this->assertCount(2, Entregables::de($id));
    }

    public function test_sin_aceptar_no_se_genera_nada(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 2]);
        $id = $this->participacion(500.0);

        $this->assertSame(0, Entregables::generarPara($id));
    }

    /** Y la base tampoco lo deja, venga de donde venga. */
    public function test_la_base_impide_un_entregable_sin_aceptacion(): void
    {
        Queue::fake();
        $requisitoId = $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $id = $this->participacion(500.0);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: la participacion NO esta aceptada y esta
        // fila existe para que `tg_del_participacion_aceptada` la rechace.
        DB::table('deliverables')->insert([
            'uuid' => (string) Str::uuid(), 'campaign_creator_id' => $id,
            'campaign_requirement_id' => $requisitoId, 'sequence_number' => 1,
            'status' => 'pending', 'due_on' => now()->addDays(7)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------- las etiquetas

    public function test_faltan_las_etiquetas_que_el_caption_no_lleva(): void
    {
        $requisito = (object) ['hashtags' => '#ACMEVerano #Publicidad', 'mentions' => '@acme'];

        $faltan = Entregables::faltanEtiquetas('Mira esto #ACMEVerano', $requisito);

        $this->assertSame(['#Publicidad'], $faltan['hashtags']);
        $this->assertSame(['@acme'], $faltan['mentions']);
    }

    /** La caja no importa: `#ACMEVerano` y `#acmeverano` son el mismo hashtag. */
    public function test_las_etiquetas_no_distinguen_mayusculas(): void
    {
        $requisito = (object) ['hashtags' => '#ACMEVerano', 'mentions' => null];

        $faltan = Entregables::faltanEtiquetas('mira esto #acmeverano', $requisito);

        $this->assertSame([], $faltan['hashtags']);
    }

    public function test_un_requisito_sin_etiquetas_no_exige_nada(): void
    {
        $requisito = (object) ['hashtags' => null, 'mentions' => null];

        $faltan = Entregables::faltanEtiquetas(null, $requisito);

        $this->assertSame([], $faltan['hashtags']);
        $this->assertSame([], $faltan['mentions']);
    }

    // ------------------------------------------------------------- entregar

    public function test_entregar_crea_la_version_uno_y_marca_el_entregable(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $id = $this->aceptado(500.0);
        $entregable = $this->entregableDe($id);

        $numero = Entregables::entregar(
            $entregable,
            ['external_url' => 'https://drive.example/x', 'caption' => 'Texto'],
            null, null, '203.0.113.9',
        );

        $this->assertSame(1, $numero);
        $this->assertSame('submitted', (string) DB::table('deliverables')->where('id', $entregable->id)->value('status'));
        $this->assertNotNull(DB::table('deliverables')->where('id', $entregable->id)->value('submitted_at'));
    }

    /**
     * Append-only: la segunda entrega es la versión 2, no una edición.
     *
     * Y `submitted_at` del entregable **no cambia**: responde «¿cuándo entregó?»,
     * y una corrección posterior no altera esa fecha.
     */
    public function test_la_segunda_entrega_es_una_version_nueva(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $id = $this->aceptado(500.0);
        $entregable = $this->entregableDe($id);

        Entregables::entregar($entregable, ['external_url' => 'https://a.example/1'], null, null, null);
        $primera = (string) DB::table('deliverables')->where('id', $entregable->id)->value('submitted_at');

        // Hay que MOVER el reloj. Sin esto las dos entregas caen en el mismo
        // milisegundo, `submitted_at` sale igual pase lo que pase, y la
        // asercion de abajo pasa aunque el codigo reescriba la fecha: una
        // mutacion que ponia `now()` en vez de `$entregable->submitted_at ??
        // now()` sobrevivia aqui, dentro de la unica prueba que la miraba.
        $this->travel(3)->minutes();

        $numero = Entregables::entregar(
            $this->entregableDe($id),
            ['external_url' => 'https://a.example/2'],
            null, null, null,
        );

        $this->assertSame(2, $numero);
        $this->assertCount(2, Entregables::versiones((int) $entregable->id));
        $this->assertSame($primera, (string) DB::table('deliverables')->where('id', $entregable->id)->value('submitted_at'));
    }

    public function test_la_base_impide_dos_versiones_con_el_mismo_numero(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $id = $this->aceptado(500.0);
        $entregable = $this->entregableDe($id);
        Entregables::entregar($entregable, ['external_url' => 'https://a.example/1'], null, null, null);

        $this->expectException(QueryException::class);

        DB::table('deliverable_versions')->insert([
            'uuid' => (string) Str::uuid(), 'deliverable_id' => $entregable->id,
            'version_number' => 1, 'external_url' => 'https://a.example/2',
            'submitted_at' => now(), 'created_at' => now(),
        ]);
    }

    public function test_la_base_exige_https(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $id = $this->aceptado(500.0);
        $entregable = $this->entregableDe($id);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: esta fila EXISTE para que la base la
        // rechace.
        DB::table('deliverable_versions')->insert([
            'uuid' => (string) Str::uuid(), 'deliverable_id' => $entregable->id,
            'version_number' => 1, 'external_url' => 'http://a.example/1',
            'submitted_at' => now(), 'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------- el veto

    public function test_sin_enlace_ni_archivo_no_se_entrega(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $entregable = $this->entregableDe($this->aceptado(500.0));

        $motivos = Entregables::vetoParaEntregar($entregable, ['caption' => 'Algo'], null);

        $this->assertStringContainsString('Manda el enlace', implode(' ', $motivos));
    }

    public function test_faltando_hashtags_no_se_entrega_y_se_dice_cuales(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, [
            'quantity' => 1, 'hashtags' => '#ACMEVerano #Publicidad', 'mentions' => '@acme',
        ]);
        $entregable = $this->entregableDe($this->aceptado(500.0));

        $motivos = Entregables::vetoParaEntregar(
            $entregable,
            ['external_url' => 'https://a.example/1', 'caption' => 'Mira esto #ACMEVerano'],
            null,
        );

        $texto = implode(' ', $motivos);
        $this->assertStringContainsString('#Publicidad', $texto);
        $this->assertStringContainsString('@acme', $texto);
        $this->assertStringNotContainsString('#ACMEVerano ', $texto, 'el que SI esta no se nombra');
    }

    public function test_con_todas_las_etiquetas_si_se_entrega(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1, 'hashtags' => '#ACMEVerano', 'mentions' => '@acme']);
        $entregable = $this->entregableDe($this->aceptado(500.0));

        $this->assertSame([], Entregables::vetoParaEntregar(
            $entregable,
            ['external_url' => 'https://a.example/1', 'caption' => 'Mira #ACMEVerano de @acme'],
            null,
        ));
    }

    // ---------------------------------------------------- el portal del creador

    /**
     * Un entregable ya aprobado no admite mas envios.
     *
     * `ABIERTOS` existe para esto y no lo miraba ninguna prueba: quitar el veto
     * entero dejaba la suite verde. Sin el, un creador puede mandar una version
     * nueva encima de algo que el cliente ya dio por bueno, y la campana pasa a
     * tener aprobado un contenido que nadie aprobo.
     */
    public function test_sobre_un_entregable_cerrado_no_se_entrega(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $id = $this->aceptado(500.0);
        $entregable = $this->entregableDe($id);
        Entregables::entregar($entregable, ['external_url' => 'https://a.example/1'], null, null, null);
        // Con firma y con puntero: desde 8.3 `ck_del_aprobador` exige que
        // aprobado diga QUIEN, y desde 8.2 `ck_del_approved_version` exige que
        // diga QUE version.
        DB::table('deliverables')->where('id', $entregable->id)->update([
            'status' => 'approved', 'approved_at' => now(),
            'approved_by_user_id' => $this->usuarioCon('campaign_manager')->id,
            'approved_version_id' => DB::table('deliverable_versions')
                ->where('deliverable_id', $entregable->id)->orderByDesc('version_number')->value('id'),
        ]);

        $motivos = Entregables::vetoParaEntregar(
            DB::table('deliverables')->where('id', $entregable->id)->first(),
            ['external_url' => 'https://a.example/2'],
            null,
        );

        $this->assertCount(1, $motivos);
        $this->assertStringContainsString('ya no admite envios', $motivos[0]);
    }

    /**
     * El `https://` se dice con palabras ANTES de que lo diga `ck_dv_url_https`.
     *
     * La base ya lo impide y hay prueba de ello, pero un 3819 en la cara del
     * creador no es una respuesta. Sin esta prueba, quitar el veto del servicio
     * no rompia nada: el rechazo seguia existiendo, solo que en forma de 500.
     */
    public function test_el_servicio_veta_un_enlace_que_no_es_https(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $id = $this->aceptado(500.0);

        $motivos = Entregables::vetoParaEntregar(
            $this->entregableDe($id),
            ['external_url' => 'http://a.example/1'],
            null,
        );

        $this->assertSame(['El enlace tiene que empezar por https://'], $motivos);
    }

    /**
     * El numero de version se calcula **con la fila bloqueada**.
     *
     * Esta prueba mira el SQL y no el resultado, a proposito, porque no hay
     * resultado que mirar: PHPUnit corre en una conexion y dentro de una
     * transaccion, asi que una carrera de verdad no se puede montar aqui —es
     * justo lo que explica `tools/pruebas/4.11-concurrencia.sh`—. Sin el
     * `FOR UPDATE`, dos envios simultaneos del mismo entregable calculan el
     * mismo numero y el segundo se estrella contra `uq_dv_number` con un 1062
     * en la cara del creador.
     *
     * Quitar `lockForUpdate()` no rompia ninguna prueba. Ahora rompe esta.
     */
    public function test_el_numero_de_version_se_calcula_con_la_fila_bloqueada(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $id = $this->aceptado(500.0);
        $entregable = $this->entregableDe($id);

        $consultas = [];
        DB::listen(static function ($evento) use (&$consultas): void {
            $consultas[] = strtolower((string) $evento->sql);
        });

        Entregables::entregar($entregable, ['external_url' => 'https://a.example/1'], null, null, null);

        $maximos = array_values(array_filter(
            $consultas,
            static fn (string $sql): bool => str_contains($sql, 'max(`version_number`)'),
        ));

        $this->assertCount(1, $maximos, 'el numero de version sale de una sola consulta');
        $this->assertStringContainsString('for update', $maximos[0]);
    }

    public function test_el_creador_ve_lo_suyo_y_entrega(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1, 'hashtags' => '#ACMEVerano']);
        $id = $this->aceptado(500.0);
        $usuario = $this->cuentaDelCreadorDe($id);
        $entregable = $this->entregableDe($id);

        $this->actingAs($usuario)
            ->get(route('entregas.mias'))
            ->assertOk()
            ->assertSee('#ACMEVerano');

        $this->actingAs($usuario)
            ->post(route('entregas.entregar', $entregable->uuid), [
                'external_url' => 'https://drive.example/reel',
                'caption' => 'Mira esto #ACMEVerano',
            ])
            ->assertRedirect(route('entregas.mias'))
            ->assertSessionHas('exito');

        $this->assertCount(1, Entregables::versiones((int) $entregable->id));
    }

    public function test_al_creador_le_falta_un_hashtag_y_la_pantalla_se_lo_dice(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1, 'hashtags' => '#ACMEVerano']);
        $id = $this->aceptado(500.0);
        $entregable = $this->entregableDe($id);

        $this->actingAs($this->cuentaDelCreadorDe($id))
            ->post(route('entregas.entregar', $entregable->uuid), [
                'external_url' => 'https://drive.example/reel',
                'caption' => 'Mira esto',
            ])
            ->assertRedirect()
            ->assertSessionHas('aviso');

        $this->assertCount(0, Entregables::versiones((int) $entregable->id));
    }

    /**
     * **Un creador no entrega lo de otro.**
     *
     * El permiso dice «puede ver UN portal de creador»; cuál lo decide
     * `creators.user_id`. Y es 404, no 403: `BR-SEC-006` dice que no se revela
     * que el recurso exista.
     */
    public function test_un_creador_no_entrega_lo_de_otro(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $mio = $this->aceptado(500.0);
        $ajeno = $this->aceptado(500.0);

        $this->actingAs($this->cuentaDelCreadorDe($mio))
            ->post(route('entregas.entregar', $this->entregableDe($ajeno)->uuid), [
                'external_url' => 'https://drive.example/reel',
            ])
            ->assertNotFound();

        $this->assertCount(0, Entregables::versiones((int) $this->entregableDe($ajeno)->id));
    }

    public function test_un_usuario_interno_no_entra_al_portal_del_creador(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('entregas.mias'))
            ->assertForbidden();
    }

    public function test_el_enlace_tiene_que_ser_https_tambien_en_la_pantalla(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $id = $this->aceptado(500.0);

        $this->actingAs($this->cuentaDelCreadorDe($id))
            ->post(route('entregas.entregar', $this->entregableDe($id)->uuid), [
                'external_url' => 'http://drive.example/reel',
            ])
            ->assertSessionHasErrors('external_url');
    }

    public function test_el_creador_puede_adjuntar_una_imagen(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $id = $this->aceptado(500.0);

        $this->actingAs($this->cuentaDelCreadorDe($id))
            ->post(route('entregas.entregar', $this->entregableDe($id)->uuid), [
                'archivo' => UploadedFile::fake()->image('referencia.png'),
            ])
            ->assertSessionHas('exito');

        $version = Entregables::versiones((int) $this->entregableDe($id)->id)->first();
        $this->assertSame('referencia.png', $version->archivo);
    }

    // ------------------------------------------------------- el back-office

    public function test_la_pantalla_interna_ensena_lo_entregado(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $id = $this->aceptado(500.0);
        Entregables::entregar(
            $this->entregableDe($id),
            ['external_url' => 'https://drive.example/reel', 'caption' => 'Un texto'],
            null, null, null,
        );

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('campanas.entregables', $this->uuid))
            ->assertOk()
            ->assertSee('https://drive.example/reel')
            ->assertSee('Un texto');
    }

    /** Un aceptado sin entregables **se avisa**, y se pueden crear a mano. */
    public function test_un_aceptado_sin_entregables_se_avisa_y_se_arregla(): void
    {
        Queue::fake();
        $id = $this->aceptado(500.0);   // sin requisitos: no se genera nada
        $gestor = $this->usuarioCon('campaign_manager');

        $this->actingAs($gestor)
            ->get(route('campanas.entregables', $this->uuid))
            ->assertOk()
            ->assertSee('no tiene ningún entregable asignado', false);

        // Ahora el brief ya dice algo y se pueden crear.
        $this->requisitoDe($this->campanaId, ['quantity' => 2]);

        $this->actingAs($gestor)
            ->post(route('campanas.entregables.generar', [$this->uuid, $id]))
            ->assertRedirect()
            ->assertSessionHas('exito');

        $this->assertCount(2, Entregables::de($id));
    }

    public function test_generar_dos_veces_no_duplica(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 2]);
        $id = $this->aceptado(500.0);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post(route('campanas.entregables.generar', [$this->uuid, $id]))
            ->assertSessionHas('aviso');

        $this->assertCount(2, Entregables::de($id));
    }

    public function test_la_pantalla_interna_exige_su_permiso(): void
    {
        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('campanas.entregables', $this->uuid))
            ->assertForbidden();
    }

    public function test_el_avance_cuenta_los_vencidos_sin_entregar(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 2]);
        $id = $this->aceptado(500.0);
        $entregables = Entregables::de($id);

        Entregables::entregar($this->entregableDe($id), ['external_url' => 'https://a.example/1'], null, null, null);

        // Se VIAJA en el tiempo en vez de mover la fecha limite hacia atras.
        //
        // `ck_del_due_futuro` impide poner un plazo anterior al dia en que se
        // creo el entregable, y hace bien: eso no es replanificar, es un error de
        // calculo. Un entregable vence porque pasa el tiempo, no porque alguien
        // le cambie la fecha — asi que la prueba tiene que hacer pasar el tiempo.
        $this->travel(30)->days();

        $avance = Entregables::avance($this->campanaId);
        $this->assertGreaterThan(0, $entregables->count());

        $this->assertSame(2, $avance['total']);
        $this->assertSame(1, $avance['enviados']);
        $this->assertSame(1, $avance['vencidos']);
    }

    /** Uno entregado tarde **no** cuenta como vencido: ya llegó. */
    public function test_lo_entregado_tarde_no_cuenta_como_vencido(): void
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, ['quantity' => 1]);
        $id = $this->aceptado(500.0);
        Entregables::entregar($this->entregableDe($id), ['external_url' => 'https://a.example/1'], null, null, null);

        $this->travel(30)->days();

        $this->assertSame(0, Entregables::avance($this->campanaId)['vencidos']);
    }

    // ------------------------------------------------------------------ apoyo

    private function campana(): object
    {
        return DB::table('campaigns')->where('id', $this->campanaId)->first();
    }

    private function fila(int $id): object
    {
        return DB::table('campaign_creators')->where('id', $id)->first();
    }

    private function entregableDe(int $participacionId): object
    {
        return DB::table('deliverables')->where('campaign_creator_id', $participacionId)
            ->orderBy('id')->first();
    }

    /** La cuenta de usuario del creador de esa participación (5.9). */
    private function cuentaDelCreadorDe(int $participacionId): User
    {
        $creadorId = (int) DB::table('campaign_creators')->where('id', $participacionId)->value('creator_id');
        $usuario = User::factory()->create(['user_type' => 'creator']);

        DB::table('creators')->where('id', $creadorId)->update(['user_id' => $usuario->id]);
        DB::table('role_user')->insert([
            'user_id' => $usuario->id,
            'role_id' => DB::table('roles')->where('code', 'creator')->value('id'),
            'assigned_at' => now(),
        ]);
        Permisos::olvidar((int) $usuario->id);

        return $usuario;
    }

    private function participacion(float $importe): int
    {
        $creadorId = $this->creadorActivo();
        ListaCorta::anadir($this->campana(), $creadorId);

        $id = (int) DB::table('campaign_creators')
            ->where('campaign_id', $this->campanaId)->where('creator_id', $creadorId)->value('id');

        DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => $importe]);

        return $id;
    }

    private function aceptado(float $importe): int
    {
        $id = $this->participacion($importe);
        $token = Invitaciones::invitar($this->campana(), $this->fila($id), (int) $this->usuarioCon('admin')->id);
        Invitaciones::aceptar($token, '203.0.113.9');

        return $id;
    }
}
