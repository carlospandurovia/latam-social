<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Campaign\Services\Invitaciones;
use App\Modules\Campaign\Services\ListaCorta;
use App\Modules\Content\Services\Entregables;
use App\Modules\Content\Services\Evidencias;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * La prueba de que el post existió (iteración 8.7).
 *
 * ### Las tres decisiones que estas pruebas fijan
 *
 * | Decisión (2026-08-26) | Qué se afirma |
 * |---|---|
 * | La captura manda; el HTTP no decide | ni un `200` permite verificar |
 * | Verificar es su propio permiso | `content.verify`, comprobado en el POST |
 * | Si el post no está, vuelve al creador | a `approved`, y se le avisa |
 */
final class EvidenciasTest extends TestCase
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

    // ------------------------------------------------- la captura es lo que vale

    public function test_sin_captura_no_se_verifica(): void
    {
        [$publicacion] = $this->publicada();

        $motivos = Evidencias::vetoParaVerificar($publicacion, null);

        $this->assertCount(1, $motivos);
        $this->assertStringContainsString('Suba la captura', $motivos[0]);
    }

    /**
     * Un `200` no permite verificar, y eso es la decisión entera de `8.7`.
     *
     * Instagram y TikTok devuelven `200` con un muro de login y `403` a todo lo
     * que no sea un navegador: el estado **no distingue** «el post existe» de
     * «nos bloquearon». Si un `http_check` bastara, `verified` —de donde cuelga
     * el pago— se apoyaría en un dato que no demuestra nada.
     */
    public function test_una_sonda_http_de_200_no_basta_para_verificar(): void
    {
        [$publicacion, $usuario] = $this->publicada();
        Evidencias::anotarSonda((int) $publicacion->id, 200, (int) $usuario->id);

        $motivos = Evidencias::vetoParaVerificar(
            Evidencias::publicacion((string) $publicacion->uuid), null,
        );

        $this->assertCount(1, $motivos);
        $this->assertStringContainsString('Suba la captura', $motivos[0]);
    }

    public function test_la_base_tampoco_deja_verificar_sin_captura(): void
    {
        [$publicacion, $usuario] = $this->publicada();
        Evidencias::anotarSonda((int) $publicacion->id, 200, (int) $usuario->id);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_pub_verificada_con_evidencia` la rechaza.
        DB::table('publications')->where('id', $publicacion->id)->update([
            'status' => 'verified', 'verified_at' => now(), 'verified_by_user_id' => $usuario->id,
        ]);
    }

    /**
     * La permanencia sale de **cuándo se publicó**, no de cuándo se verifica.
     *
     * El post se registra con fecha de hace cinco días a propósito. Con la
     * fecha de hoy —que es lo normal al reportar— `published_at` y `now()`
     * coinciden y la aserción pasa aunque el cálculo use el reloj equivocado:
     * una mutación que cambiaba `published_at` por `now()` sobrevivía justo aquí.
     */
    public function test_con_la_captura_si_se_verifica_y_se_calcula_la_permanencia(): void
    {
        [$publicacion, $usuario] = $this->publicada(
            ['permanence_days' => 45],
            now()->subDays(5)->toDateTimeString(),
        );
        $archivoId = $this->capturaGuardada();

        Evidencias::verificar($publicacion, $archivoId, (int) $usuario->id);

        $fila = DB::table('publications')->where('id', $publicacion->id)->first();

        $this->assertSame('verified', $fila->status);
        $this->assertSame((int) $usuario->id, (int) $fila->verified_by_user_id);
        $this->assertSame(
            Carbon::parse((string) $publicacion->published_at)
                ->addDays(45)->toDateString(),
            (string) $fila->permanence_until,
            'permanencia = publicado + los dias del requisito',
        );
        $this->assertSame('verified', (string) DB::table('deliverables')
            ->where('id', $publicacion->deliverable_id)->value('status'));
    }

    public function test_la_captura_queda_archivada_y_no_se_puede_borrar(): void
    {
        [$publicacion, $usuario] = $this->publicada();
        Evidencias::verificar($publicacion, $this->capturaGuardada(), (int) $usuario->id);

        $this->assertCount(1, Evidencias::de((int) $publicacion->id));

        $this->expectException(QueryException::class);

        // `publication_evidence` lleva `no_delete` desde 3.12.
        DB::table('publication_evidence')->where('publication_id', $publicacion->id)->delete();
    }

    // ------------------------------------------------------------ el rechazo

    public function test_rechazar_devuelve_el_entregable_al_creador(): void
    {
        [$publicacion, $usuario] = $this->publicada();

        Evidencias::rechazar($publicacion, 'not_found', null, null, (int) $usuario->id);

        $fila = DB::table('publications')->where('id', $publicacion->id)->first();

        $this->assertSame('rejected', $fila->status);
        $this->assertNotNull($fila->verified_at);
        $this->assertStringContainsString('no lleva a ningún post', (string) $fila->rejected_reason);
        // A `approved`, no a revisión: el contenido no tenía nada malo.
        $this->assertSame('approved', (string) DB::table('deliverables')
            ->where('id', $publicacion->deliverable_id)->value('status'));
    }

    /**
     * El agujero que apareció al conectar el rechazo.
     *
     * Se le pide al creador que arregle el post y vuelva a registrar el **mismo**
     * enlace. Con `uq_pub_fingerprint` global, eso era un `1062` en su cara. La
     * columna puerta `viva_gate` hace que una rechazada no reclame nada.
     */
    public function test_tras_el_rechazo_el_creador_puede_registrar_el_mismo_enlace(): void
    {
        [$publicacion, $usuario, $entregable] = $this->publicada();
        $url = (string) $publicacion->url;
        Evidencias::rechazar($publicacion, 'private', null, null, (int) $usuario->id);

        $fresco = Revisiones::entregable((string) $entregable->uuid);
        $motivos = Publicaciones::vetoParaPublicar($fresco, $url, null);

        $this->assertSame([], $motivos, 'el enlace de una rechazada vuelve a estar libre');

        Publicaciones::reportar($fresco, $url, null, (int) $usuario->id, null);

        $this->assertSame(2, DB::table('publications')
            ->where('deliverable_id', $entregable->id)->count());
    }

    /**
     * Y **otro** entregable también puede reclamar un enlace rechazado.
     *
     * No es rebuscado: es el motivo `wrong_account` de la lista. Se rechaza el
     * post de Ana porque está publicado en otra cuenta, y esa otra cuenta es la
     * de Luis, que lo registra con todo el derecho. Si la rechazada siguiera
     * reclamando el enlace, Luis no podría registrar su propio post.
     */
    public function test_otro_entregable_puede_reclamar_un_enlace_rechazado(): void
    {
        [$primera, $usuario, , $participacionId] = $this->publicada(['quantity' => 2]);
        $url = (string) $primera->url;
        Evidencias::rechazar($primera, 'wrong_account', null, null, (int) $usuario->id);
        $segundo = $this->aprobarSegundo($participacionId, $usuario);

        $motivos = Publicaciones::vetoParaPublicar($segundo, $url, null);

        $this->assertSame([], $motivos, 'una rechazada no reclama nada');
    }

    public function test_dos_publicaciones_vivas_con_el_mismo_enlace_siguen_sin_poder(): void
    {
        [$primera, $usuario, $entregable, $participacionId] = $this->publicada(['quantity' => 2]);
        $segundo = $this->aprobarSegundo($participacionId, $usuario);

        $motivos = Publicaciones::vetoParaPublicar($segundo, (string) $primera->url, null);

        $this->assertNotSame([], $motivos);
        $this->assertStringContainsString('ya esta registrado en otro entregable', $motivos[0]);
    }

    public function test_rechazar_avisa_al_creador(): void
    {
        [$publicacion, $usuario] = $this->publicada();
        Event::fake([CorreoPedido::class]);

        Evidencias::rechazar($publicacion, 'not_found', null, null, (int) $usuario->id);

        Event::assertDispatched(CorreoPedido::class, static fn (CorreoPedido $c): bool => $c->codigo === 'content.publication_rejected');
    }

    /** Verificar no manda correo: no le pide nada al creador, su parte terminó. */
    public function test_verificar_no_manda_correo(): void
    {
        [$publicacion, $usuario] = $this->publicada();
        $archivoId = $this->capturaGuardada();
        Event::fake([CorreoPedido::class]);

        Evidencias::verificar($publicacion, $archivoId, (int) $usuario->id);

        Event::assertNotDispatched(CorreoPedido::class);
    }

    public function test_la_plantilla_del_aviso_existe(): void
    {
        $this->assertSame(1, DB::table('email_templates')
            ->where('code', 'content.publication_rejected')->count());
    }

    // ------------------------------------------------------------------ la cola

    public function test_la_cola_ensena_lo_reportado_y_lo_pierde_al_verificar(): void
    {
        [$publicacion, $usuario] = $this->publicada();

        $this->assertCount(1, Evidencias::cola());

        Evidencias::verificar($publicacion, $this->capturaGuardada(), (int) $usuario->id);

        $this->assertCount(0, Evidencias::cola());
    }

    public function test_una_publicacion_ya_verificada_no_admite_otro_veredicto(): void
    {
        [$publicacion, $usuario] = $this->publicada();
        Evidencias::verificar($publicacion, $this->capturaGuardada(), (int) $usuario->id);

        $motivos = Evidencias::vetoParaVerificar(
            Evidencias::publicacion((string) $publicacion->uuid), 1,
        );

        $this->assertCount(1, $motivos);
        $this->assertStringContainsString('ya no espera veredicto', $motivos[0]);
    }

    // --------------------------------------------------------------- pantallas

    public function test_la_cola_exige_su_permiso(): void
    {
        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('verificacion.cola'))
            ->assertForbidden();
    }

    public function test_un_verificador_ve_la_cola_y_verifica_con_captura(): void
    {
        [$publicacion] = $this->publicada();
        $usuario = $this->usuarioCon('content_reviewer');

        $this->actingAs($usuario)->get(route('verificacion.cola'))->assertOk()->assertSee('Comprobar');

        $this->actingAs($usuario)
            ->post(route('verificacion.verificar', $publicacion->uuid), [
                'veredicto' => Evidencias::VERIFICADA,
                'captura' => UploadedFile::fake()->image('post.jpg'),
            ])
            ->assertRedirect(route('verificacion.cola'));

        $this->assertSame('verified', (string) DB::table('publications')
            ->where('id', $publicacion->id)->value('status'));
    }

    /**
     * Sin `content.verify` no se verifica, aunque se mande el formulario.
     *
     * Se comprueba en el POST y no sólo escondiendo el botón: el formulario se
     * manda igual desde fuera de la pantalla.
     */
    public function test_sin_permiso_de_verificacion_no_se_verifica(): void
    {
        [$publicacion] = $this->publicada();
        $soloMira = $this->usuarioConPermisos(['content.deliverable.view']);

        $this->actingAs($soloMira)
            ->post(route('verificacion.verificar', $publicacion->uuid), [
                'veredicto' => Evidencias::VERIFICADA,
                'captura' => UploadedFile::fake()->image('post.jpg'),
            ])
            ->assertRedirect()
            ->assertSessionHas('aviso');

        $this->assertSame('reported', (string) DB::table('publications')
            ->where('id', $publicacion->id)->value('status'));
    }

    public function test_verificar_sin_subir_captura_no_pasa_la_validacion(): void
    {
        [$publicacion] = $this->publicada();

        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->post(route('verificacion.verificar', $publicacion->uuid), [
                'veredicto' => Evidencias::VERIFICADA,
            ])
            ->assertSessionHasErrors('captura');

        $this->assertSame('reported', (string) DB::table('publications')
            ->where('id', $publicacion->id)->value('status'));
    }

    public function test_rechazar_desde_la_pantalla_exige_motivo(): void
    {
        [$publicacion] = $this->publicada();

        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->post(route('verificacion.verificar', $publicacion->uuid), [
                'veredicto' => Evidencias::RECHAZADA,
            ])
            ->assertSessionHasErrors('motivo');
    }

    /**
     * Verificar dos veces desde la pantalla se responde con un aviso.
     *
     * `tg_pub_verificada_con_evidencia` **no** lo impide —sólo mira la
     * transición hacia `verified`, y de `verified` a `verified` no es una— así
     * que sin el veto del controlador la fecha de verificación y la firma se
     * reescribirían en silencio sobre una publicación ya cerrada.
     */
    public function test_verificar_dos_veces_desde_la_pantalla_avisa(): void
    {
        [$publicacion, $usuario] = $this->publicada();
        Evidencias::verificar($publicacion, $this->capturaGuardada(), (int) $usuario->id);
        $primeraFirma = DB::table('publications')->where('id', $publicacion->id)->value('verified_at');

        $this->travel(2)->minutes();

        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->post(route('verificacion.verificar', $publicacion->uuid), [
                'veredicto' => Evidencias::VERIFICADA,
                'captura' => UploadedFile::fake()->image('otra.jpg'),
            ])
            ->assertRedirect()
            ->assertSessionHas('aviso');

        $this->assertSame(
            (string) $primeraFirma,
            (string) DB::table('publications')->where('id', $publicacion->id)->value('verified_at'),
            'la firma original no se reescribe',
        );
    }

    public function test_una_publicacion_que_no_existe_da_404(): void
    {
        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->get(route('verificacion.ver', (string) Str::uuid()))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------ apoyo

    /** @return array{0: object, 1: User, 2: object, 3: int} */
    private function publicada(array $requisito = [], ?string $cuando = null): array
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
            $aprobado, 'https://instagram.com/p/ZZZ', $cuando, (int) $usuario->id, null,
        );

        return [Evidencias::publicacion($uuid), $usuario, $aprobado, $participacionId];
    }

    private function aprobarSegundo(int $participacionId, User $usuario): object
    {
        $segundo = DB::table('deliverables')->where('campaign_creator_id', $participacionId)
            ->where('sequence_number', 2)->first();
        Entregables::entregar($segundo, ['external_url' => 'https://a.example/2'], null, (int) $usuario->id, null);
        Revisiones::emitir(
            Revisiones::entregable((string) $segundo->uuid),
            Revisiones::ultimaVersion((int) $segundo->id),
            ['outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform'],
            (int) $usuario->id, null,
        );

        return Revisiones::entregable((string) $segundo->uuid);
    }

    /** Una captura de verdad en el almacén, para tener un `file_id` válido. */
    private function capturaGuardada(): int
    {
        return Almacen::guardar(UploadedFile::fake()->image('post.jpg'), 'publication_evidence');
    }

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
}
