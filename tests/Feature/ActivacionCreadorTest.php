<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Creator\Services\CompletitudOperativa;
use App\Shared\Auth\Permisos;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * La puerta de activación (iteración 3.5).
 *
 * La prueba central es `test_falta_un_requisito_y_no_se_activa`: equipa al
 * creador con las seis condiciones y luego le quita **una sola**, seis veces.
 * Escrita al revés —comprobar que con todo puesto sí se activa— pasaría en
 * verde aunque el servidor no comprobara nada.
 *
 * Y la que de verdad ha encontrado errores en este proyecto: la que afirma que
 * algo **sí se permite**. `test_con_todo_puesto_se_activa` es la que se rompe si
 * una condición se vuelve imposible de cumplir por un fallo en la consulta, y
 * ese fallo no lo ve ninguna prueba de rechazo.
 */
final class ActivacionCreadorTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private int $creadorId;

    private string $uuid;

    private int $revisorId;

    /** Quien CAPTURA el perfil fiscal, que no puede ser quien lo aprueba
     *  (`ck_ctp_segregation`). Desde 3.6 `created_by_user_id` es obligatorio:
     *  antes esta prueba aprobaba un perfil que nadie constaba haber capturado. */
    private int $capturadorId;

    private int $fileId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->revisorId = (int) User::factory()->create()->id;
        $this->capturadorId = (int) User::factory()->create()->id;

        $this->fileId = $this->archivoDeIdentidad();

        $this->uuid = (string) Str::uuid();
        $this->creadorId = $this->creadorPendiente(['uuid' => $this->uuid]);
    }

    // --------------------------------------------------------------- utilería

    /**
     * Publica unos términos nuevos para que la aceptación anterior deje de valer.
     *
     * Antes tenía su propio `insert`, que **se dejaba `title`** —columna
     * obligatoria— y reventaba con un `1364`. No hacía falta ninguno: para esto
     * ya estaba `publicarTerminos()`, que es además el camino que las otras
     * pruebas ejercitan.
     */
    private function publicarTerminosNuevos(): void
    {
        $this->publicarTerminos('v2-prueba', '2026-07-01');
    }

    /** Deja al creador cumpliendo las seis condiciones. */
    private function equiparTodo(?string $elegibleDesde = null): void
    {
        $this->ponerIdentidad();
        $this->ponerRedSocial();
        $this->ponerFiscal();
        $this->ponerMedioDePago(elegibleDesde: $elegibleDesde);
        $this->ponerTerminos();
    }

    private function ponerIdentidad(): void
    {
        DB::table('creators')->where('id', $this->creadorId)->update([
            'identity_verified_at' => now(),
            'identity_verified_by_user_id' => $this->revisorId,
            'identity_document_file_id' => $this->fileId,
        ]);
    }

    private function ponerRedSocial(): void
    {
        DB::table('social_accounts')->insert([
            'uuid' => (string) Str::uuid(), 'creator_id' => $this->creadorId,
            'platform_id' => DB::table('platforms')->value('id'),
            'handle' => 'anatorres', 'profile_url' => 'https://ejemplo.test/anatorres',
            // 3.7 / H-05: verificada exige decir COMO y QUIEN. Antes bastaba
            // la fecha, y una cuenta podia quedar verificada sin constancia de
            // nada.
            'verification_status' => 'verified', 'verified_at' => now(),
            'verification_method' => 'bio_code', 'verified_by_user_id' => $this->revisorId,
            'is_primary' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Si se pasa `$tutorId`, el perfil queda a nombre del tutor. Para un menor
     * eso no es opcional: BR-CREATOR-013 exige el perfil DEL TUTOR, que es quien
     * emite el comprobante. Antes de 3.6 el modelo no sabia decirlo (H-01) y
     * aqui valia cualquier perfil aprobado, fuera de quien fuera.
     */
    private function ponerFiscal(?int $tutorId = null): void
    {
        DB::table('creator_tax_profiles')->insert([
            'creator_id' => $this->creadorId,
            'holder_type' => $tutorId === null ? 'creator' : 'guardian',
            'holder_guardian_id' => $tutorId,
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'tax_regime_code' => 'RER', 'tax_id_type' => 'RUC', 'tax_id_number' => '10400000012',
            'issued_document_type' => 'recibo_honorarios',
            // DEC-048: aprobar con la retención sin decidir lo impide un CHECK.
            'withholding_status' => 'not_applicable', 'withholding_rate' => 0,
            'status' => 'approved',
            'created_by_user_id' => $this->capturadorId,
            'approved_by_user_id' => $this->revisorId, 'approved_at' => now(),
            'valid_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Desde 3.8 la cuenta es INMUTABLE (`DEC-066`): no se puede crear una y
     * luego moverle la fecha de elegibilidad con un `update`. Por eso
     * `$elegibleDesde` es un parametro y no algo que se retoque despues.
     */
    private function ponerMedioDePago(string $dueno = 'creator', ?int $tutorId = null, ?string $elegibleDesde = null): void
    {
        DB::table('creator_payment_methods')->insert([
            'uuid' => (string) Str::uuid(), 'creator_id' => $this->creadorId,
            'owner_type' => $dueno, 'owner_guardian_id' => $tutorId,
            'method_type' => 'bank_account',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'currency_code' => 'PEN', 'bank_name' => 'BCP', 'account_type' => 'savings',
            'account_number_encrypted' => 'enc:xxxx', 'account_number_masked' => '****4321',
            'account_number_fingerprint' => str_repeat('b', 64),
            'holder_name' => 'Ana Torres', 'holder_document_type' => 'DNI', 'holder_document_number' => '40000001',
            // H-11: capturador y verificador, y distintos. La base lo exige.
            'created_by_user_id' => $this->capturadorId,
            'status' => 'verified', 'verified_at' => now()->subDay(), 'verified_by_user_id' => $this->revisorId,
            // BR-FIN-006: por defecto, ya fuera del enfriamiento.
            'eligible_from' => $elegibleDesde ?? now()->subHour(), 'is_default' => 1,
            'created_at' => now()->subDay(), 'updated_at' => now(),
        ]);
    }

    private function ponerTerminos(): void
    {
        $versionId = $this->publicarTerminos();

        DB::table('terms_acceptances')->insert([
            'uuid' => (string) Str::uuid(), 'terms_version_id' => $versionId,
            'subject_type' => 'creator', 'subject_id' => $this->creadorId,
            'channel' => 'email', 'recorded_by_user_id' => $this->revisorId,
            'evidence_file_id' => $this->fileId, 'evidence_note' => 'Correo archivado',
            'accepted_at' => now(), 'created_at' => now(),
        ]);
    }

    private function ponerTutor(string $estado = 'active'): int
    {
        return (int) DB::table('creator_guardians')->insertGetId([
            'creator_id' => $this->creadorId, 'full_name' => 'Rosa Torres',
            'relationship' => 'mother', 'document_country_code' => 'PE',
            'document_type' => 'DNI', 'document_number' => '09000001',
            'email' => 'rosa@ejemplo.test',
            'authorization_file_id' => $this->fileId,
            'proof_of_relationship_file_id' => $this->fileId,
            'status' => $estado, 'valid_from' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------ autorización

    public function test_solo_quien_puede_verificar_o_activar_ve_la_pantalla(): void
    {
        $this->actingAs($this->usuarioCon('content_reviewer'))
            ->get("/backoffice/creadores/{$this->uuid}/activacion")->assertForbidden();
        $this->actingAs($this->usuarioCon('finance'))
            ->get("/backoffice/creadores/{$this->uuid}/activacion")->assertForbidden();
        $this->actingAs($this->usuarioCon('admin'))
            ->get("/backoffice/creadores/{$this->uuid}/activacion")->assertOk();
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->get("/backoffice/creadores/{$this->uuid}/activacion")->assertOk();
    }

    /**
     * `finance` ve datos fiscales y bancarios (DEC-053) pero NO decide quién
     * entra al catálogo. Son autorizaciones distintas y esta prueba lo fija.
     */
    public function test_finanzas_no_puede_activar(): void
    {
        $this->equiparTodo();

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/backoffice/creadores/{$this->uuid}/activar")->assertForbidden();

        $this->assertSame('pending', DB::table('creators')->where('id', $this->creadorId)->value('status'));
    }

    // ------------------------------------------------------- la puerta en sí

    public function test_con_todo_puesto_se_activa(): void
    {
        $this->equiparTodo();

        $this->actingAs($this->usuarioCon('admin'))
            ->post("/backoffice/creadores/{$this->uuid}/activar", ['motivo' => 'Revisado por reclutamiento'])
            ->assertRedirect(route('creadores.show', $this->uuid));

        $creador = DB::table('creators')->where('id', $this->creadorId)->first();
        $this->assertSame('active', $creador->status);
        $this->assertNotNull($creador->activated_at, 'ck_creators_activation exige fecha de activación.');

        // El histórico, que no es la bitácora: de aquí salen los tiempos del embudo.
        $this->assertDatabaseHas('status_transitions', [
            'entity_type' => 'creator', 'entity_id' => $this->creadorId,
            'from_status' => 'pending', 'to_status' => 'active',
            'reason' => 'Revisado por reclutamiento',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'creator.activated', 'entity_type' => 'creator', 'entity_id' => $this->creadorId,
        ]);
    }

    /** Se congela POR QUÉ se pudo activar, no solo que se activó. */
    public function test_la_bitacora_guarda_con_que_se_dio_por_buena_la_activacion(): void
    {
        $this->equiparTodo();
        $this->actingAs($this->usuarioCon('admin'))->post("/backoffice/creadores/{$this->uuid}/activar");

        $entrada = DB::table('audit_logs')->where('action', 'creator.activated')->first();
        $cambios = json_decode((string) $entrada->changes, true);

        $this->assertIsArray($cambios);
        $this->assertArrayHasKey('completitud', $cambios);
        $this->assertArrayHasKey(CompletitudOperativa::MEDIO_PAGO, $cambios['completitud']['despues']);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function requisitos(): array
    {
        return [
            'sin identidad verificada' => [CompletitudOperativa::IDENTIDAD],
            'sin red social validada' => [CompletitudOperativa::RED_SOCIAL],
            'sin datos fiscales aprobados' => [CompletitudOperativa::FISCAL],
            'sin medio de pago elegible' => [CompletitudOperativa::MEDIO_PAGO],
            'sin aceptación de términos' => [CompletitudOperativa::TERMINOS],
        ];
    }

    /**
     * LA PRUEBA CENTRAL: se pone todo y se quita **una sola** cosa.
     */
    #[DataProvider('requisitos')]
    public function test_falta_un_requisito_y_no_se_activa(string $codigo): void
    {
        $this->equiparTodo();

        match ($codigo) {
            CompletitudOperativa::IDENTIDAD => DB::table('creators')->where('id', $this->creadorId)->update([
                'identity_verified_at' => null,
                'identity_verified_by_user_id' => null,
                'identity_document_file_id' => null,
            ]),
            CompletitudOperativa::RED_SOCIAL => DB::table('social_accounts')
                ->where('creator_id', $this->creadorId)->update(['verification_status' => 'pending', 'verified_at' => null]),
            CompletitudOperativa::FISCAL => DB::table('creator_tax_profiles')
                ->where('creator_id', $this->creadorId)->update(['status' => 'rejected']),
            // Desde 3.8 una verificacion no se reescribe (`tg_cpm_inmutable`) y el
            // predeterminado tiene que estar verificado (`ck_cpm_default_usable`),
            // asi que "quitarle el medio de pago" ya no es desverificarlo: es
            // retirarlo, que es lo que se haria de verdad.
            CompletitudOperativa::MEDIO_PAGO => DB::table('creator_payment_methods')
                ->where('creator_id', $this->creadorId)->update([
                    'is_default' => 0, 'status' => 'disabled',
                    'closed_at' => now(), 'closed_by_user_id' => $this->revisorId,
                ]),
            // No se borra la aceptación: se PUBLICA UNA VERSIÓN NUEVA.
            //
            // Borrarla era destruir la prueba de que el creador aceptó, y desde
            // `T-16` la base no lo admite. Pero el arreglo no es rodear el
            // disparador: es que borrar nunca fue lo que pasa de verdad.
            //
            // Lo que ocurre en la vida real es que los términos se actualizan y
            // la aceptación anterior deja de valer —`CompletitudOperativa` mira
            // la versión VIGENTE—. Así que eso es lo que hace la prueba ahora, y
            // de paso cubre un caso que antes no cubría nadie.
            //
            // Es el tercer requisito de esta misma lista que deja de simularse
            // rompiendo datos: el fiscal ya se rechaza y el medio de pago ya se
            // retira, «que es lo que se haría de verdad».
            CompletitudOperativa::TERMINOS => $this->publicarTerminosNuevos(),
            default => $this->fail("Requisito desconocido: {$codigo}"),
        };

        $this->actingAs($this->usuarioCon('admin'))
            ->post("/backoffice/creadores/{$this->uuid}/activar")
            ->assertSessionHas('aviso');

        $this->assertSame(
            'pending',
            DB::table('creators')->where('id', $this->creadorId)->value('status'),
            "Se activó al creador faltando «{$codigo}».",
        );
        $this->assertDatabaseMissing('status_transitions', [
            'entity_type' => 'creator', 'entity_id' => $this->creadorId, 'to_status' => 'active',
        ]);
    }

    /**
     * `H-02`, cerrado en 3.8: este test comprobaba que un medio `verified` con
     * `eligible_from` NULL no contara para la activacion. Ya no puede
     * construirse ese estado — `ck_cpm_eligible` lo rechaza en la base — asi
     * que lo que se comprueba ahora es justamente eso: que **no existe**.
     *
     * Una defensa en la aplicacion se puede olvidar en la siguiente consulta,
     * y de hecho se olvido: la de pagos no la tenia (`H-09`).
     */
    public function test_un_medio_verificado_sin_fecha_de_elegibilidad_ya_no_puede_existir(): void
    {
        $this->equiparTodo();

        $this->expectException(QueryException::class);

        DB::table('creator_payment_methods')->insert([
            'uuid' => (string) Str::uuid(), 'creator_id' => $this->creadorId,
            'owner_type' => 'creator', 'method_type' => 'bank_account',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'currency_code' => 'PEN',
            'account_number_encrypted' => 'enc:yyyy', 'account_number_masked' => '****9999',
            'account_number_fingerprint' => str_repeat('c', 64),
            'holder_name' => 'Ana Torres', 'holder_document_type' => 'DNI',
            'holder_document_number' => '40000001',
            'created_by_user_id' => $this->capturadorId,
            'status' => 'verified', 'verified_at' => now(), 'verified_by_user_id' => $this->revisorId,
            'eligible_from' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Y todavía dentro del enfriamiento, no cuenta. */
    public function test_medio_de_pago_en_enfriamiento_no_cuenta(): void
    {
        // Nace ya en enfriamiento: `tg_cpm_inmutable` no deja moverle la fecha
        // despues, que es exactamente lo que hacia la version anterior de este
        // test y lo que BR-FIN-006 vino a impedir.
        $this->equiparTodo(elegibleDesde: now()->addDays(3)->toDateTimeString());

        $this->actingAs($this->usuarioCon('admin'))
            ->post("/backoffice/creadores/{$this->uuid}/activar")->assertSessionHas('aviso');

        $this->assertSame('pending', DB::table('creators')->where('id', $this->creadorId)->value('status'));
    }

    // -------------------------------------------------------------- menores

    public function test_un_menor_sin_tutela_activa_no_se_activa(): void
    {
        DB::table('creators')->where('id', $this->creadorId)->update(['birth_date' => now()->subYears(15)->toDateString()]);
        $this->equiparTodo();

        $this->actingAs($this->usuarioCon('admin'))
            ->post("/backoffice/creadores/{$this->uuid}/activar")->assertSessionHas('aviso');

        $this->assertSame('pending', DB::table('creators')->where('id', $this->creadorId)->value('status'));

        // Y el motivo del medio de pago tiene que decir la verdad: lo que falta
        // es el tutor, no la cuenta. Antes decia «no hay ningun medio de pago
        // registrado» porque la consulta usaba un id centinela que no casaba
        // con nada.
        foreach (CompletitudOperativa::revisar($this->creadorId) as $r) {
            if ($r->codigo === CompletitudOperativa::MEDIO_PAGO) {
                $this->assertStringContainsString('tutela activa', $r->detalle);
            }
        }
    }

    /**
     * BR-CREATOR-010: al menor se le paga a nombre del tutor. Un medio de pago
     * a nombre del propio menor no sirve, aunque esté verificado.
     */
    public function test_un_menor_con_medio_de_pago_a_su_nombre_no_se_activa(): void
    {
        DB::table('creators')->where('id', $this->creadorId)->update(['birth_date' => now()->subYears(15)->toDateString()]);
        $tutorId = $this->ponerTutor();

        // Todo correcto MENOS la cuenta, que esta a nombre del menor. Asi lo
        // que falla es una sola cosa y la prueba dice de verdad lo que afirma.
        $this->ponerIdentidad();
        $this->ponerRedSocial();
        $this->ponerFiscal($tutorId);
        $this->ponerMedioDePago();
        $this->ponerTerminos();

        $this->actingAs($this->usuarioCon('admin'))
            ->post("/backoffice/creadores/{$this->uuid}/activar")->assertSessionHas('aviso');

        $this->assertSame('pending', DB::table('creators')->where('id', $this->creadorId)->value('status'));
    }

    public function test_un_menor_con_tutela_y_cuenta_del_tutor_si_se_activa(): void
    {
        DB::table('creators')->where('id', $this->creadorId)->update(['birth_date' => now()->subYears(15)->toDateString()]);
        $tutorId = $this->ponerTutor();

        $this->ponerIdentidad();
        $this->ponerRedSocial();
        $this->ponerFiscal($tutorId);
        $this->ponerMedioDePago('guardian', $tutorId);
        $this->ponerTerminos();

        $this->actingAs($this->usuarioCon('admin'))->post("/backoffice/creadores/{$this->uuid}/activar");

        $this->assertSame('active', DB::table('creators')->where('id', $this->creadorId)->value('status'));
    }

    // ------------------------------------------------------------- términos

    /**
     * LA PROPIEDAD QUE JUSTIFICA VERSIONAR (DEC-059): publicar unos términos
     * nuevos deja pendiente a todo el mundo, sin revocar nada y sin que nadie
     * tenga que acordarse de invalidar las aceptaciones viejas.
     */
    public function test_publicar_una_version_nueva_deja_la_aceptacion_anterior_fuera_de_vigencia(): void
    {
        $this->equiparTodo();

        $requisitos = CompletitudOperativa::revisar($this->creadorId);
        $this->assertTrue(CompletitudOperativa::completa($requisitos));

        // Desde julio, no desde enero: la 2026.1 ya cubre desde el 1 de enero,
        // y dos versiones no pueden arrancar el mismo dia (3.13).
        $this->publicarTerminos('2026.2', '2026-07-01');

        $requisitos = CompletitudOperativa::revisar($this->creadorId);
        $this->assertFalse(CompletitudOperativa::completa($requisitos));
        $this->assertContains('Aceptación vigente de los términos', CompletitudOperativa::pendientes($requisitos));
    }

    /** Sin términos publicados no es culpa del creador, y el mensaje lo dice. */
    public function test_sin_terminos_publicados_el_requisito_falla_y_se_explica(): void
    {
        $encontrado = false;

        foreach (CompletitudOperativa::revisar($this->creadorId) as $r) {
            if ($r->codigo !== CompletitudOperativa::TERMINOS) {
                continue;
            }

            $encontrado = true;
            $this->assertFalse($r->cumple);
            // El mensaje apunta a quien puede resolverlo, que no es el creador.
            $this->assertStringContainsString('plataforma', $r->detalle);
        }

        $this->assertTrue($encontrado, 'CompletitudOperativa dejo de evaluar los terminos.');
    }

    public function test_registrar_una_aceptacion_exige_evidencia_y_deja_rastro(): void
    {
        $this->publicarTerminos();

        $this->actingAs($this->usuarioCon('admin'))
            ->post("/backoffice/creadores/{$this->uuid}/terminos", [
                'channel' => 'email',
                'accepted_at' => now()->subHour()->format('Y-m-d\TH:i'),
                'evidencia' => self::pdfDePrueba('correo.pdf'),
                'confirma_revision' => '1',
            ])->assertRedirect(route('creadores.activacion', $this->uuid));

        $aceptacion = DB::table('terms_acceptances')->where('subject_id', $this->creadorId)->first();
        $this->assertNotNull($aceptacion);
        $this->assertNotNull($aceptacion->evidence_file_id, 'ck_terms_acceptances_backing exige evidencia.');
        $this->assertNotNull($aceptacion->recorded_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator.terms_accepted']);
    }

    public function test_no_se_registra_una_aceptacion_sin_evidencia(): void
    {
        $this->publicarTerminos();

        $this->actingAs($this->usuarioCon('admin'))
            ->post("/backoffice/creadores/{$this->uuid}/terminos", [
                'channel' => 'email',
                'accepted_at' => now()->subHour()->format('Y-m-d\TH:i'),
                'confirma_revision' => '1',
            ])->assertSessionHasErrors('evidencia');

        $this->assertDatabaseCount('terms_acceptances', 0);
    }

    /** No se puede haber aceptado un documento antes de que existiera. */
    public function test_no_se_acepta_una_version_antes_de_su_entrada_en_vigor(): void
    {
        $this->publicarTerminos();

        $this->actingAs($this->usuarioCon('admin'))
            ->post("/backoffice/creadores/{$this->uuid}/terminos", [
                'channel' => 'email',
                'accepted_at' => '2025-06-01T10:00',
                'evidencia' => self::pdfDePrueba('correo.pdf'),
                'confirma_revision' => '1',
            ])->assertSessionHas('aviso');

        $this->assertDatabaseCount('terms_acceptances', 0);
    }

    // ------------------------------------------------------------- identidad

    public function test_verificar_identidad_guarda_las_tres_columnas_y_el_archivo(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post("/backoffice/creadores/{$this->uuid}/identidad", [
                'documento' => self::pdfDePrueba('dni.pdf'),
                'confirma_cotejo' => '1',
                'nota' => 'DNI cotejado contra la ficha',
            ])->assertRedirect(route('creadores.activacion', $this->uuid));

        $creador = DB::table('creators')->where('id', $this->creadorId)->first();
        $this->assertNotNull($creador->identity_verified_at);
        $this->assertNotNull($creador->identity_verified_by_user_id);
        $this->assertNotNull($creador->identity_document_file_id);

        $archivo = DB::table('files')->where('id', $creador->identity_document_file_id)->first();
        $this->assertSame('identity_document', $archivo->purpose);
        $this->assertSame('private', $archivo->visibility);
        // Un archivo de 0 bytes es una evidencia que no prueba nada. Antes esto
        // no se comprobaba y solo saltaba `ck_files_size` con un 500 opaco.
        $this->assertGreaterThan(0, (int) $archivo->size_bytes);
        // La huella se calcula del archivo guardado, no del temporal.
        $this->assertSame(64, strlen((string) $archivo->checksum_sha256));
        Storage::disk('local')->assertExists($archivo->path);
    }

    public function test_no_se_verifica_la_identidad_sin_confirmar_el_cotejo(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post("/backoffice/creadores/{$this->uuid}/identidad", [
                'documento' => self::pdfDePrueba('dni.pdf'),
            ])->assertSessionHasErrors('confirma_cotejo');

        $this->assertNull(DB::table('creators')->where('id', $this->creadorId)->value('identity_verified_at'));
    }

    public function test_no_se_admite_un_ejecutable_disfrazado_de_pdf(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->post("/backoffice/creadores/{$this->uuid}/identidad", [
                'documento' => UploadedFile::fake()->create('malicioso.php', 10, 'application/x-php'),
                'confirma_cotejo' => '1',
            ])->assertSessionHasErrors('documento');

        $this->assertDatabaseMissing('files', ['purpose' => 'identity_document', 'original_name' => 'malicioso.php']);
    }

    // ------------------------------------------------------------ concurrencia

    public function test_no_se_activa_dos_veces(): void
    {
        $this->equiparTodo();
        $usuario = $this->usuarioCon('admin');

        $this->actingAs($usuario)->post("/backoffice/creadores/{$this->uuid}/activar");
        $this->actingAs($usuario)->post("/backoffice/creadores/{$this->uuid}/activar")->assertSessionHas('aviso');

        $this->assertSame(1, DB::table('status_transitions')
            ->where('entity_type', 'creator')->where('entity_id', $this->creadorId)
            ->where('to_status', 'active')->count());
    }

    public function test_la_pantalla_dice_que_falta(): void
    {
        $this->actingAs($this->usuarioCon('admin'))
            ->get("/backoffice/creadores/{$this->uuid}/activacion")
            ->assertOk()
            ->assertSee('No hay ninguna cuenta con la propiedad comprobada.')
            ->assertSee('No hay perfil tributario aprobado y vigente', false);
    }
}
