<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\Services\Costos;
use App\Shared\Auth\Permisos;
use App\Shared\Files\Vigilante;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Quién puede mirar un archivo (iteración 9.15).
 *
 * ### El problema que resuelve
 *
 * `Almacen` guardaba desde la Fase 3 y **nadie servía**: no existía ni una ruta
 * que devolviera un archivo. La pantalla de conciliación aceptaba el comprobante
 * y la de gastos decía «con comprobante», y no había forma de abrirlo. Una
 * evidencia que nadie puede mirar no es una evidencia (`T-67`).
 *
 * ### Los dos escalones
 *
 * `file.view` dice que se puede **pedir** un archivo; el `Vigilante` dice
 * **cuál**. Estas pruebas fijan el segundo, que es el que impide que un creador
 * abra el documento de identidad de otro.
 */
final class ArchivosTest extends TestCase
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

    // ------------------------------------------------- se niega por omisión

    /**
     * **La que más importa.** Un propósito sin regla registrada no se abre.
     *
     * Un archivo nuevo cuyo autor olvidó declarar quién puede verlo se queda
     * cerrado, y no abierto a todos.
     */
    public function test_un_proposito_sin_regla_no_se_abre_ni_para_el_admin(): void
    {
        $archivoId = $this->archivoDeIdentidad('inventado.pdf');
        DB::table('files')->where('id', $archivoId)->update(['purpose' => 'proposito_sin_regla']);
        $uuid = (string) DB::table('files')->where('id', $archivoId)->value('uuid');

        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('archivos.ver', $uuid))
            ->assertForbidden();
    }

    /** Y los seis propósitos que la aplicación guarda de verdad SÍ tienen regla. */
    public function test_todo_proposito_que_se_guarda_tiene_su_regla(): void
    {
        $conRegla = Vigilante::propositosConRegla();

        foreach (['identity_document', 'terms_evidence', 'deliverable',
            'publication_evidence', 'payout_proof', 'campaign_cost'] as $proposito) {
            $this->assertContains($proposito, $conRegla,
                "el proposito «{$proposito}» se guarda y nadie declaro quien puede verlo");
        }
    }

    // ----------------------------------------------------- lo del creador

    public function test_el_creador_abre_su_documento_de_identidad(): void
    {
        [$creadorId, $usuario] = $this->creadorConCuenta();

        $archivoId = (int) DB::table('creators')->where('id', $creadorId)
            ->value('identity_document_file_id');

        $this->assertNotSame(0, $archivoId, 'el creador activo tiene documento archivado');

        $this->actingAs($usuario)
            ->get(route('archivos.ver', $this->conBytes($archivoId)))
            ->assertOk();
    }

    /** **Y el de OTRO creador, nunca.** */
    public function test_un_creador_no_abre_el_documento_de_otro(): void
    {
        [, $usuario] = $this->creadorConCuenta();
        $otro = $this->creadorActivo();

        $uuid = (string) DB::table('files as f')
            ->join('creators as c', 'c.identity_document_file_id', '=', 'f.id')
            ->where('c.id', $otro)->value('f.uuid');

        $this->actingAs($usuario)->get(route('archivos.ver', $uuid))->assertForbidden();
    }

    // ------------------------------------------------------- lo de finanzas

    public function test_finanzas_abre_un_comprobante_de_gasto_y_quien_lleva_campanas_tambien(): void
    {
        $uuid = $this->comprobanteDeGasto();

        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('archivos.ver', $uuid))->assertOk();
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get(route('archivos.ver', $uuid))->assertOk();
    }

    public function test_un_creador_no_abre_un_comprobante_de_gasto(): void
    {
        $uuid = $this->comprobanteDeGasto();
        [, $usuario] = $this->creadorConCuenta();

        $this->actingAs($usuario)->get(route('archivos.ver', $uuid))->assertForbidden();
    }

    // ---------------------------------------------------------- la bitácora

    /**
     * Los sensibles dejan rastro; los demás no.
     *
     * Anotar cada apertura de una captura convertiría la bitácora en ruido que
     * nadie lee — y una bitácora que nadie lee no protege nada.
     */
    public function test_abrir_un_documento_de_identidad_deja_rastro(): void
    {
        [$creadorId, $usuario] = $this->creadorConCuenta();
        $archivoId = (int) DB::table('creators')->where('id', $creadorId)
            ->value('identity_document_file_id');

        $this->actingAs($usuario)
            ->get(route('archivos.ver', $this->conBytes($archivoId)))
            ->assertOk();

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'file.viewed')->count());
        $this->assertTrue(Vigilante::esSensible('identity_document'));
    }

    public function test_abrir_un_comprobante_de_gasto_no_llena_la_bitacora(): void
    {
        $uuid = $this->comprobanteDeGasto();

        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('archivos.ver', $uuid))->assertOk();

        $this->assertSame(0, DB::table('audit_logs')->where('action', 'file.viewed')->count());
        $this->assertFalse(Vigilante::esSensible('campaign_cost'));
    }

    // ------------------------------------------------------------ los bordes

    public function test_un_archivo_que_no_existe_da_404(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->get(route('archivos.ver', (string) Str::uuid()))
            ->assertNotFound();
    }

    /** La fila dice que ya no debe existir, y la fila manda. */
    public function test_un_archivo_purgado_no_se_sirve(): void
    {
        $uuid = $this->comprobanteDeGasto();
        DB::table('files')->where('uuid', $uuid)->update(['purged_at' => now()]);

        $this->actingAs($this->usuarioCon('finance'))
            ->get(route('archivos.ver', $uuid))
            ->assertNotFound();
    }

    public function test_sin_sesion_no_se_abre_nada(): void
    {
        $uuid = $this->comprobanteDeGasto();

        $this->get(route('archivos.ver', $uuid))->assertRedirect();
    }

    // -------------------------------------------------------------- apoyo

    /**
     * Escribe en el disco el archivo al que apunta la fila.
     *
     * `ConFixturas::archivoDeIdentidad()` crea la FILA y nada más, porque hasta
     * `9.15` nadie servía archivos y con la fila bastaba. Ahora el controlador
     * comprueba que el archivo esté de verdad —una fila que apunta a un archivo
     * que no existe es la «evidencia fantasma» que `Almacen` intenta impedir— y
     * sin esto la prueba mediría ese 404 en vez del permiso.
     */
    private function conBytes(int $archivoId): string
    {
        $archivo = DB::table('files')->where('id', $archivoId)->first(['uuid', 'disk', 'path']);
        Storage::disk((string) $archivo->disk)->put((string) $archivo->path, 'contenido de prueba');

        return (string) $archivo->uuid;
    }

    /** @return array{0:int,1:User} */
    private function creadorConCuenta(): array
    {
        $creadorId = $this->creadorActivo();
        $usuario = $this->usuarioCon('creator');
        DB::table('creators')->where('id', $creadorId)->update(['user_id' => $usuario->id]);

        return [$creadorId, $usuario];
    }

    private function comprobanteDeGasto(): string
    {
        $clienteId = (int) DB::table('client_organizations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'commercial_name' => 'ACME', 'client_code' => 'ACME-01',
            'country_id' => (int) DB::table('countries')->where('iso2', 'PE')->value('id'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $marcaId = (int) DB::table('client_brands')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_organization_id' => $clienteId,
            'name' => 'Marca ACME', 'slug' => 'marca-acme', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $campanaId = $this->campanaDe($clienteId, $marcaId);
        $archivoId = $this->archivoDeIdentidad('factura.pdf');
        DB::table('files')->where('id', $archivoId)->update(['purpose' => 'campaign_cost']);

        Costos::anotar($campanaId, 'product', 'Producto', 100.0,
            (string) DB::table('currencies')->value('code'), now()->toDateString(),
            $archivoId, (int) $this->usuarioCon('admin')->id);

        return $this->conBytes($archivoId);
    }
}
