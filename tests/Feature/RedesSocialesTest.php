<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Creator\Services\CoherenciaMetrica;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * Cuentas sociales y coherencia de métricas (iteración 3.7).
 *
 * Las dos que cargan con el peso:
 *
 * - `test_verificar_deja_constancia_del_metodo_y_de_quien` — `H-05`. Antes
 *   bastaba la fecha: una cuenta podía quedar verificada sin constancia de cómo
 *   ni de quién.
 * - `test_una_metrica_nueva_nace_sin_revisar` — `H-06`. Antes el valor por
 *   defecto afirmaba «no es anómala» sin que ningún chequeo se hubiera
 *   ejecutado.
 */
final class RedesSocialesTest extends TestCase
{
    use ConFixturas;
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
        $this->creadorId = $this->creadorPendiente(['uuid' => $this->uuid]);
    }

    /** @return array<string, mixed> */
    private function alta(array $cambios = []): array
    {
        return array_merge([
            'platform_id' => DB::table('platforms')->orderBy('id')->value('id'),
            'handle' => 'anatorres',
            'profile_url' => 'https://instagram.com/anatorres',
        ], $cambios);
    }

    private function cuenta(array $cambios = []): int
    {
        return (int) DB::table('social_accounts')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'creator_id' => $this->creadorId,
            'platform_id' => DB::table('platforms')->orderBy('id')->value('id'),
            'handle' => 'anatorres', 'profile_url' => 'https://instagram.com/anatorres',
            'verification_status' => 'unverified',
            'is_primary' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $cambios));
    }

    // ------------------------------------------------------------------ alta

    public function test_una_cuenta_nace_sin_verificar(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/backoffice/creadores/{$this->uuid}/redes", $this->alta())
            ->assertRedirect(route('creadores.redes', $this->uuid));

        $cuenta = DB::table('social_accounts')->where('creator_id', $this->creadorId)->first();
        $this->assertSame('unverified', $cuenta->verification_status);
        $this->assertNull($cuenta->verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'social_account.created']);
    }

    public function test_el_identificador_va_sin_arroba(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/backoffice/creadores/{$this->uuid}/redes", $this->alta(['handle' => '@anatorres']))
            ->assertSessionHasErrors('handle');
    }

    /**
     * `BR-CREATOR-003`. `uq_social_accounts_verified` solo salta al VERIFICAR,
     * así que sin este aviso el operador daría de alta la cuenta, intentaría
     * verificarla y solo entonces descubriría el choque, con el trabajo hecho.
     */
    public function test_avisa_si_la_cuenta_ya_esta_verificada_por_otro_creador(): void
    {
        $otro = $this->creadorPendiente(['uuid' => (string) Str::uuid(), 'first_name' => 'Otro', 'last_name' => 'Creador', 'display_name' => 'otro', 'birth_date' => '1990-01-01', 'email' => 'otro@ejemplo.test', 'document_number' => '40000002']);

        $this->cuenta([
            'creator_id' => $otro,
            'verification_status' => 'verified', 'verified_at' => now(),
            'verification_method' => 'bio_code',
            'verified_by_user_id' => $this->usuarioCon('admin')->id,
        ]);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/backoffice/creadores/{$this->uuid}/redes", $this->alta())
            ->assertSessionHas('aviso');

        $this->assertSame(1, DB::table('social_accounts')->count());
    }

    // ----------------------------------------------------------- verificación

    public function test_verificar_deja_constancia_del_metodo_y_de_quien(): void
    {
        $id = $this->cuenta();
        $revisor = $this->usuarioCon('admin');

        $this->actingAs($revisor)
            ->post("/backoffice/creadores/{$this->uuid}/redes/{$id}/verificar", [
                'verification_method' => 'bio_code',
                'nota' => 'Codigo LS-4471 visible en la biografia',
                'confirma_comprobacion' => '1',
            ])
            ->assertRedirect(route('creadores.redes', $this->uuid));

        $cuenta = DB::table('social_accounts')->where('id', $id)->first();
        $this->assertSame('verified', $cuenta->verification_status);
        $this->assertSame('bio_code', $cuenta->verification_method);
        $this->assertSame((int) $revisor->id, (int) $cuenta->verified_by_user_id);
        $this->assertNotNull($cuenta->verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'social_account.verified']);
    }

    public function test_no_se_verifica_sin_decir_como(): void
    {
        $id = $this->cuenta();

        $this->actingAs($this->usuarioCon('admin'))
            ->post("/backoffice/creadores/{$this->uuid}/redes/{$id}/verificar", ['confirma_comprobacion' => '1'])
            ->assertSessionHasErrors('verification_method');

        $this->assertSame('unverified', DB::table('social_accounts')->where('id', $id)->value('verification_status'));
    }

    /** `oauth` no se ofrece: no está implementado y marcarlo sería mentir. */
    public function test_no_se_puede_declarar_una_verificacion_por_oauth(): void
    {
        $id = $this->cuenta();

        $this->actingAs($this->usuarioCon('admin'))
            ->post("/backoffice/creadores/{$this->uuid}/redes/{$id}/verificar", [
                'verification_method' => 'oauth',
                'confirma_comprobacion' => '1',
            ])
            ->assertSessionHasErrors('verification_method');
    }

    public function test_quien_no_verifica_no_verifica(): void
    {
        $id = $this->cuenta();

        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->post("/backoffice/creadores/{$this->uuid}/redes/{$id}/verificar", [
                'verification_method' => 'bio_code', 'confirma_comprobacion' => '1',
            ])
            ->assertForbidden();
    }

    // ------------------------------------------------------------- coherencia

    public function test_una_metrica_normal_pasa_los_chequeos(): void
    {
        $id = $this->cuenta();

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/backoffice/creadores/{$this->uuid}/redes/{$id}/metrica", [
                'source' => 'self_declared',
                'captured_at' => now()->format('Y-m-d\TH:i'),
                'followers' => 12000,
                'engagement_rate' => '3.4',
            ])
            ->assertRedirect();

        $snap = DB::table('social_account_snapshots')->where('social_account_id', $id)->first();
        $this->assertSame(CoherenciaMetrica::LIMPIA, $snap->coherence_status);
        $this->assertNull($snap->anomaly_note);
    }

    /** `BR-CREATOR-004`: se marca para revisión humana, **nunca se rechaza**. */
    public function test_un_salto_absurdo_se_marca_pero_se_guarda(): void
    {
        $id = $this->cuenta();

        DB::table('social_account_snapshots')->insert([
            'social_account_id' => $id, 'captured_at' => now()->subDays(3),
            'source' => 'self_declared', 'followers' => 10000,
            'coherence_status' => CoherenciaMetrica::LIMPIA,
        ]);

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/backoffice/creadores/{$this->uuid}/redes/{$id}/metrica", [
                'source' => 'self_declared',
                'captured_at' => now()->format('Y-m-d\TH:i'),
                'followers' => 90000,
            ])
            ->assertSessionHas('aviso');

        $snap = DB::table('social_account_snapshots')
            ->where('social_account_id', $id)->orderByDesc('id')->first();

        // Guardada, no rechazada. Esa es la regla.
        $this->assertSame(90000, (int) $snap->followers);
        $this->assertSame(CoherenciaMetrica::ANOMALA, $snap->coherence_status);
        $this->assertStringContainsString('Seguidores', (string) $snap->anomaly_note);
    }

    public function test_un_engagement_imposible_se_marca(): void
    {
        $id = $this->cuenta();

        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/backoffice/creadores/{$this->uuid}/redes/{$id}/metrica", [
                'source' => 'self_declared',
                'captured_at' => now()->format('Y-m-d\TH:i'),
                'engagement_rate' => '87',
            ])
            ->assertSessionHas('aviso');

        $snap = DB::table('social_account_snapshots')->where('social_account_id', $id)->first();
        $this->assertSame(CoherenciaMetrica::ANOMALA, $snap->coherence_status);
        $this->assertStringContainsString('Engagement', (string) $snap->anomaly_note);
    }

    /**
     * LA PRUEBA DE `H-06`. Una fila escrita sin pasar por el servicio no puede
     * afirmar estar limpia: el estado de partida es «sin revisar».
     */
    public function test_una_metrica_nueva_nace_sin_revisar(): void
    {
        $id = $this->cuenta();

        DB::table('social_account_snapshots')->insert([
            'social_account_id' => $id, 'captured_at' => now(),
            'source' => 'import', 'followers' => 5000,
        ]);

        $this->assertSame(
            CoherenciaMetrica::PENDIENTE,
            DB::table('social_account_snapshots')->where('social_account_id', $id)->value('coherence_status'),
            'Una metrica que nadie ha comprobado no puede decir que esta limpia.',
        );
    }

    /** La ventana se mide ENTRE CAPTURAS, no contra hoy. */
    public function test_un_salto_viejo_queda_fuera_de_la_ventana(): void
    {
        $id = $this->cuenta();

        DB::table('social_account_snapshots')->insert([
            'social_account_id' => $id, 'captured_at' => now()->subDays(400),
            'source' => 'import', 'followers' => 1000,
            'coherence_status' => CoherenciaMetrica::LIMPIA,
        ]);

        // Un año después los seguidores se multiplicaron por 90: eso es
        // crecimiento, no una anomalia de un dia para otro.
        $veredicto = CoherenciaMetrica::evaluar($id, [
            'followers' => 90000,
            'captured_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame(CoherenciaMetrica::LIMPIA, $veredicto['estado']);
    }
}
