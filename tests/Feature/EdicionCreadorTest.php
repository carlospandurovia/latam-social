<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Primera pantalla de escritura del proyecto, y su rastro en la bitácora
 * (iteración 3.2).
 */
final class EdicionCreadorTest extends TestCase
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
        DB::table('creators')->insert([
            'uuid' => $this->uuid,
            'first_name' => 'Ana', 'last_name' => 'Torres', 'display_name' => 'anatorres',
            'birth_date' => '1998-05-12', 'email' => 'ana@ejemplo.test',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'document_country_code' => 'PE', 'document_type' => 'DNI', 'document_number' => '40000001',
            'preferred_currency_code' => 'PEN', 'status' => 'active',
            'payment_term_days' => 30, 'locale' => 'es', 'timezone' => 'America/Lima',
            'created_at' => now(), 'updated_at' => now(),
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
    private function formulario(array $cambios = []): array
    {
        return array_merge([
            'display_name' => 'anatorres',
            'phone' => null,
            'city' => null,
            'payment_term_days' => 30,
            'preferred_currency_code' => 'PEN',
            'locale' => 'es',
            'timezone' => 'America/Lima',
        ], $cambios);
    }

    // ------------------------------------------------------------ autorización

    public function test_ver_no_es_poder_editar(): void
    {
        $usuario = $this->usuarioCon('content_reviewer');   // tiene creator.view

        $this->actingAs($usuario)->get("/creadores/{$this->uuid}")->assertOk();
        $this->actingAs($usuario)->get("/creadores/{$this->uuid}/editar")->assertForbidden();
        $this->actingAs($usuario)
            ->put("/creadores/{$this->uuid}", $this->formulario(['city' => 'Cusco']))
            ->assertForbidden();
    }

    public function test_quien_puede_gestionar_entra_al_formulario(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get("/creadores/{$this->uuid}/editar")
            ->assertOk();
    }

    // ------------------------------------------------------------- la escritura

    public function test_guarda_los_cambios_y_los_deja_en_la_bitacora(): void
    {
        $usuario = $this->usuarioCon('admin');

        $this->actingAs($usuario)
            ->put("/creadores/{$this->uuid}", $this->formulario([
                'city' => 'Cusco',
                'payment_term_days' => 45,
            ]))
            ->assertRedirect(route('creadores.show', $this->uuid));

        $creador = DB::table('creators')->where('uuid', $this->uuid)->first();
        $this->assertSame('Cusco', $creador->city);
        $this->assertSame(45, (int) $creador->payment_term_days);

        $entrada = DB::table('audit_logs')->where('action', 'creator.updated')->first();
        $this->assertNotNull($entrada, 'La edición no dejó rastro en la bitácora.');
        $this->assertSame('creator', $entrada->entity_type);
        $this->assertStringContainsString($usuario->email, (string) $entrada->actor_label);

        // Solo lo que se movió, con antes y después.
        $cambios = json_decode((string) $entrada->changes, true);
        $this->assertSame(['city', 'payment_term_days'], array_keys($cambios));
        $this->assertSame('Cusco', $cambios['city']['despues']);
        $this->assertNull($cambios['city']['antes']);
        $this->assertSame(30, (int) $cambios['payment_term_days']['antes']);
    }

    /** Una entrada que dice «no cambió nada» es ruido donde luego nadie busca. */
    public function test_sin_cambios_no_ensucia_la_bitacora(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->put("/creadores/{$this->uuid}", $this->formulario())
            ->assertRedirect();

        $this->assertSame(0, DB::table('audit_logs')->where('action', 'creator.updated')->count());
    }

    /**
     * LA PRUEBA QUE IMPORTA.
     *
     * Enviar campos que el formulario no ofrece es trivial: basta una petición a
     * mano. Si el controlador guardara todo lo que llega, cualquiera con permiso
     * de edición podría cambiarse el documento, el correo o pasar su propio
     * estado a `active`. `validated()` solo devuelve lo declarado en las reglas,
     * y esta prueba lo fija para que nadie lo "simplifique" luego.
     */
    public function test_los_campos_de_identidad_se_ignoran_aunque_se_envien(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->put("/creadores/{$this->uuid}", $this->formulario([
                'city' => 'Arequipa',
                'email' => 'secuestrada@atacante.test',
                'document_number' => '99999999',
                'first_name' => 'Otra',
                'status' => 'blacklisted',
            ]))
            ->assertRedirect();

        $creador = DB::table('creators')->where('uuid', $this->uuid)->first();

        $this->assertSame('Arequipa', $creador->city, 'El campo legítimo sí debía cambiar.');
        $this->assertSame('ana@ejemplo.test', $creador->email);
        $this->assertSame('40000001', $creador->document_number);
        $this->assertSame('Ana', $creador->first_name);
        $this->assertSame('active', $creador->status);
    }

    public function test_rechaza_un_plazo_de_pago_imposible(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->put("/creadores/{$this->uuid}", $this->formulario(['payment_term_days' => 400]))
            ->assertSessionHasErrors('payment_term_days');

        $this->assertSame(
            30,
            (int) DB::table('creators')->where('uuid', $this->uuid)->value('payment_term_days'),
        );
    }

    public function test_rechaza_una_moneda_que_no_existe(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->put("/creadores/{$this->uuid}", $this->formulario(['preferred_currency_code' => 'XXX']))
            ->assertSessionHasErrors('preferred_currency_code');
    }

    // -------------------------------------------------- la bitácora es evidencia

    /**
     * Regla del cliente: «el registro de auditoría no debe ser fácilmente
     * modificable desde la aplicación». Lo impide la base, no la aplicación.
     */
    public function test_una_entrada_de_bitacora_no_se_puede_reescribir(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->put("/creadores/{$this->uuid}", $this->formulario(['city' => 'Trujillo']));

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('action', 'creator.updated')->update(['action' => 'nada']);
    }

    public function test_una_entrada_de_bitacora_no_se_puede_borrar(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->put("/creadores/{$this->uuid}", $this->formulario(['city' => 'Piura']));

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('action', 'creator.updated')->delete();
    }
}
