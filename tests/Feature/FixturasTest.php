<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * El apoyo de las pruebas, probado (`T-13`).
 *
 * Un fixture compartido que se rompe rompe **todas** las pruebas a la vez, y el
 * síntoma es una pantalla de errores que no señala a la causa. Estas pruebas son
 * baratas y valen justo por eso: si una se pone roja, el problema está aquí y no
 * en las quince pruebas que se apoyan en esto.
 */
final class FixturasTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
    }

    public function test_un_usuario_con_rol_lo_tiene(): void
    {
        $usuario = $this->usuarioCon('finance');

        $this->assertSame(1, DB::table('role_user')->where('user_id', $usuario->id)->count());
    }

    /** El caso que catorce de las dieciséis copias no sabían expresar. */
    public function test_un_usuario_sin_rol_es_un_caso_legitimo(): void
    {
        $usuario = $this->usuarioCon(null);

        $this->assertSame(0, DB::table('role_user')->where('user_id', $usuario->id)->count());
    }

    /** Un rol mal escrito acusa al rol, no a la tabla. */
    public function test_un_rol_que_no_existe_lo_dice(): void
    {
        try {
            $this->usuarioCon('rol_inventado');
            $this->fail('tenia que haberse quejado');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('rol_inventado', $e->getMessage());
        }
    }

    public function test_un_creador_pendiente_es_el_minimo(): void
    {
        $id = $this->creadorPendiente();

        $fila = DB::table('creators')->where('id', $id)->first();

        $this->assertSame('pending', $fila->status);
        $this->assertNull($fila->activated_at, 'un pendiente no esta activado');
        $this->assertNull($fila->identity_verified_at);
    }

    /**
     * **La prueba que justifica el archivo.**
     *
     * Cuatro reglas a la vez, y la cuarta es una foránea: un
     * `identity_document_file_id` inventado da un `1452` que no dice nada de lo
     * que falta. Si esto pasa, nadie más tiene que descubrirlas a base de `4025`.
     */
    public function test_un_creador_activo_trae_su_evidencia(): void
    {
        $id = $this->creadorActivo();

        $fila = DB::table('creators')->where('id', $id)->first();

        $this->assertSame('active', $fila->status);
        $this->assertNotNull($fila->activated_at, 'ck_creators_activation');
        $this->assertNotNull($fila->identity_verified_at, 'ck_creators_active_identity');
        $this->assertNotNull($fila->identity_verified_by_user_id, 'ck_creators_identity_evidence');
        $this->assertNotNull($fila->identity_document_file_id, 'ck_creators_identity_evidence');

        // Y el archivo EXISTE: la foranea no se cumple con un id cualquiera.
        $this->assertSame(1, DB::table('files')
            ->where('id', $fila->identity_document_file_id)->count());
    }

    /** Dos creadores en la misma prueba no chocan entre sí. */
    public function test_dos_creadores_conviven(): void
    {
        $uno = $this->creadorPendiente();
        $dos = $this->creadorPendiente(['document_number' => '40000002', 'email' => 'otra@ejemplo.test']);

        $this->assertNotSame($uno, $dos);
        $this->assertSame(2, DB::table('creators')->count());
    }

    /**
     * El cierre es EL DÍA ANTES. Era la undécima copia del defecto de `H-16`,
     * escrita a mano y fuera del alcance de la puerta `vigencias`.
     */
    public function test_publicar_terminos_cierra_la_version_anterior_la_vispera(): void
    {
        $this->publicarTerminos('v1-prueba', '2026-01-01');
        $this->publicarTerminos('v2-prueba', '2026-07-01');

        $anterior = DB::table('terms_versions')->where('version', 'v1-prueba')->first();
        $nueva = DB::table('terms_versions')->where('version', 'v2-prueba')->first();

        $this->assertSame('2026-06-30', (string) $anterior->effective_to, 'la vispera, no el mismo dia');
        $this->assertSame('2026-07-01', (string) $nueva->effective_from);
        $this->assertNull($nueva->effective_to);
    }

    /** Un PDF con bytes de verdad: `ck_files_size` rechaza el cero. */
    public function test_el_pdf_de_prueba_no_esta_vacio(): void
    {
        $this->assertGreaterThan(0, $this->pdfDePrueba()->getSize());
    }
}
