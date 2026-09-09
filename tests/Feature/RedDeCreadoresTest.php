<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Services\FiltrosDeResumen;
use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Resumen;
use App\Shared\Auth\Permisos;
use Carbon\CarbonImmutable;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * `D-9`: la red de creadores.
 *
 * ### La que fija la decisión
 *
 * `test_una_invitacion_cuenta_en_el_periodo_en_que_se_envio`. Si contara al
 * responderse, la tasa de aceptación de un periodo **cambiaría según el día en
 * que se mire**, y un número que se mueve solo no sirve para decidir nada. Es
 * la misma regla que los prospectos en `D-7` (`DEC-336`), y por eso está
 * escrita dos veces: una regla que vive en un solo consumidor es una regla que
 * el segundo no tiene.
 */
final class RedDeCreadoresTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();
        Marca::olvidar();
        Queue::fake();
    }

    public function test_el_reparto_saca_los_seis_estados_aunque_esten_a_cero(): void
    {
        $this->creadorActivo();

        $red = Resumen::redDeCreadores(FiltrosDeResumen::porDefecto());

        self::assertCount(6, $red['filas']);
        self::assertSame(
            ['pending', 'active', 'suspended', 'inactive', 'rejected', 'blacklisted'],
            array_column($red['filas'], 'clave'),
        );
        self::assertSame(1, $red['activos']);
    }

    public function test_un_creador_anonimizado_deja_de_contar(): void
    {
        $creadorId = $this->creadorActivo();

        self::assertSame(1, Resumen::redDeCreadores(FiltrosDeResumen::porDefecto())['total']);

        // Anonimizar no borra la fila --es informacion que no se destruye-- pero
        // la saca de los conteos: contar personas que ya no estan seria contar
        // dos veces la misma decision de borrado.
        DB::table('creators')->where('id', $creadorId)->update(['anonymized_at' => now()]);

        self::assertSame(0, Resumen::redDeCreadores(FiltrosDeResumen::porDefecto())['total']);
    }

    public function test_la_verificacion_se_mira_sobre_los_activos(): void
    {
        // Uno activo y verificado --`creadorActivo()` verifica-- y otro
        // rechazado sin verificar: el segundo no falta, asi que no baja la cuenta.
        $this->creadorActivo();
        $this->creadorPendiente(['status' => 'rejected']);

        $red = Resumen::redDeCreadores(FiltrosDeResumen::porDefecto());

        self::assertSame(1, $red['activos']);
        self::assertSame(1, $red['verificados']);
        self::assertSame(2, $red['total'], 'El rechazado sigue estando en el reparto.');
    }

    public function test_una_invitacion_cuenta_en_el_periodo_en_que_se_envio(): void
    {
        // Enviada hace 10 dias --dentro del periodo-- y respondida HOY.
        $this->invitacion(dias: 10, respuesta: 'accepted');
        // Enviada hace 10 dias y sin contestar.
        $this->invitacion(dias: 10);
        // Enviada hace 100 dias: fuera del periodo, no entra en la tasa.
        $this->invitacion(dias: 100, respuesta: 'accepted');

        $red = Resumen::redDeCreadores(FiltrosDeResumen::porDefecto());

        // 1 de 2 dentro del periodo. Con la vieja dentro serian 2 de 3.
        self::assertSame(50.0, $red['aceptacion']);
    }

    public function test_sin_invitaciones_no_se_inventa_una_tasa(): void
    {
        self::assertNull(Resumen::redDeCreadores(FiltrosDeResumen::porDefecto())['aceptacion']);
    }

    public function test_quien_no_puede_ver_creadores_no_recibe_el_bloque(): void
    {
        $sinPermisos = $this->usuarioCon(null);

        self::assertFalse($sinPermisos->can('creator.view'));

        $html = (string) $this->actingAs($sinPermisos)->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringNotContainsString('En qué estado está la red', $html);
    }

    public function test_el_panel_lo_pinta_y_dice_lo_que_falta(): void
    {
        $this->creadorActivo();

        $html = (string) $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get('/backoffice/panel')->assertOk()->getContent();

        self::assertStringContainsString('En qué estado está la red', $html);
        // El hueco se dice, no se disimula (`DEC-190`). Si alguien borra la
        // frase, esta prueba lo cuenta.
        self::assertStringContainsString('no hay tabla de evaluación', $html);
    }

    // ---------------------------------------------------------------- apoyo

    private function invitacion(int $dias, ?string $respuesta = null): int
    {
        $cuando = CarbonImmutable::now()->subDays($dias);

        $clienteId = DB::table('client_organizations')->value('id') ?? DB::table('client_organizations')
            ->insertGetId([
                'uuid' => (string) Str::uuid(), 'client_code' => 'RED-01',
                'commercial_name' => 'Cliente', 'status' => 'active',
                'country_id' => (int) DB::table('countries')->where('iso2', 'PE')->value('id'),
                'created_at' => now(), 'updated_at' => now(),
            ]);

        $marcaId = DB::table('client_brands')->where('client_organization_id', $clienteId)->value('id')
            ?? DB::table('client_brands')->insertGetId([
                'uuid' => (string) Str::uuid(), 'client_organization_id' => $clienteId,
                // `slug` es NOT NULL y sin valor por defecto: lo calcula el
                // servicio de marcas, no la base (`DEC-087`).
                'name' => 'Marca', 'slug' => 'marca-red', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);

        $campanaId = $this->campanaDe((int) $clienteId, (int) $marcaId, [
            'status' => 'recruiting',
            'starts_on' => CarbonImmutable::now()->subDays(5)->toDateString(),
            'ends_on' => CarbonImmutable::now()->addDays(30)->toDateString(),
        ]);

        $participacionId = $this->participacionDe($campanaId, null, [
            'status' => $respuesta === 'accepted' ? 'accepted' : 'invited',
        ]);

        return (int) DB::table('invitations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'campaign_creator_id' => $participacionId,
            'channel' => 'email',
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'sent_at' => $cuando,
            'expires_at' => $cuando->addDays(7),
            'responded_at' => $respuesta === null ? null : now(),
            // `ck_inv_responded_ip`: contestada exige DESDE DONDE, porque es la
            // fila que se mira cuando alguien dice «yo no acepte eso». Se
            // guarda empaquetada --`inet_pton`-- como lo hace el servicio.
            'responded_ip' => $respuesta === null ? null : inet_pton('127.0.0.1'),
            'response' => $respuesta,
            'created_at' => $cuando,
        ]);
    }
}
