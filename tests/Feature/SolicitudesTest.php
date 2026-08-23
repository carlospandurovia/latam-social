<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La bandeja de solicitudes: por dónde entra un creador (iteración 3.4).
 */
final class SolicitudesTest extends TestCase
{
    use RefreshDatabase;

    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->uuid = (string) Str::uuid();
        DB::table('creator_applications')->insert([
            'uuid' => $this->uuid,
            'full_name' => 'Ana Torres', 'email' => 'ana@ejemplo.test', 'phone' => '+51999',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'source' => 'landing', 'status' => 'submitted',
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function usuarioCon(string $rol): User
    {
        $usuario = User::factory()->create();
        DB::table('role_user')->insert([
            'user_id' => $usuario->id,
            'role_id' => DB::table('roles')->where('code', $rol)->value('id'),
            'assigned_at' => now(),
        ]);
        Permisos::olvidar((int) $usuario->id);

        return $usuario;
    }

    /** @return array<string, mixed> */
    private function alta(array $cambios = []): array
    {
        return array_merge([
            'first_name' => 'Ana', 'last_name' => 'Torres', 'display_name' => 'anatorres',
            'birth_date' => '1998-05-12',
            'document_country_code' => 'PE', 'document_type' => 'DNI', 'document_number' => '40000001',
            'preferred_currency_code' => 'PEN', 'payment_term_days' => 30,
            'confirma_revision' => '1',
        ], $cambios);
    }

    // ------------------------------------------------------------ autorización

    public function test_solo_quien_puede_aprobar_ve_la_bandeja(): void
    {
        $this->actingAs($this->usuarioCon('content_reviewer'))->get('/solicitudes')->assertForbidden();
        $this->actingAs($this->usuarioCon('finance'))->get('/solicitudes')->assertForbidden();
        $this->actingAs($this->usuarioCon('admin'))->get('/solicitudes')->assertOk();
    }

    // ------------------------------------------------------------- la aprobación

    /**
     * LA REGLA QUE MÁS FÁCIL SE ROMPE.
     *
     * `BR-CREATOR-006`: aprobar la solicitud NO activa al creador. Falta
     * identidad verificada, red social, datos fiscales y medio de pago. Un
     * creador «activo» al que no se le puede pagar es el error caro.
     */
    public function test_aprobar_crea_al_creador_en_pendiente_no_en_activo(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post("/solicitudes/{$this->uuid}/aprobar", $this->alta())
            ->assertRedirect();

        $creador = DB::table('creators')->where('email', 'ana@ejemplo.test')->first();

        $this->assertNotNull($creador);
        $this->assertSame('pending', $creador->status);
        $this->assertNull($creador->activated_at);

        $solicitud = DB::table('creator_applications')->where('uuid', $this->uuid)->first();
        $this->assertSame('approved', $solicitud->status);
        $this->assertSame((int) $creador->id, (int) $solicitud->creator_id);
        $this->assertNotNull($solicitud->reviewed_by_user_id);
    }

    public function test_la_aprobacion_deja_dos_entradas_en_la_bitacora(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post("/solicitudes/{$this->uuid}/aprobar", $this->alta());

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'creator_application.approved')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'creator.created')->count());
    }

    /** BR-CREATOR-003: no pueden coexistir dos creadores con el mismo documento. */
    public function test_no_aprueba_si_el_documento_ya_existe(): void
    {
        DB::table('creators')->insert([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Otra', 'last_name' => 'Persona', 'display_name' => 'otra',
            'birth_date' => '1990-01-01', 'email' => 'otra@ejemplo.test',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'document_country_code' => 'PE', 'document_type' => 'DNI', 'document_number' => '40000001',
            // 'pending' basta: la comprobacion de duplicados de
            // `SolicitudesController` mira `pending`, `active` y `suspended` por
            // igual. Poner 'active' obligaria a inventar fecha de activacion e
            // identidad verificada para algo que esta prueba no comprueba.
            'preferred_currency_code' => 'PEN', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->usuarioCon('admin'))
            ->post("/solicitudes/{$this->uuid}/aprobar", $this->alta())
            ->assertSessionHas('choque');

        $this->assertSame('submitted', DB::table('creator_applications')->where('uuid', $this->uuid)->value('status'));
        $this->assertSame(1, DB::table('creators')->count());
    }

    /**
     * La casilla dice que el revisor MIRÓ los duplicados. No le da permiso para
     * crear una colisión: el servidor vuelve a comprobar.
     */
    public function test_la_casilla_de_confirmacion_no_salta_la_comprobacion(): void
    {
        DB::table('creators')->insert([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ana', 'last_name' => 'Torres', 'display_name' => 'ana2',
            'birth_date' => '1998-05-12', 'email' => 'ana@ejemplo.test',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'document_country_code' => 'PE', 'document_type' => 'CE', 'document_number' => '999',
            'preferred_currency_code' => 'PEN', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->usuarioCon('admin'))
            ->post("/solicitudes/{$this->uuid}/aprobar", $this->alta(['confirma_revision' => '1']))
            ->assertSessionHas('choque');

        $this->assertSame(1, DB::table('creators')->count());
    }

    public function test_sin_confirmar_la_revision_no_se_aprueba(): void
    {
        $datos = $this->alta();
        unset($datos['confirma_revision']);

        $this->actingAs($this->usuarioCon('admin'))
            ->post("/solicitudes/{$this->uuid}/aprobar", $datos)
            ->assertSessionHasErrors('confirma_revision');

        $this->assertSame(0, DB::table('creators')->count());
    }

    public function test_rechaza_una_fecha_de_nacimiento_futura(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post("/solicitudes/{$this->uuid}/aprobar", $this->alta(['birth_date' => now()->addYear()->toDateString()]))
            ->assertSessionHasErrors('birth_date');
    }

    /** Una solicitud resuelta no se vuelve a resolver. */
    public function test_una_solicitud_ya_resuelta_no_se_aprueba_dos_veces(): void
    {
        $usuario = $this->usuarioCon('admin');

        $this->actingAs($usuario)->post("/solicitudes/{$this->uuid}/aprobar", $this->alta());
        $this->actingAs($usuario)->post("/solicitudes/{$this->uuid}/aprobar", $this->alta(['document_number' => '777']));

        $this->assertSame(1, DB::table('creators')->count());
    }

    // ------------------------------------------------------------- el rechazo

    public function test_rechazar_exige_una_explicacion(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post("/solicitudes/{$this->uuid}/rechazar", ['motivo' => 'rejected', 'rejection_note' => 'corto'])
            ->assertSessionHasErrors('rejection_note');

        $this->assertSame('submitted', DB::table('creator_applications')->where('uuid', $this->uuid)->value('status'));
    }

    public function test_rechazar_con_motivo_lo_deja_registrado(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post("/solicitudes/{$this->uuid}/rechazar", [
                'motivo' => 'duplicate',
                'rejection_note' => 'Ya existe una cuenta con este documento desde marzo.',
            ])
            ->assertRedirect(route('solicitudes.index'));

        $solicitud = DB::table('creator_applications')->where('uuid', $this->uuid)->first();
        $this->assertSame('duplicate', $solicitud->status);
        $this->assertStringContainsString('marzo', (string) $solicitud->rejection_note);
        $this->assertNotNull($solicitud->reviewed_at);

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'creator_application.duplicate')->count());
        $this->assertSame(0, DB::table('creators')->count());
    }
}
