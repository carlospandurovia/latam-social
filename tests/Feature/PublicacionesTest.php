<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Campaign\Services\Invitaciones;
use App\Modules\Campaign\Services\ListaCorta;
use App\Modules\Content\Services\Entregables;
use App\Modules\Content\Services\Publicaciones;
use App\Modules\Content\Services\Revisiones;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Database\Seeders\PlantillasDeCorreoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El post publicado (iteración 8.6).
 *
 * ### Las tres decisiones que estas pruebas fijan
 *
 * | Decisión (2026-08-26) | Qué se afirma |
 * |---|---|
 * | Reporta el creador, y el equipo también | los dos caminos, mismo veto |
 * | Sin aprobar no se registra | y lo impone la base, no sólo la pantalla |
 * | La red tiene que ser la del brief | deducida del enlace, no preguntada |
 *
 * ### Y la mitad más frágil: la huella
 *
 * `uq_pub_fingerprint` existe para que dos creadores no reclamen el mismo post.
 * Con la URL cruda no impediría nada —`?utm_source=ig` basta para esquivarla— y
 * con una normalización demasiado agresiva rechazaría posts legítimos diciendo
 * que ya están reclamados. Las dos mitades se afirman aquí.
 */
final class PublicacionesTest extends TestCase
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

    // ------------------------------------------------------------- la huella

    /**
     * Lo que la plataforma añade al compartir **no** identifica el post.
     */
    #[DataProvider('mismoPost')]
    public function test_dos_formas_de_escribir_el_mismo_post_dan_la_misma_huella(string $a, string $b): void
    {
        $this->assertSame(
            Publicaciones::huella($a),
            Publicaciones::huella($b),
            "«{$a}» y «{$b}» son el mismo post",
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function mismoPost(): array
    {
        return [
            'utm' => ['https://instagram.com/p/ABC', 'https://instagram.com/p/ABC?utm_source=ig&utm_medium=social'],
            'igshid' => ['https://instagram.com/p/ABC', 'https://instagram.com/p/ABC?igshid=xyz123'],
            'www' => ['https://instagram.com/p/ABC', 'https://www.instagram.com/p/ABC'],
            'barra final' => ['https://instagram.com/p/ABC', 'https://instagram.com/p/ABC/'],
            'fragmento' => ['https://instagram.com/p/ABC', 'https://instagram.com/p/ABC#comentarios'],
            'host en mayusculas' => ['https://instagram.com/p/ABC', 'https://INSTAGRAM.com/p/ABC'],
            'tiktok con _t y _r' => [
                'https://tiktok.com/@ana/video/123',
                'https://www.tiktok.com/@ana/video/123?_t=8abc&_r=1',
            ],
            'parametros en otro orden' => [
                'https://youtube.com/watch?list=PL1&v=abc',
                'https://youtube.com/watch?v=abc&list=PL1',
            ],
        ];
    }

    /**
     * Y lo que SÍ identifica el post no se toca.
     *
     * La mitad que casi nadie prueba, y la que hace daño de verdad: una
     * normalización demasiado agresiva rechaza un post legítimo diciendo que ya
     * está reclamado, y quien lo reporta no tiene forma de saber por qué.
     */
    #[DataProvider('postDistinto')]
    public function test_dos_posts_distintos_no_comparten_huella(string $a, string $b): void
    {
        $this->assertNotSame(
            Publicaciones::huella($a),
            Publicaciones::huella($b),
            "«{$a}» y «{$b}» son posts distintos",
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function postDistinto(): array
    {
        return [
            // El identificador de YouTube va en la query: una regla que borre la
            // query entera fundiría todos los vídeos del canal en uno.
            'youtube v=' => ['https://youtube.com/watch?v=abc', 'https://youtube.com/watch?v=xyz'],
            // Instagram distingue mayúsculas en el id del post.
            'mayusculas en la ruta' => ['https://instagram.com/p/AbC', 'https://instagram.com/p/abc'],
            'ruta distinta' => ['https://instagram.com/p/ABC', 'https://instagram.com/p/ABD'],
            'red distinta' => ['https://instagram.com/p/ABC', 'https://tiktok.com/p/ABC'],
        ];
    }

    public function test_la_huella_mide_64_como_exige_la_base(): void
    {
        $this->assertSame(64, mb_strlen(Publicaciones::huella('https://instagram.com/p/ABC')));
    }

    public function test_una_url_sin_host_no_revienta(): void
    {
        // No es un caso teórico: alguien pega «instagram.com/p/ABC» sin esquema.
        // Aquí no se decide si vale —eso es del veto—, sólo que no explota.
        $this->assertNotSame('', Publicaciones::huella('instagram.com/p/ABC'));
    }

    // ---------------------------------------------------------------- la red

    public function test_la_red_se_deduce_del_enlace(): void
    {
        $this->assertSame('instagram', Publicaciones::redDe('https://www.instagram.com/p/ABC')?->code);
        $this->assertSame('tiktok', Publicaciones::redDe('https://tiktok.com/@ana/video/1')?->code);
        $this->assertSame('youtube', Publicaciones::redDe('https://youtu.be/abc')?->code);
    }

    public function test_un_enlace_de_una_red_desconocida_no_se_reconoce(): void
    {
        $this->assertNull(Publicaciones::redDe('https://mi-blog.example/post/1'));
    }

    // ------------------------------------------------------------- los vetos

    public function test_sin_aprobar_no_se_registra(): void
    {
        [$entregable] = $this->entregado();

        $motivos = Publicaciones::vetoParaPublicar($entregable, 'https://instagram.com/p/ABC', null);

        $this->assertCount(1, $motivos);
        $this->assertStringContainsString('Solo se publica lo aprobado', $motivos[0]);
    }

    public function test_la_red_tiene_que_ser_la_del_brief(): void
    {
        [$entregable] = $this->aprobado();

        $motivos = Publicaciones::vetoParaPublicar($entregable, 'https://tiktok.com/@ana/video/1', null);

        $this->assertCount(1, $motivos);
        $this->assertStringContainsString('El brief pide', $motivos[0]);
    }

    public function test_un_enlace_que_no_es_de_ninguna_red_se_rechaza(): void
    {
        [$entregable] = $this->aprobado();

        $motivos = Publicaciones::vetoParaPublicar($entregable, 'https://mi-blog.example/post/1', null);

        $this->assertNotSame([], $motivos);
        $this->assertStringContainsString('ninguna red conocida', $motivos[0]);
    }

    public function test_un_post_del_futuro_se_rechaza(): void
    {
        [$entregable] = $this->aprobado();

        $motivos = Publicaciones::vetoParaPublicar(
            $entregable, 'https://instagram.com/p/ABC', now()->addDay()->toDateTimeString(),
        );

        $this->assertNotSame([], $motivos);
        $this->assertStringContainsString('en el futuro', $motivos[0]);
    }

    public function test_un_post_ya_reclamado_por_otro_se_rechaza(): void
    {
        [$primero, $usuario, $participacionId] = $this->aprobado(['quantity' => 2]);
        Publicaciones::reportar($primero, 'https://instagram.com/p/ABC', null, (int) $usuario->id, null);
        $segundo = $this->aprobarSegundo($participacionId, $usuario);

        // Con parámetros de medición encima: la huella lo caza igual, que es el
        // único motivo por el que `uq_pub_fingerprint` sirve de algo.
        $motivos = Publicaciones::vetoParaPublicar(
            $segundo, 'https://www.instagram.com/p/ABC/?utm_source=ig', null,
        );

        $this->assertNotSame([], $motivos);
        $this->assertStringContainsString('ya esta registrado en otro entregable', $motivos[0]);
    }

    public function test_un_entregable_no_tiene_dos_posts(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        Publicaciones::reportar($entregable, 'https://instagram.com/p/ABC', null, (int) $usuario->id, null);

        $motivos = Publicaciones::vetoParaPublicar(
            Revisiones::entregable($entregable->uuid), 'https://instagram.com/p/OTRO', null,
        );

        $this->assertCount(1, $motivos);
        $this->assertStringContainsString('ya tiene un post registrado', $motivos[0]);
    }

    // ------------------------------------------------------------- registrar

    public function test_registrar_guarda_la_version_aprobada_y_cierra_el_entregable(): void
    {
        [$entregable, $usuario] = $this->aprobado();

        Publicaciones::reportar($entregable, 'https://www.instagram.com/p/ABC/', null, (int) $usuario->id, '203.0.113.9');

        $fila = DB::table('publications')->where('deliverable_id', $entregable->id)->first();

        $this->assertSame((int) $entregable->approved_version_id, (int) $fila->deliverable_version_id);
        $this->assertSame((int) $usuario->id, (int) $fila->reported_by_user_id);
        $this->assertSame(Publicaciones::huella('https://instagram.com/p/ABC'), $fila->url_fingerprint);
        // La URL se guarda TAL CUAL: la huella es para comparar, no para
        // sustituir lo que alguien pegó. Si hay que abrirlo, se abre el original.
        $this->assertSame('https://www.instagram.com/p/ABC/', $fila->url);
        $this->assertSame('published', (string) DB::table('deliverables')
            ->where('id', $entregable->id)->value('status'));
    }

    /**
     * `published_at` y `created_at` salen del **mismo** instante.
     *
     * `ck_pub_published_no_futuro` compara las dos, y dos `now()` separados
     * pueden caer a los dos lados de un milisegundo. Es `T-39` otra vez, y aquí
     * sería un rechazo aleatorio en la cara del creador.
     */
    public function test_reportar_sin_fecha_no_choca_con_el_check(): void
    {
        [$entregable, $usuario] = $this->aprobado();

        Publicaciones::reportar($entregable, 'https://instagram.com/p/ABC', null, (int) $usuario->id, null);

        $fila = DB::table('publications')->where('deliverable_id', $entregable->id)->first();

        $this->assertSame((string) $fila->created_at, (string) $fila->published_at);
    }

    public function test_publicado_ya_no_admite_ni_versiones_ni_veredictos(): void
    {
        [$entregable, $usuario] = $this->aprobado();
        Publicaciones::reportar($entregable, 'https://instagram.com/p/ABC', null, (int) $usuario->id, null);

        $motivos = Revisiones::vetoParaRevisar(Revisiones::entregable($entregable->uuid), [
            'outcome' => Revisiones::CAMBIOS, 'reviewer_side' => 'platform', 'comments' => 'Me lo repense.',
        ]);

        $this->assertNotSame([], $motivos);
        $this->assertStringContainsString('ya no admite veredictos', $motivos[0]);
    }

    public function test_la_base_impide_publicar_sin_aprobar(): void
    {
        [$entregable, $usuario] = $this->entregado();
        $version = Revisiones::ultimaVersion((int) $entregable->id);

        $this->expectException(QueryException::class);

        // fixture-invalido-a-proposito: `tg_pub_version_aprobada` la rechaza.
        DB::table('publications')->insert([
            'uuid' => (string) Str::uuid(), 'deliverable_id' => $entregable->id,
            'deliverable_version_id' => $version->id,
            'platform_id' => DB::table('platforms')->where('code', 'instagram')->value('id'),
            'url' => 'https://instagram.com/p/ABC',
            'url_fingerprint' => Publicaciones::huella('https://instagram.com/p/ABC'),
            'published_at' => now(), 'status' => 'reported',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // --------------------------------------------------------------- pantallas

    public function test_el_creador_pega_su_enlace_desde_el_portal(): void
    {
        [$entregable, , $participacionId] = $this->aprobado();
        $creador = $this->cuentaDelCreadorDe($participacionId);

        $this->actingAs($creador)->get(route('entregas.mias'))
            ->assertOk()->assertSee('Ya está publicado', false);

        $this->actingAs($creador)
            ->post(route('entregas.publicar', $entregable->uuid), ['url' => 'https://instagram.com/p/ABC'])
            ->assertRedirect(route('entregas.mias'));

        $this->assertSame(1, DB::table('publications')->where('deliverable_id', $entregable->id)->count());
    }

    public function test_un_creador_no_publica_lo_de_otro(): void
    {
        [$entregable, , $participacionId] = $this->aprobado();
        $this->cuentaDelCreadorDe($participacionId);
        $otro = $this->cuentaDeUnCreadorCualquiera();

        $this->actingAs($otro)
            ->post(route('entregas.publicar', $entregable->uuid), ['url' => 'https://instagram.com/p/ABC'])
            ->assertNotFound();
    }

    public function test_el_equipo_lo_registra_por_el_creador(): void
    {
        [$entregable] = $this->aprobado();
        $uuidCampana = (string) DB::table('campaigns')->where('id', $this->campanaId)->value('uuid');

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post(route('campanas.entregables.publicar', [$uuidCampana, $entregable->id]),
                ['url' => 'https://instagram.com/p/ABC'])
            ->assertRedirect();

        $this->assertSame(1, DB::table('publications')->where('deliverable_id', $entregable->id)->count());
    }

    public function test_registrar_por_el_creador_exige_su_permiso(): void
    {
        [$entregable] = $this->aprobado();
        $uuidCampana = (string) DB::table('campaigns')->where('id', $this->campanaId)->value('uuid');

        $this->actingAs($this->usuarioCon('finance'))
            ->post(route('campanas.entregables.publicar', [$uuidCampana, $entregable->id]),
                ['url' => 'https://instagram.com/p/ABC'])
            ->assertForbidden();
    }

    public function test_la_pantalla_avisa_con_palabras_cuando_la_red_no_es_la_del_brief(): void
    {
        [$entregable, , $participacionId] = $this->aprobado();
        $creador = $this->cuentaDelCreadorDe($participacionId);

        $this->actingAs($creador)
            ->post(route('entregas.publicar', $entregable->uuid), ['url' => 'https://tiktok.com/@ana/video/1'])
            ->assertRedirect()
            ->assertSessionHas('aviso');

        $this->assertSame(0, DB::table('publications')->count());
    }

    // ------------------------------------------------------------------ apoyo

    /** @return array{0: object, 1: User, 2: int} */
    private function entregado(array $requisito = []): array
    {
        Queue::fake();
        // El formato por omisión de `ConFixturas` es de Instagram, que es lo que
        // pide el brief en estas pruebas.
        $this->requisitoDe($this->campanaId, array_merge(['quantity' => 1], $requisito));
        $participacionId = $this->aceptado(500.0);
        $usuario = $this->usuarioCon('campaign_manager');

        $entregable = DB::table('deliverables')->where('campaign_creator_id', $participacionId)
            ->orderBy('sequence_number')->first();
        Entregables::entregar($entregable, ['external_url' => 'https://a.example/1'], null, (int) $usuario->id, null);

        return [Revisiones::entregable((string) $entregable->uuid), $usuario, $participacionId];
    }

    /** @return array{0: object, 1: User, 2: int} */
    private function aprobado(array $requisito = []): array
    {
        [$entregable, $usuario, $participacionId] = $this->entregado($requisito);

        Revisiones::emitir($entregable, Revisiones::ultimaVersion((int) $entregable->id), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        return [Revisiones::entregable((string) $entregable->uuid), $usuario, $participacionId];
    }

    private function aprobarSegundo(int $participacionId, object $usuario): object
    {
        $segundo = DB::table('deliverables')->where('campaign_creator_id', $participacionId)
            ->where('sequence_number', 2)->first();
        Entregables::entregar($segundo, ['external_url' => 'https://a.example/2'], null, (int) $usuario->id, null);
        $fresco = Revisiones::entregable((string) $segundo->uuid);
        Revisiones::emitir($fresco, Revisiones::ultimaVersion((int) $segundo->id), [
            'outcome' => Revisiones::APROBAR, 'reviewer_side' => 'platform',
        ], (int) $usuario->id, null);

        return Revisiones::entregable((string) $segundo->uuid);
    }

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

    private function cuentaDeUnCreadorCualquiera(): User
    {
        $otroId = $this->creadorActivo();
        $usuario = User::factory()->create(['user_type' => 'creator']);

        DB::table('creators')->where('id', $otroId)->update(['user_id' => $usuario->id]);
        DB::table('role_user')->insert([
            'user_id' => $usuario->id,
            'role_id' => DB::table('roles')->where('code', 'creator')->value('id'),
            'assigned_at' => now(),
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
