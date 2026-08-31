<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\Terminos;
use App\Modules\Creator\Services\CompletitudOperativa;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Database\Seeders\TerminosBaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Los términos, editables desde el admin (iteración 9.16).
 *
 * ### Qué cambia de criterio
 *
 * En `3.5` no había términos sembrados a propósito, y la consecuencia era que
 * **sin correr un comando no se activaba ningún creador**. Eso convertía una
 * configuración en un bloqueo. Ahora el sistema arranca con un texto de partida
 * que **dice que no está revisado**, y todo se cambia desde la pantalla.
 *
 * Lo que estas pruebas fijan:
 *
 * 1. El sistema **arranca operable**: hay términos publicados tras sembrar.
 * 2. Un borrador se edita; **una publicada no** — hay firmas apuntando a ella.
 * 3. El **cambio menor** no obliga a reaceptar, y el de fondo sí.
 * 4. El estado de revisión legal **informa y no bloquea**.
 */
final class TerminosTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Queue::fake();
    }

    // ------------------------------------------------------- el arranque

    /** **La que importa.** El sistema arranca con términos publicados. */
    public function test_el_sistema_arranca_con_terminos_publicados(): void
    {
        $this->sembrarBase();

        $vigente = Terminos::vigente(Terminos::codigo());

        $this->assertNotNull($vigente, 'sin terminos no se activa ningun creador');
        $this->assertNotNull($vigente->published_at);
        $this->assertSame('sin_revisar', $vigente->review_status);
    }

    /**
     * Y el texto sembrado es el de verdad, no el de respaldo.
     *
     * La primera versión de la semilla leía de `docs/`, que no viaja junto a la
     * aplicación en todos los entornos: sembraba 192 caracteres en vez de los
     * términos completos **sin fallar y sin avisar**. Se vio midiendo el largo.
     */
    public function test_el_texto_base_no_es_el_de_respaldo(): void
    {
        $this->assertFileExists(database_path('seeders/textos/terminos-creador-2026.1.md'));

        $this->sembrarBase();
        $vigente = Terminos::vigente(Terminos::codigo());

        $this->assertGreaterThan(5000, mb_strlen((string) $vigente->body));
        $this->assertStringContainsString('30 días', (string) $vigente->body);
    }

    /** Sembrar dos veces no duplica ni pisa lo que alguien haya editado. */
    public function test_sembrar_dos_veces_no_pisa_nada(): void
    {
        $this->sembrarBase();
        $this->sembrarBase();

        $this->assertSame(1, DB::table('terms_versions')->where('code', Terminos::codigo())->count());
    }

    // --------------------------------------------------- borrar y editar

    public function test_un_borrador_se_edita_cuantas_veces_haga_falta(): void
    {
        $autor = (int) $this->usuarioCon('admin')->id;
        $uuid = Terminos::crearBorrador('creator_terms', '2030.1', 'Términos',
            'Texto uno.', 'creator', $autor);

        Terminos::guardarBorrador($uuid, 'Términos', 'Texto dos.', $autor);
        Terminos::guardarBorrador($uuid, 'Términos', 'Texto tres.', $autor);

        $version = Terminos::porUuid($uuid);
        $this->assertSame('Texto tres.', $version->body);
        $this->assertSame(hash('sha256', 'Texto tres.'), $version->content_sha256);
        $this->assertNull($version->published_at, 'sigue siendo borrador');
    }

    public function test_una_publicada_no_se_edita(): void
    {
        $autor = (int) $this->usuarioCon('admin')->id;
        $uuid = Terminos::crearBorrador('creator_terms', '2030.1', 'Términos',
            'Texto publicado.', 'creator', $autor);
        Terminos::publicar($uuid, 'fondo', null, $autor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no se reescribe');

        Terminos::guardarBorrador($uuid, 'Términos', 'Otro texto.', $autor);
    }

    /** Y no lo impide la pantalla: lo impide la base. */
    public function test_la_base_tampoco_deja_reescribir_una_publicada(): void
    {
        $autor = (int) $this->usuarioCon('admin')->id;
        $uuid = Terminos::crearBorrador('creator_terms', '2030.1', 'Términos',
            'Texto publicado.', 'creator', $autor);
        Terminos::publicar($uuid, 'fondo', null, $autor);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no se reescribe');

        // fixture-invalido-a-proposito: la prueba afirma que la base lo rechaza.
        DB::table('terms_versions')->where('uuid', $uuid)->update(['body' => 'cambiado por detras']);
    }

    // -------------------------------------------------------- publicar

    public function test_publicar_cierra_la_anterior_el_dia_antes(): void
    {
        $autor = (int) $this->usuarioCon('admin')->id;
        $primera = Terminos::crearBorrador('creator_terms', '2030.1', 'T', 'Uno.', 'creator', $autor);
        Terminos::publicar($primera, 'fondo', '2030-01-01', $autor);

        $segunda = Terminos::crearBorrador('creator_terms', '2030.2', 'T', 'Dos.', 'creator', $autor);
        Terminos::publicar($segunda, 'fondo', '2030-06-01', $autor);

        $this->assertSame('2030-05-31',
            (string) DB::table('terms_versions')->where('uuid', $primera)->value('effective_to'));
        $this->assertSame('2030.2', Terminos::vigente('creator_terms')->version);
    }

    /** La primera versión no reemplaza a nadie: no hay nada que declarar. */
    public function test_la_primera_version_no_declara_tipo_de_cambio(): void
    {
        $autor = (int) $this->usuarioCon('admin')->id;
        $uuid = Terminos::crearBorrador('creator_terms', '2030.1', 'T', 'Uno.', 'creator', $autor);
        Terminos::publicar($uuid, 'fondo', null, $autor);

        $this->assertNull(Terminos::porUuid($uuid)->change_type);
    }

    // ------------------------------------------------- el cambio menor

    /**
     * **La decisión de esta iteración.** Una errata no deja a todos incompletos.
     */
    public function test_un_cambio_menor_no_obliga_a_reaceptar(): void
    {
        $autor = (int) $this->usuarioCon('admin')->id;
        $creadorId = $this->creadorActivo();

        $primera = Terminos::crearBorrador('creator_terms', '2030.1', 'T', 'Uno.', 'creator', $autor);
        Terminos::publicar($primera, 'fondo', '2030-01-01', $autor);
        $this->aceptar($creadorId, $primera, $autor);

        $this->assertTrue($this->cumpleTerminos($creadorId));

        $segunda = Terminos::crearBorrador('creator_terms', '2030.2', 'T', 'Uno, con la coma.', 'creator', $autor);
        Terminos::publicar($segunda, 'menor', '2030-06-01', $autor);

        $this->assertTrue($this->cumpleTerminos($creadorId),
            'un cambio menor no puede dejar a todos los creadores incompletos');
    }

    public function test_un_cambio_de_fondo_si_obliga_a_reaceptar(): void
    {
        $autor = (int) $this->usuarioCon('admin')->id;
        $creadorId = $this->creadorActivo();

        $primera = Terminos::crearBorrador('creator_terms', '2030.1', 'T', 'Uno.', 'creator', $autor);
        Terminos::publicar($primera, 'fondo', '2030-01-01', $autor);
        $this->aceptar($creadorId, $primera, $autor);

        $segunda = Terminos::crearBorrador('creator_terms', '2030.2', 'T', 'Otra cosa distinta.', 'creator', $autor);
        Terminos::publicar($segunda, 'fondo', '2030-06-01', $autor);

        $this->assertFalse($this->cumpleTerminos($creadorId));
    }

    /** Y un cambio de fondo **corta la cadena** de menores anteriores. */
    public function test_el_fondo_corta_la_cadena_de_menores(): void
    {
        $autor = (int) $this->usuarioCon('admin')->id;
        $creadorId = $this->creadorActivo();

        $a = Terminos::crearBorrador('creator_terms', '2030.1', 'T', 'A.', 'creator', $autor);
        Terminos::publicar($a, 'fondo', '2030-01-01', $autor);
        $this->aceptar($creadorId, $a, $autor);

        $b = Terminos::crearBorrador('creator_terms', '2030.2', 'T', 'B.', 'creator', $autor);
        Terminos::publicar($b, 'menor', '2030-03-01', $autor);
        $this->assertTrue($this->cumpleTerminos($creadorId));

        $c = Terminos::crearBorrador('creator_terms', '2030.3', 'T', 'C, y esto es otra cosa.', 'creator', $autor);
        Terminos::publicar($c, 'fondo', '2030-06-01', $autor);

        $this->assertFalse($this->cumpleTerminos($creadorId));
        $this->assertCount(1, Terminos::versionesQueValen('creator_terms'));
    }

    /** Un borrador no cuenta como vigente: nadie tiene que aceptar lo que se escribe. */
    public function test_un_borrador_no_es_la_version_vigente(): void
    {
        $autor = (int) $this->usuarioCon('admin')->id;
        $primera = Terminos::crearBorrador('creator_terms', '2030.1', 'T', 'Uno.', 'creator', $autor);
        Terminos::publicar($primera, 'fondo', '2030-01-01', $autor);
        Terminos::crearBorrador('creator_terms', '2030.2', 'T', 'Escribiendose.', 'creator', $autor);

        $this->assertSame('2030.1', Terminos::vigente('creator_terms')->version);
    }

    // -------------------------------------------------- revisión legal

    public function test_el_estado_de_revision_avisa_y_no_bloquea(): void
    {
        $this->sembrarBase();

        $avisos = Terminos::avisos();
        $this->assertSame('rojo', $avisos[0]['nivel']);

        // Y aun asi el requisito de terminos se puede cumplir: informa, no cierra.
        $creadorId = $this->creadorActivo();
        $vigente = Terminos::vigente(Terminos::codigo());
        $this->aceptar($creadorId, (string) $vigente->uuid, (int) $this->usuarioCon('admin')->id);

        $this->assertTrue($this->cumpleTerminos($creadorId));
    }

    public function test_marcar_revisado_apaga_el_aviso_rojo(): void
    {
        $this->sembrarBase();
        $vigente = Terminos::vigente(Terminos::codigo());

        Terminos::marcarRevision((string) $vigente->uuid, 'revisado',
            'Revisado por el estudio X.', (int) $this->usuarioCon('admin')->id);

        $niveles = array_column(Terminos::avisos(), 'nivel');
        $this->assertNotContains('rojo', $niveles);
    }

    public function test_sin_ninguna_version_el_aviso_lo_dice(): void
    {
        $avisos = Terminos::avisos();

        $this->assertSame('rojo', $avisos[0]['nivel']);
        $this->assertStringContainsString('ningún creador puede activarse', $avisos[0]['texto']);
    }

    // --------------------------------------------------------- pantalla

    public function test_quien_configura_la_plataforma_entra(): void
    {
        $this->sembrarBase();

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('terminos.index'))
            ->assertOk()
            ->assertSee('Versiones de', false);
    }

    public function test_un_creador_no_entra(): void
    {
        $this->actingAs($this->usuarioCon('creator'))
            ->get(route('terminos.index'))
            ->assertForbidden();
    }

    public function test_desde_la_pantalla_se_crea_edita_y_publica(): void
    {
        $admin = $this->usuarioCon('admin');

        $this->actingAs($admin)->post(route('terminos.store'), [
            'code' => 'creator_terms', 'version' => '2030.1',
            'title' => 'Términos', 'audience' => 'creator', 'body' => 'Primer texto.',
        ])->assertRedirect();

        $uuid = (string) DB::table('terms_versions')->where('version', '2030.1')->value('uuid');

        $this->actingAs($admin)->put(route('terminos.update', $uuid), [
            'title' => 'Términos', 'body' => 'Texto corregido.',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('terminos.publicar', $uuid), [
            'change_type' => 'fondo',
        ])->assertRedirect();

        $vigente = Terminos::vigente('creator_terms');
        $this->assertSame('Texto corregido.', $vigente->body);
        $this->assertNotNull($vigente->published_at);
    }

    // ------------------------------------------------------------ apoyo

    /**
     * Siembra el texto base con un usuario delante.
     *
     * `TerminosBaseSeeder` publica sólo si hay alguien a quien atribuirlo
     * —`ck_terms_publicada` exige responsable— y sin eso deja un borrador. En
     * producción `UsuarioAdminSeeder` va antes; aquí hay que decirlo.
     */
    private function sembrarBase(): void
    {
        $this->usuarioCon('admin');
        $this->seed(TerminosBaseSeeder::class);
    }

    private function aceptar(int $creadorId, string $uuidVersion, int $autorId): void
    {
        DB::table('terms_acceptances')->insert([
            'uuid' => (string) Str::uuid(),
            'terms_version_id' => (int) Terminos::porUuid($uuidVersion)->id,
            'subject_type' => 'creator', 'subject_id' => $creadorId,
            // `ck_terms_acceptances_backing`: si no lo hizo el interesado desde
            // su sesion, hay una persona que lo registro y un archivo que lo
            // respalda. Sin eso, «acepto» es la palabra de quien tecleo.
            'channel' => 'email', 'recorded_by_user_id' => $autorId,
            'evidence_file_id' => $this->archivoDeIdentidad('aceptacion.pdf'),
            'evidence_note' => 'Aceptado por correo.',
            'accepted_at' => now(), 'created_at' => now(),
        ]);
    }

    private function cumpleTerminos(int $creadorId): bool
    {
        foreach (CompletitudOperativa::revisar($creadorId) as $requisito) {
            if ($requisito->codigo === CompletitudOperativa::TERMINOS) {
                return $requisito->cumple;
            }
        }

        return false;
    }
}
