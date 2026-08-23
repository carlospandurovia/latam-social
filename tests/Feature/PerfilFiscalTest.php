<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Creator\Services\CompletitudOperativa;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El perfil tributario del creador (iteración 3.6).
 *
 * Dos pruebas cargan con el peso de la iteración:
 *
 * - `test_no_aprueba_quien_capturo` — la separación de funciones. La base la
 *   impone con `ck_ctp_segregation`, pero hasta 3.6 se apagaba sola si nadie
 *   decía quién había capturado el perfil (H-03).
 * - `test_para_un_menor_el_perfil_del_creador_no_cuenta` — el titular. Antes de
 *   3.6 el modelo no sabía de quién eran los datos fiscales (H-01), así que
 *   cualquier perfil aprobado daba por buena la condición.
 */
final class PerfilFiscalTest extends TestCase
{
    use RefreshDatabase;

    private string $uuid;

    private int $creadorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->uuid = (string) Str::uuid();
        $this->creadorId = (int) DB::table('creators')->insertGetId([
            'uuid' => $this->uuid,
            'first_name' => 'Ana', 'last_name' => 'Torres', 'display_name' => 'anatorres',
            'birth_date' => '1998-05-12', 'email' => 'ana@ejemplo.test',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'document_country_code' => 'PE', 'document_type' => 'DNI', 'document_number' => '40000001',
            'status' => 'pending', 'payment_term_days' => 30, 'preferred_currency_code' => 'PEN',
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
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'tax_regime_code' => 'RER',
            'tax_id_type' => 'RUC',
            'tax_id_number' => '10400000012',
            'issued_document_type' => 'recibo_honorarios',
            'holder_type' => 'creator',
            'valid_from' => '2026-01-01',
        ], $cambios);
    }

    private function capturar(User $quien, array $cambios = []): int
    {
        $this->actingAs($quien)->post("/creadores/{$this->uuid}/fiscal", $this->formulario($cambios));

        return (int) DB::table('creator_tax_profiles')
            ->where('creator_id', $this->creadorId)->orderByDesc('id')->value('id');
    }

    // ------------------------------------------------------------ autorización

    public function test_los_datos_fiscales_no_son_para_cualquiera(): void
    {
        // DEC-053: finanzas es el único rol no administrador con datos fiscales.
        $this->actingAs($this->usuarioCon('campaign_manager'))->get("/creadores/{$this->uuid}/fiscal")->assertForbidden();
        $this->actingAs($this->usuarioCon('content_reviewer'))->get("/creadores/{$this->uuid}/fiscal")->assertForbidden();
        $this->actingAs($this->usuarioCon('finance'))->get("/creadores/{$this->uuid}/fiscal")->assertOk();
        $this->actingAs($this->usuarioCon('admin'))->get("/creadores/{$this->uuid}/fiscal")->assertOk();
    }

    public function test_quien_no_gestiona_no_captura(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/creadores/{$this->uuid}/fiscal", $this->formulario())
            ->assertForbidden();

        $this->assertDatabaseCount('creator_tax_profiles', 0);
    }

    // --------------------------------------------------------------- captura

    public function test_capturar_deja_el_perfil_pendiente_y_la_retencion_sin_decidir(): void
    {
        $id = $this->capturar($this->usuarioCon('finance'));

        $perfil = DB::table('creator_tax_profiles')->where('id', $id)->first();

        $this->assertSame('pending', $perfil->status);
        // DEC-048: nace sin decidir. No es un hueco, es el control.
        $this->assertSame('pending_review', $perfil->withholding_status);
        $this->assertNotNull($perfil->created_by_user_id, 'H-03: sin capturador no hay separacion de funciones.');
        $this->assertNull($perfil->approved_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator_tax_profile.created']);
    }

    public function test_no_se_admite_el_tutor_de_otro_creador(): void
    {
        $otro = DB::table('creators')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Otro', 'last_name' => 'Creador', 'display_name' => 'otro',
            'birth_date' => '1990-01-01', 'email' => 'otro@ejemplo.test',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'document_country_code' => 'PE', 'document_type' => 'DNI', 'document_number' => '40000002',
            'status' => 'pending', 'preferred_currency_code' => 'PEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ajeno = $this->tutorDe((int) $otro);

        // La clave foránea comprueba que el tutor EXISTE, no de quién es. Sin la
        // comprobación del servidor, un id ajeno colaría los datos fiscales de
        // otra persona en este creador.
        $this->actingAs($this->usuarioCon('finance'))
            ->post("/creadores/{$this->uuid}/fiscal", $this->formulario([
                'holder_type' => 'guardian',
                'holder_guardian_id' => $ajeno,
            ]))
            ->assertSessionHas('aviso');

        $this->assertDatabaseCount('creator_tax_profiles', 0);
    }

    // -------------------------------------------------------------- aprobación

    public function test_no_se_aprueba_sin_decidir_la_retencion(): void
    {
        $id = $this->capturar($this->usuarioCon('finance'));

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/creadores/{$this->uuid}/fiscal/{$id}/aprobar", ['confirma_revision' => '1'])
            ->assertSessionHasErrors('withholding_status');

        $this->assertSame('pending', DB::table('creator_tax_profiles')->where('id', $id)->value('status'));
    }

    public function test_si_se_retiene_hacen_falta_tasa_y_norma(): void
    {
        $id = $this->capturar($this->usuarioCon('finance'));

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/creadores/{$this->uuid}/fiscal/{$id}/aprobar", [
                'withholding_status' => 'applies',
                'confirma_revision' => '1',
            ])
            ->assertSessionHasErrors(['withholding_rate', 'withholding_basis']);
    }

    public function test_aprobar_congela_tasa_y_norma(): void
    {
        $id = $this->capturar($this->usuarioCon('finance'));

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/creadores/{$this->uuid}/fiscal/{$id}/aprobar", [
                'withholding_status' => 'applies',
                'withholding_rate' => '30',
                'withholding_basis' => 'LIR art. 54 inc. f - por confirmar con contador',
                'confirma_revision' => '1',
            ])
            ->assertRedirect(route('creadores.fiscal', $this->uuid));

        $perfil = DB::table('creator_tax_profiles')->where('id', $id)->first();
        $this->assertSame('approved', $perfil->status);
        $this->assertSame('applies', $perfil->withholding_status);
        $this->assertSame(30.0, (float) $perfil->withholding_rate);
        $this->assertStringContainsString('LIR art. 54', (string) $perfil->withholding_basis);
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator_tax_profile.approved']);
    }

    /**
     * LA PRUEBA DE LA SEPARACIÓN DE FUNCIONES.
     *
     * `ck_ctp_segregation` lo impide en la base, pero un error 45000 en pantalla
     * no le dice al operador qué hizo mal. Aquí se comprueba que el servidor lo
     * para antes y con un mensaje.
     */
    public function test_no_aprueba_quien_capturo(): void
    {
        $mismo = $this->usuarioCon('finance');
        $id = $this->capturar($mismo);

        $this->actingAs($mismo)
            ->post("/creadores/{$this->uuid}/fiscal/{$id}/aprobar", [
                'withholding_status' => 'not_applicable',
                'confirma_revision' => '1',
            ])
            ->assertSessionHas('aviso');

        $this->assertSame('pending', DB::table('creator_tax_profiles')->where('id', $id)->value('status'));
    }

    /** BR-CREATOR-007: el cambio no surte efecto hasta que se aprueba, y al hacerlo cierra el anterior. */
    public function test_aprobar_uno_nuevo_cierra_el_anterior(): void
    {
        $capturador = $this->usuarioCon('finance');
        $aprobador = $this->usuarioCon('finance');

        $primero = $this->capturar($capturador);
        $this->actingAs($aprobador)->post("/creadores/{$this->uuid}/fiscal/{$primero}/aprobar", [
            'withholding_status' => 'not_applicable', 'confirma_revision' => '1',
        ]);

        $segundo = $this->capturar($capturador, [
            'tax_regime_code' => 'GENERAL', 'tax_id_number' => '10400000099',
            'valid_from' => '2026-07-01',
        ]);
        $this->actingAs($aprobador)->post("/creadores/{$this->uuid}/fiscal/{$segundo}/aprobar", [
            'withholding_status' => 'not_applicable', 'confirma_revision' => '1',
        ]);

        $viejo = DB::table('creator_tax_profiles')->where('id', $primero)->first();
        $nuevo = DB::table('creator_tax_profiles')->where('id', $segundo)->first();

        $this->assertSame('superseded', $viejo->status);
        $this->assertNotNull($viejo->valid_to, 'Un perfil cerrado sin fecha de cierre no se puede explicar.');
        // El anterior se cierra CUANDO EMPIEZA EL NUEVO. Cerrarlo «hoy» dejaba
        // los dos periodos solapados, y entonces «que regimen aplicaba el 1 de
        // mayo» tiene dos respuestas.
        $this->assertSame('2026-07-01', substr((string) $viejo->valid_to, 0, 10));
        $this->assertSame('approved', $nuevo->status);
        $this->assertNull($nuevo->valid_to);

        // `uq_ctp_current`: uno y solo uno vigente por creador y pais.
        $this->assertSame(1, DB::table('creator_tax_profiles')
            ->where('creator_id', $this->creadorId)->where('status', 'approved')->whereNull('valid_to')->count());
    }

    public function test_rechazar_exige_un_motivo(): void
    {
        $id = $this->capturar($this->usuarioCon('finance'));

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/creadores/{$this->uuid}/fiscal/{$id}/rechazar", ['rejection_note' => 'no'])
            ->assertSessionHasErrors('rejection_note');

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/creadores/{$this->uuid}/fiscal/{$id}/rechazar", [
                'rejection_note' => 'El RUC esta de baja en SUNAT desde marzo.',
            ])
            ->assertRedirect();

        $this->assertSame('rejected', DB::table('creator_tax_profiles')->where('id', $id)->value('status'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator_tax_profile.rejected']);
    }

    /**
     * REGRESIÓN. La primera versión escribía `approved_by_user_id` también al
     * rechazar, y `ck_ctp_segregation` compara esa columna con el capturador sea
     * cual sea el estado: al capturador le explotaba un error 4025 en la cara al
     * intentar retirar una captura equivocada suya.
     *
     * La restricción existe para impedir la AUTOAPROBACIÓN, no para impedir que
     * alguien corrija su propio error.
     */
    public function test_el_capturador_puede_retirar_su_propia_captura(): void
    {
        $mismo = $this->usuarioCon('finance');
        $id = $this->capturar($mismo);

        $this->actingAs($mismo)
            ->post("/creadores/{$this->uuid}/fiscal/{$id}/rechazar", [
                'rejection_note' => 'Me equivoque de RUC al teclearlo.',
            ])
            ->assertRedirect();

        $perfil = DB::table('creator_tax_profiles')->where('id', $id)->first();
        $this->assertSame('rejected', $perfil->status);
        // Quien rechazo vive en la bitacora, no en una columna que se llama
        // «aprobado por».
        $this->assertNull($perfil->approved_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator_tax_profile.rejected']);
    }

    /** BR-CREATOR-009: sobre un creador anonimizado no se registran datos personales. */
    public function test_no_se_capturan_datos_fiscales_de_un_creador_anonimizado(): void
    {
        DB::table('creators')->where('id', $this->creadorId)->update(['anonymized_at' => now()]);

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/creadores/{$this->uuid}/fiscal", $this->formulario())
            ->assertSessionHas('aviso');

        $this->assertDatabaseCount('creator_tax_profiles', 0);
    }

    // ------------------------------------------------------------------ menores

    /**
     * LA PRUEBA DEL TITULAR (H-01).
     *
     * `BR-CREATOR-013`: para un menor, el perfil exigido es el **del tutor**,
     * que es quien emite el comprobante. Un perfil aprobado a nombre del propio
     * menor no cumple la condición, aunque esté impecable por lo demás.
     */
    public function test_para_un_menor_el_perfil_del_creador_no_cuenta(): void
    {
        DB::table('creators')->where('id', $this->creadorId)
            ->update(['birth_date' => now()->subYears(15)->toDateString()]);
        $tutorId = $this->tutorDe($this->creadorId);

        $capturador = $this->usuarioCon('finance');
        $aprobador = $this->usuarioCon('finance');

        // A nombre del propio menor: aprobado y vigente, pero no sirve.
        $id = $this->capturar($capturador);
        $this->actingAs($aprobador)->post("/creadores/{$this->uuid}/fiscal/{$id}/aprobar", [
            'withholding_status' => 'not_applicable', 'confirma_revision' => '1',
        ]);

        $this->assertSame('approved', DB::table('creator_tax_profiles')->where('id', $id)->value('status'));
        $this->assertFalse($this->cumpleFiscal(), 'Un perfil a nombre del menor no puede dar por buena la condicion fiscal.');

        // A nombre del tutor: ahora sí.
        $nuevo = $this->capturar($capturador, [
            'tax_id_number' => '10999999999',
            'holder_type' => 'guardian',
            'holder_guardian_id' => $tutorId,
        ]);
        $this->actingAs($aprobador)->post("/creadores/{$this->uuid}/fiscal/{$nuevo}/aprobar", [
            'withholding_status' => 'not_applicable', 'confirma_revision' => '1',
        ]);

        $this->assertTrue($this->cumpleFiscal());
    }

    // ------------------------------------------------------------------- apoyo

    private function cumpleFiscal(): bool
    {
        foreach (CompletitudOperativa::revisar($this->creadorId) as $r) {
            if ($r->codigo === CompletitudOperativa::FISCAL) {
                return $r->cumple;
            }
        }

        return false;
    }

    private function tutorDe(int $creadorId): int
    {
        $archivo = DB::table('files')->insertGetId([
            'uuid' => (string) Str::uuid(), 'disk' => 'local', 'path' => 'pruebas/tutela.pdf',
            'original_name' => 'tutela.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 512,
            'checksum_sha256' => hash('sha256', 'tutela'.$creadorId), 'visibility' => 'private',
            'purpose' => 'guardian_authorization', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('creator_guardians')->insertGetId([
            'creator_id' => $creadorId, 'full_name' => 'Rosa Torres',
            'relationship' => 'mother', 'document_country_code' => 'PE',
            'document_type' => 'DNI', 'document_number' => '0900000'.$creadorId,
            'email' => 'rosa'.$creadorId.'@ejemplo.test',
            'authorization_file_id' => $archivo,
            'proof_of_relationship_file_id' => $archivo,
            'status' => 'active', 'valid_from' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
