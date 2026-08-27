<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Campaign\Services\Invitaciones;
use App\Modules\Campaign\Services\ListaCorta;
use App\Modules\Content\Services\Entregables;
use App\Modules\Content\Services\Evidencias;
use App\Modules\Content\Services\Permanencia;
use App\Modules\Content\Services\Publicaciones;
use App\Modules\Content\Services\Revisiones;
use App\Shared\Auth\Permisos;
use App\Shared\Eventos\CorreoPedido;
use App\Shared\Files\Almacen;
use Database\Seeders\CimientosSeeder;
use Database\Seeders\PlantillasDeCorreoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * La permanencia mínima del post (iteración 8.8).
 *
 * ### Las tres decisiones que estas pruebas fijan
 *
 * | Decisión (2026-08-27) | Qué se afirma |
 * |---|---|
 * | `DEC-145` retirar el post bloquea el pago | el entregable sale de `verified`, y **nada** se descuenta |
 * | `DEC-146` la sonda marca, la persona firma | anotar no cambia ningún estado |
 * | `DEC-147` se avisa al creador, no al cliente | un `CorreoPedido` y sólo uno |
 */
final class PermanenciaTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $campanaId;

    private int $paisPE;

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
        $this->mercadoDe($this->campanaId, $this->paisPE, ['target_creators' => null]);
    }

    // ------------------------------------------- la sonda marca, no decide

    /**
     * Anotar una comprobación **no cambia el estado de nada** (`DEC-146`).
     *
     * Es la decisión entera: Instagram y TikTok responden igual ante un post
     * borrado, un perfil en privado y un bloqueo, así que ningún resultado
     * automático puede acusar a un creador de incumplir.
     */
    public function test_anotar_una_caida_no_cambia_el_estado_de_nada(): void
    {
        [$publicacion, $usuario] = $this->vigilada();

        Permanencia::anotar((int) $publicacion->id, false, Permanencia::MANUAL,
            404, 'El enlace devuelve no encontrado', (int) $usuario->id);

        $this->assertSame('verified', (string) DB::table('publications')
            ->where('id', $publicacion->id)->value('status'));
        $this->assertSame('verified', (string) DB::table('deliverables')
            ->where('id', $publicacion->deliverable_id)->value('status'));
        $this->assertCount(1, Permanencia::comprobaciones((int) $publicacion->id));
    }

    public function test_una_caida_sin_decir_que_se_vio_la_rechaza_la_base(): void
    {
        [$publicacion, $usuario] = $this->vigilada();

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `ck_pc_caida_motivada` la rechaza.
        Permanencia::anotar((int) $publicacion->id, false, Permanencia::MANUAL,
            null, null, (int) $usuario->id);
    }

    /**
     * La decimosexta columna puerta, y el fallo real que tapa.
     *
     * `docs/18` §2: en producción el planificador es una línea de cron. Dos
     * servidores con la misma línea, o alguien que ejecuta el comando a mano
     * para comprobar que funciona, meterían la misma comprobación dos veces —y
     * cada una manda su correo al creador—.
     */
    public function test_la_sonda_solo_escribe_una_vez_por_publicacion_y_dia(): void
    {
        [$publicacion] = $this->vigilada();

        Permanencia::anotar((int) $publicacion->id, true, Permanencia::SONDA, 200, null, null);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `uq_pc_sonda_dia` la rechaza.
        Permanencia::anotar((int) $publicacion->id, true, Permanencia::SONDA, 200, null, null);
    }

    public function test_una_persona_puede_mirar_las_veces_que_quiera(): void
    {
        [$publicacion, $usuario] = $this->vigilada();

        Permanencia::anotar((int) $publicacion->id, true, Permanencia::MANUAL, 200, null, (int) $usuario->id);
        Permanencia::anotar((int) $publicacion->id, true, Permanencia::MANUAL, 200, null, (int) $usuario->id);
        Permanencia::anotar((int) $publicacion->id, true, Permanencia::MANUAL, 200, null, (int) $usuario->id);

        $this->assertCount(3, Permanencia::comprobaciones((int) $publicacion->id));
    }

    public function test_una_comprobacion_no_se_edita_ni_se_borra(): void
    {
        [$publicacion, $usuario] = $this->vigilada();
        Permanencia::anotar((int) $publicacion->id, true, Permanencia::MANUAL, 200, null, (int) $usuario->id);

        try {
            DB::table('permanence_checks')->where('publication_id', $publicacion->id)
                ->update(['is_live' => 0]);
            $this->fail('`tg_pc_inmutable` tenia que impedir el UPDATE.');
        } catch (QueryException) {
            // Esperado: append-only.
        }

        $this->expectException(QueryException::class);

        // `permanence_checks` entro en la lista de 3.12 con 8.8.
        DB::table('permanence_checks')->where('publication_id', $publicacion->id)->delete();
    }

    // --------------------------------------------------------- la firma

    public function test_no_se_firma_una_caida_sin_comprobacion_que_la_respalde(): void
    {
        [$publicacion] = $this->vigilada();

        $motivos = Permanencia::vetoParaDarPorCaida($publicacion, true);

        $this->assertCount(1, $motivos);
        $this->assertStringContainsString('Anote antes una comprobacion', $motivos[0]);
    }

    public function test_ni_sin_captura_de_lo_que_se_ve_ahora(): void
    {
        [$publicacion, $usuario] = $this->vigilada();
        Permanencia::anotar((int) $publicacion->id, false, Permanencia::MANUAL, 404, null, (int) $usuario->id);

        $motivos = Permanencia::vetoParaDarPorCaida($publicacion, false);

        $this->assertCount(1, $motivos);
        $this->assertStringContainsString('captura de lo que ve ahora', $motivos[0]);
    }

    /**
     * Lo que manda es la **última** mirada, no la primera.
     *
     * Sobrevivió una mutación que ordenaba el historial al revés. Con una sola
     * comprobación por publicación las dos versiones dan lo mismo, y el caso
     * real es justo el que tiene dos: se mira, no está, se le avisa, el creador
     * lo repone —y entonces **no** se firma nada—.
     */
    public function test_manda_la_ultima_mirada_y_no_la_primera(): void
    {
        [$publicacion, $usuario] = $this->vigilada();

        Permanencia::anotar((int) $publicacion->id, false, Permanencia::MANUAL,
            404, 'no aparecia', (int) $usuario->id);
        $this->travel(1)->minutes();
        Permanencia::anotar((int) $publicacion->id, true, Permanencia::MANUAL,
            200, null, (int) $usuario->id);

        $motivos = Permanencia::vetoParaDarPorCaida($publicacion, true);

        $this->assertCount(1, $motivos, 'la ultima dice que esta: no hay caida que firmar');
        $this->assertStringContainsString('Anote antes una comprobacion', $motivos[0]);
    }

    public function test_y_si_la_ultima_dice_que_no_esta_si_se_puede_firmar(): void
    {
        [$publicacion, $usuario] = $this->vigilada();

        Permanencia::anotar((int) $publicacion->id, true, Permanencia::MANUAL,
            200, null, (int) $usuario->id);
        $this->travel(1)->minutes();
        Permanencia::anotar((int) $publicacion->id, false, Permanencia::MANUAL,
            404, 'ya no aparece', (int) $usuario->id);

        $this->assertSame([], Permanencia::vetoParaDarPorCaida($publicacion, true));
    }

    /**
     * La captura vieja no sirve, y lo impone la base.
     *
     * La que probó que el post existía prueba exactamente eso. Reutilizarla como
     * prueba de que ya no está sería archivar lo contrario de lo que enseña.
     */
    public function test_la_base_exige_una_captura_posterior_a_la_verificacion(): void
    {
        [$publicacion, $usuario] = $this->vigilada();
        Permanencia::anotar((int) $publicacion->id, false, Permanencia::MANUAL, 404, null, (int) $usuario->id);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_pub_permanencia` la rechaza. La unica
        // captura archivada es la de verificar, y es anterior a `verified_at`.
        DB::table('publications')->where('id', $publicacion->id)->update([
            'status' => 'removed', 'removed_at' => now(),
            'removed_by_user_id' => $usuario->id,
            'removed_reason' => 'El creador borro el post',
        ]);
    }

    /**
     * `DEC-145`: bloquea el pago y **no descuenta nada**.
     *
     * El entregable sale de `verified` —que es de donde `F9` va a pagar— y pasa
     * a `removed`, que es un estado propio y no `published`: `published`
     * significa «esperando a que alguien lo mire», y un estado que significa dos
     * cosas es el fallo de `T-50`.
     */
    public function test_firmar_la_caida_para_el_pago_y_no_descuenta_nada(): void
    {
        [$publicacion, $usuario] = $this->vigilada();
        Permanencia::anotar((int) $publicacion->id, false, Permanencia::MANUAL, 404, null, (int) $usuario->id);

        Permanencia::darPorCaida($publicacion, 'post_deleted', 'ya no aparece',
            $this->capturaGuardada(), (int) $usuario->id);

        $fila = DB::table('publications')->where('id', $publicacion->id)->first();

        $this->assertSame('removed', (string) $fila->status);
        $this->assertNotNull($fila->removed_at);
        $this->assertSame((int) $usuario->id, (int) $fila->removed_by_user_id);
        $this->assertStringContainsString('borro el post', (string) $fila->removed_reason);
        $this->assertSame('removed', (string) DB::table('deliverables')
            ->where('id', $publicacion->deliverable_id)->value('status'));

        // Y NADA se ha tocado del dinero: ni el importe comprometido ni nada
        // que se le parezca. La decision de que se le paga la toma una persona.
        $this->assertSame(
            '500.0000',
            (string) DB::table('campaign_creators')
                ->where('id', DB::table('deliverables')->where('id', $publicacion->deliverable_id)
                    ->value('campaign_creator_id'))
                ->value('agreed_amount'),
        );
    }

    /** El entregable caído es un entregable CERRADO. */
    public function test_un_entregable_caido_no_admite_una_version_nueva(): void
    {
        [$publicacion, $usuario, $entregable] = $this->vigilada();
        Permanencia::anotar((int) $publicacion->id, false, Permanencia::MANUAL, 404, null, (int) $usuario->id);
        Permanencia::darPorCaida($publicacion, 'post_deleted', null,
            $this->capturaGuardada(), (int) $usuario->id);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_dv_entregable_abierto` la rechaza.
        DB::table('deliverable_versions')->insert([
            'uuid' => (string) Str::uuid(), 'deliverable_id' => $entregable->id,
            'version_number' => 9, 'external_url' => 'https://a.example/otra',
            'submitted_at' => now(), 'created_at' => now(),
        ]);
    }

    /** `DEC-147`: al creador y al equipo; al cliente no. */
    public function test_se_avisa_al_creador_y_a_nadie_mas(): void
    {
        [$publicacion, $usuario] = $this->vigilada();
        Permanencia::anotar((int) $publicacion->id, false, Permanencia::MANUAL, 404, null, (int) $usuario->id);

        // El `fake` va DESPUES de montar la fixtura: invitar, aceptar y aprobar
        // mandan sus propios correos, y contarlos aqui haria que la asercion
        // dijera «tres» sin que ninguno de los otros dos tenga que ver con esto.
        Event::fake([CorreoPedido::class]);

        Permanencia::darPorCaida($publicacion, 'account_private', null,
            $this->capturaGuardada(), (int) $usuario->id);

        Event::assertDispatchedTimes(CorreoPedido::class, 1);
        Event::assertDispatched(CorreoPedido::class, function (CorreoPedido $correo) use ($publicacion): bool {
            return $correo->codigo === 'content.permanence_broken'
                && $correo->destinatario === (string) $publicacion->creador_email;
        });
    }

    /** La nota libre no viaja al evento persistido. `2.2 §7`. */
    public function test_el_evento_lleva_el_motivo_pero_no_la_nota_libre(): void
    {
        [$publicacion, $usuario] = $this->vigilada();
        Permanencia::anotar((int) $publicacion->id, false, Permanencia::MANUAL, 404, null, (int) $usuario->id);

        Permanencia::darPorCaida($publicacion, 'url_changed', 'me lo dijo Ana por WhatsApp',
            $this->capturaGuardada(), (int) $usuario->id);

        $payload = (string) DB::table('domain_events')
            ->where('event_name', 'publication.permanence_broken')->value('payload');

        $this->assertStringContainsString('url_changed', $payload);
        $this->assertStringNotContainsString('WhatsApp', $payload);
    }

    // ------------------------------------------------------------- reponer

    public function test_reponer_devuelve_a_vigilada_sin_mover_la_fecha(): void
    {
        [$publicacion, $usuario] = $this->vigilada();
        Permanencia::anotar((int) $publicacion->id, false, Permanencia::MANUAL, 404, null, (int) $usuario->id);
        Permanencia::darPorCaida($publicacion, 'post_deleted', null,
            $this->capturaGuardada(), (int) $usuario->id);

        $hasta = (string) DB::table('publications')->where('id', $publicacion->id)
            ->value('permanence_until');

        // La captura de la caida y `removed_at` se escriben con el MISMO
        // instante --lo mismo que hace verificar-- asi que reponer necesita una
        // posterior de verdad. En produccion pasan horas; aqui se mueve el
        // reloj en vez de confiar en el milisegundo.
        $this->travel(1)->minutes();

        Permanencia::reponer(
            Permanencia::publicacion((string) $publicacion->uuid),
            $this->capturaGuardada(),
            (int) $usuario->id,
        );

        $fila = DB::table('publications')->where('id', $publicacion->id)->first();

        $this->assertSame('verified', (string) $fila->status);
        $this->assertNull($fila->removed_at);
        $this->assertNull($fila->removed_reason);
        // `Q-59`: si la ventana deberia alargarse por los dias que estuvo caido
        // es una decision de negocio abierta. Alargarla aqui seria inventarse
        // una clausula del contrato.
        $this->assertSame($hasta, (string) $fila->permanence_until);
        $this->assertSame('verified', (string) DB::table('deliverables')
            ->where('id', $publicacion->deliverable_id)->value('status'));
    }

    // ------------------------------------------------------- el planificador

    /**
     * La ventana incluye su último día entero.
     *
     * `permanence_until < CURDATE()` y no `<=`. Cerrarla el mismo día recorta
     * veinticuatro horas de una obligación contractual medida en días, y es la
     * clase de error que sólo se descubre discutiendo un pago.
     */
    public function test_la_ventana_no_se_cierra_su_ultimo_dia(): void
    {
        [$publicacion] = $this->vigilada(['permanence_days' => 30], now()->subDays(30)->toDateTimeString());

        $this->assertSame(now()->toDateString(), (string) DB::table('publications')
            ->where('id', $publicacion->id)->value('permanence_until'));
        $this->assertSame(0, Permanencia::cerrarVentanas());
        $this->assertSame('verified', (string) DB::table('publications')
            ->where('id', $publicacion->id)->value('status'));
    }

    public function test_al_dia_siguiente_la_cierra_y_queda_cumplida(): void
    {
        [$publicacion] = $this->vigilada(['permanence_days' => 30], now()->subDays(31)->toDateTimeString());

        $this->assertSame(1, Permanencia::cerrarVentanas());

        $fila = DB::table('publications')->where('id', $publicacion->id)->first();

        $this->assertSame('fulfilled', (string) $fila->status);
        $this->assertNotNull($fila->fulfilled_at);
    }

    public function test_el_comando_cierra_las_ventanas_cumplidas(): void
    {
        $this->vigilada(['permanence_days' => 30], now()->subDays(31)->toDateTimeString());

        $this->artisan('permanencia:vigilar')
            ->expectsOutputToContain('1 ventana(s) de permanencia cumplida(s)')
            ->assertExitCode(0);
    }

    /**
     * Se dice también cuando son cero.
     *
     * «0 ventanas cerradas» en `planificador.log` demuestra que el comando
     * corrió; el silencio no distingue entre «no había ninguna» y «el cron no
     * está puesto». Es la lección de `7.6`.
     */
    public function test_el_comando_dice_algo_aunque_no_haya_nada_que_cerrar(): void
    {
        $this->artisan('permanencia:vigilar')
            ->expectsOutputToContain('0 ventana(s)')
            ->assertExitCode(0);
    }

    /** Una caída no se cierra sola como cumplida. */
    public function test_el_comando_no_toca_una_publicacion_ya_caida(): void
    {
        [$publicacion, $usuario] = $this->vigilada(
            ['permanence_days' => 30], now()->subDays(31)->toDateTimeString(),
        );
        Permanencia::anotar((int) $publicacion->id, false, Permanencia::MANUAL, 404, null, (int) $usuario->id);
        Permanencia::darPorCaida($publicacion, 'post_deleted', null,
            $this->capturaGuardada(), (int) $usuario->id);

        $this->assertSame(0, Permanencia::cerrarVentanas());
        $this->assertSame('removed', (string) DB::table('publications')
            ->where('id', $publicacion->id)->value('status'));
    }

    public function test_la_ventana_no_se_puede_cerrar_antes_de_su_fecha(): void
    {
        [$publicacion] = $this->vigilada(['permanence_days' => 30], now()->subDays(5)->toDateTimeString());

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_pub_permanencia` la rechaza.
        DB::table('publications')->where('id', $publicacion->id)->update([
            'status' => 'fulfilled', 'fulfilled_at' => now(),
        ]);
    }

    public function test_las_desatendidas_son_las_que_nadie_mira(): void
    {
        [$publicacion, $usuario] = $this->vigilada(
            ['permanence_days' => 60], now()->subDays(20)->toDateTimeString(),
        );

        $this->assertCount(1, Permanencia::desatendidas());

        Permanencia::anotar((int) $publicacion->id, true, Permanencia::MANUAL, 200, null, (int) $usuario->id);

        $this->assertCount(0, Permanencia::desatendidas());
    }

    /**
     * Una mirada de hace un mes no cuenta como mirada.
     *
     * Sobrevivió una mutación que estiraba el plazo a diez años: con una
     * comprobación reciente las dos versiones dan lo mismo, y lo que hay que
     * afirmar es que el plazo **caduca**.
     */
    public function test_una_mirada_vieja_no_cuenta_como_mirada(): void
    {
        [$publicacion, $usuario] = $this->vigilada(
            ['permanence_days' => 60], now()->subDays(20)->toDateTimeString(),
        );

        $this->travel(-1 * (Permanencia::DIAS_DESATENDIDA + 1))->days();
        Permanencia::anotar((int) $publicacion->id, true, Permanencia::MANUAL, 200, null, (int) $usuario->id);
        $this->travelBack();

        $this->assertCount(1, Permanencia::desatendidas(),
            'la comprobacion es mas vieja que el plazo: sigue desatendida');
    }

    // ------------------------------------------------------------ la pantalla

    public function test_la_bandeja_pone_las_caidas_primero(): void
    {
        [$publicacion, $usuario] = $this->vigilada();
        Permanencia::anotar((int) $publicacion->id, false, Permanencia::MANUAL, 404, null, (int) $usuario->id);
        Permanencia::darPorCaida($publicacion, 'post_deleted', null,
            $this->capturaGuardada(), (int) $usuario->id);

        $bandeja = Permanencia::bandeja();

        $this->assertCount(1, $bandeja);
        $this->assertSame('removed', (string) $bandeja->first()->status);
        $this->assertSame(0, (int) $bandeja->first()->ultima_viva);
    }

    /** Firmar necesita `content.verify`, igual que verificar (`8.7`). */
    public function test_sin_el_permiso_de_verificar_no_se_firma_nada(): void
    {
        [$publicacion] = $this->vigilada();
        $mirón = $this->usuarioConPermisos(['content.deliverable.view']);

        $this->actingAs($mirón)
            ->post(route('permanencia.comprobar', $publicacion->uuid), [
                'accion' => 'anotar', 'viva' => '1',
            ])
            ->assertRedirect();

        $this->assertCount(0, Permanencia::comprobaciones((int) $publicacion->id));
    }

    public function test_con_el_permiso_se_anota_desde_la_pantalla(): void
    {
        [$publicacion] = $this->vigilada();
        $usuario = $this->usuarioConPermisos(['content.deliverable.view', 'content.verify']);

        $this->actingAs($usuario)
            ->post(route('permanencia.comprobar', $publicacion->uuid), [
                'accion' => 'anotar', 'viva' => '1', 'http_status' => 200,
            ])
            ->assertRedirect(route('permanencia.ver', $publicacion->uuid));

        $this->assertCount(1, Permanencia::comprobaciones((int) $publicacion->id));
    }

    public function test_la_pantalla_no_deja_anotar_una_caida_muda(): void
    {
        [$publicacion] = $this->vigilada();
        $usuario = $this->usuarioConPermisos(['content.deliverable.view', 'content.verify']);

        $this->actingAs($usuario)
            ->post(route('permanencia.comprobar', $publicacion->uuid), [
                'accion' => 'anotar', 'viva' => '0',
            ])
            ->assertSessionHas('aviso');

        $this->assertCount(0, Permanencia::comprobaciones((int) $publicacion->id));
    }

    /** `BR-SEC-006`: 404 y no 403 ante algo que no existe. */
    public function test_una_publicacion_que_no_existe_da_404(): void
    {
        $usuario = $this->usuarioConPermisos(['content.deliverable.view', 'content.verify']);

        $this->actingAs($usuario)
            ->get(route('permanencia.ver', (string) Str::uuid()))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Una publicación verificada y bajo vigilancia.
     *
     * @return array{0: object, 1: User, 2: object}
     */
    private function vigilada(array $requisito = [], ?string $cuando = null): array
    {
        Queue::fake();
        $this->requisitoDe($this->campanaId, array_merge(['quantity' => 1], $requisito));
        $participacionId = $this->aceptado(500.0);
        $usuario = $this->usuarioCon('campaign_manager');

        $entregable = DB::table('deliverables')->where('campaign_creator_id', $participacionId)
            ->orderBy('sequence_number')->first();
        Entregables::entregar($entregable, ['external_url' => 'https://a.example/1'], null, (int) $usuario->id, null);

        $fresco = Revisiones::entregable((string) $entregable->uuid);
        Revisiones::emitir($fresco, Revisiones::ultimaVersion((int) $entregable->id), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        $aprobado = Revisiones::entregable((string) $entregable->uuid);
        $uuid = Publicaciones::reportar(
            $aprobado, 'https://instagram.com/p/PERM8',
            $cuando ?? now()->subDays(10)->toDateTimeString(),
            (int) $usuario->id, null,
        );

        Evidencias::verificar(Evidencias::publicacion($uuid), $this->capturaGuardada(), (int) $usuario->id);

        // La verificación y su captura, cinco minutos atrás y **al mismo
        // instante**. En producción `Evidencias::verificar()` las escribe con el
        // mismo `$ahora`, así que la captura de verificar nunca es POSTERIOR a
        // `verified_at` —que es justo lo que hace que la regla muerda—. Aquí se
        // fija el momento en vez de confiar en que dos `now()` caigan en
        // milisegundos distintos: es `T-39`.
        $momento = now()->subMinutes(5);
        $publicacionId = (int) DB::table('publications')->where('uuid', $uuid)->value('id');
        DB::table('publications')->where('uuid', $uuid)->update(['verified_at' => $momento]);
        DB::table('publication_evidence')->where('publication_id', $publicacionId)
            ->update(['captured_at' => $momento]);

        return [Permanencia::publicacion($uuid), $usuario, $entregable];
    }

    private function campana(): object
    {
        return DB::table('campaigns')->where('id', $this->campanaId)->first();
    }

    private function fila(int $id): object
    {
        return DB::table('campaign_creators')->where('id', $id)->first();
    }

    private function aceptado(float $importe): int
    {
        $creadorId = $this->creadorActivo();
        ListaCorta::anadir($this->campana(), $creadorId);

        $id = (int) DB::table('campaign_creators')
            ->where('campaign_id', $this->campanaId)->where('creator_id', $creadorId)->value('id');
        DB::table('campaign_creators')->where('id', $id)->update(['agreed_amount' => $importe]);

        $token = Invitaciones::invitar($this->campana(), $this->fila($id), (int) $this->usuarioCon('admin')->id);
        Invitaciones::aceptar($token, '203.0.113.9');

        return $id;
    }

    private function capturaGuardada(): int
    {
        return Almacen::guardar(UploadedFile::fake()->image('post.jpg'), 'publication_evidence');
    }

    /** @param list<string> $permisos */
    private function usuarioConPermisos(array $permisos): User
    {
        $usuario = User::factory()->create();
        $rolId = (int) DB::table('roles')->insertGetId([
            'code' => 'solo_'.Str::random(6), 'name' => 'Rol de prueba',
            'scope' => 'internal', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($permisos as $codigo) {
            DB::table('permission_role')->insert([
                'role_id' => $rolId,
                'permission_id' => (int) DB::table('permissions')->where('code', $codigo)->value('id'),
            ]);
        }

        DB::table('role_user')->insert([
            'user_id' => $usuario->id, 'role_id' => $rolId, 'assigned_at' => now(),
        ]);
        Permisos::olvidar((int) $usuario->id);

        return $usuario;
    }
}
