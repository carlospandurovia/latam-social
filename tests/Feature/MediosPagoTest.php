<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Creator\Services\CompletitudOperativa;
use App\Modules\Creator\Services\Requisito;
use App\Shared\Auth\Permisos;
use App\Shared\Crypto\CuentaBancaria;
use Database\Seeders\CimientosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los medios de pago del creador (iteración 3.8).
 *
 * Estas pruebas corren contra el esquema que construyen **las migraciones**, no
 * contra el SQL de referencia. Es una distinción que costó cara: las 226
 * aserciones de `tools/pruebas/*.sh` prueban el `.sql`, y por eso `H-08` —una
 * migración que MySQL 8 rechazaba— pasó por delante de todas ellas. Las cuatro
 * pruebas que terminan en `_la_base_lo_impide` están aquí a propósito, para que
 * los disparadores se ejecuten sobre lo que de verdad se despliega.
 */
final class MediosPagoTest extends TestCase
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
        $this->creadorId = $this->crearCreador('anatorres', 'ana@ejemplo.test', '40000001', $this->uuid);
    }

    private function crearCreador(string $nombre, string $correo, string $documento, ?string $uuid = null): int
    {
        return (int) DB::table('creators')->insertGetId([
            'uuid' => $uuid ?? (string) Str::uuid(),
            'first_name' => 'Ana', 'last_name' => 'Torres', 'display_name' => $nombre,
            'birth_date' => '1998-05-12', 'email' => $correo,
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'document_country_code' => 'PE', 'document_type' => 'DNI', 'document_number' => $documento,
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

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function formulario(array $cambios = []): array
    {
        return array_merge([
            'method_type' => 'bank_account',
            'country_id' => DB::table('countries')->where('iso2', 'PE')->value('id'),
            'currency_code' => 'PEN',
            'bank_name' => 'BCP',
            'account_type' => 'savings',
            'account_number' => '19100012345678',
            'owner_type' => 'creator',
            'holder_name' => 'Ana Torres',
            'holder_document_type' => 'DNI',
            'holder_document_number' => '40000001',
        ], $cambios);
    }

    /** @param array<string, mixed> $cambios */
    private function capturar(User $quien, array $cambios = []): int
    {
        $this->actingAs($quien)->post("/creadores/{$this->uuid}/pagos", $this->formulario($cambios));

        return (int) DB::table('creator_payment_methods')
            ->where('creator_id', $this->creadorId)->orderByDesc('id')->value('id');
    }

    private function verificado(): int
    {
        $id = $this->capturar($this->usuarioCon('finance'));
        $this->actingAs($this->usuarioCon('finance'))->post("/creadores/{$this->uuid}/pagos/{$id}/verificar");

        return $id;
    }

    private function requisitoDelMedio(): Requisito
    {
        foreach (CompletitudOperativa::revisar($this->creadorId) as $r) {
            if ($r->codigo === CompletitudOperativa::MEDIO_PAGO) {
                return $r;
            }
        }

        $this->fail('CompletitudOperativa no devolvio el requisito del medio de pago.');
    }

    // ------------------------------------------------------------ autorización

    public function test_la_cuenta_bancaria_no_es_para_cualquiera(): void
    {
        // DEC-053: finanzas es el único rol no administrador con acceso.
        $this->actingAs($this->usuarioCon('campaign_manager'))->get("/creadores/{$this->uuid}/pagos")->assertForbidden();
        $this->actingAs($this->usuarioCon('content_reviewer'))->get("/creadores/{$this->uuid}/pagos")->assertForbidden();
        $this->actingAs($this->usuarioCon('finance'))->get("/creadores/{$this->uuid}/pagos")->assertOk();
        $this->actingAs($this->usuarioCon('admin'))->get("/creadores/{$this->uuid}/pagos")->assertOk();
    }

    public function test_quien_no_gestiona_pagos_no_captura(): void
    {
        $this->actingAs($this->usuarioCon('campaign_manager'))
            ->post("/creadores/{$this->uuid}/pagos", $this->formulario())
            ->assertForbidden();

        $this->assertDatabaseCount('creator_payment_methods', 0);
    }

    // --------------------------------------------------------------- captura

    public function test_capturar_deja_la_cuenta_pendiente_y_sin_fecha_de_pago(): void
    {
        $id = $this->capturar($this->usuarioCon('finance'));
        $medio = DB::table('creator_payment_methods')->where('id', $id)->first();

        $this->assertSame('pending', $medio->status);
        // H-02: nace sin `eligible_from`, y eso no es un hueco: es que todavía
        // no lo ha mirado nadie.
        $this->assertNull($medio->eligible_from);
        $this->assertNull($medio->verified_at);
        $this->assertNotNull($medio->created_by_user_id, 'H-11: sin capturador no hay separacion de funciones.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator_payment_method.created']);
    }

    /**
     * LA PRUEBA DEL NÚMERO DE CUENTA.
     *
     * La regla del proyecto es literal: nada sensible en claro en la base
     * cuando hay alternativa segura. Aquí se comprueba de la única forma que
     * vale: buscando el número en las columnas y no encontrándolo.
     */
    public function test_el_numero_de_cuenta_no_queda_en_claro_en_ningun_sitio(): void
    {
        $numero = '19100012345678';
        $id = $this->capturar($this->usuarioCon('finance'), ['account_number' => $numero]);

        $medio = DB::table('creator_payment_methods')->where('id', $id)->first();

        $this->assertStringNotContainsString($numero, $medio->account_number_encrypted);
        $this->assertStringNotContainsString($numero, $medio->account_number_masked);
        $this->assertStringNotContainsString($numero, $medio->account_number_fingerprint);

        // Cifrado y reversible, no hasheado: para pagar hace falta el número.
        $this->assertSame($numero, CuentaBancaria::descifrar($medio->account_number_encrypted));

        // H-10: la máscara nunca puede llevar una quinta cifra.
        $this->assertSame('****5678', $medio->account_number_masked);
        $this->assertSame(1, preg_match('/^\*{4}\d{4}$/', $medio->account_number_masked));
        $this->assertSame(64, strlen($medio->account_number_fingerprint));

        // Y la máscara es lo único que llega a la pantalla.
        $this->actingAs($this->usuarioCon('finance'))->get("/creadores/{$this->uuid}/pagos")
            ->assertSee('****5678')
            ->assertDontSee($numero);
    }

    public function test_la_misma_cuenta_con_guiones_es_la_misma_cuenta(): void
    {
        $this->capturar($this->usuarioCon('finance'), ['account_number' => '19100012345678']);

        // Sin normalizar, un guion bastaría para colar la misma cuenta dos
        // veces y multiplicar las filas que hay que verificar.
        $this->actingAs($this->usuarioCon('finance'))
            ->post("/creadores/{$this->uuid}/pagos", $this->formulario(['account_number' => '1910-0012-3456-78']))
            ->assertSessionHas('aviso');

        $this->assertDatabaseCount('creator_payment_methods', 1);
    }

    // ----------------------------------------------------------- verificación

    /**
     * LA PRUEBA DE LA SEPARACIÓN DE FUNCIONES (`H-11`).
     *
     * Es la misma que en el perfil fiscal, y aquí importa más: esta fila dice a
     * dónde va el dinero.
     */
    public function test_no_verifica_quien_capturo(): void
    {
        $capturador = $this->usuarioCon('finance');
        $id = $this->capturar($capturador);

        $this->actingAs($capturador)
            ->post("/creadores/{$this->uuid}/pagos/{$id}/verificar")
            ->assertSessionHas('aviso');

        $this->assertSame('pending', DB::table('creator_payment_methods')->where('id', $id)->value('status'));
    }

    public function test_verificar_no_habilita_el_pago_lo_programa(): void
    {
        $id = $this->verificado();
        $medio = DB::table('creator_payment_methods')->where('id', $id)->first();

        $this->assertSame('verified', $medio->status);
        $this->assertNotNull($medio->verified_by_user_id);
        $this->assertNotNull($medio->eligible_from);
        // BR-FIN-006: verificada hoy, pagable dentro de 24 h.
        $this->assertTrue(
            now()->lt($medio->eligible_from),
            'Una cuenta recien verificada no puede ser pagable ya (BR-FIN-006).',
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator_payment_method.verified']);
    }

    public function test_durante_el_enfriamiento_el_creador_todavia_no_cumple(): void
    {
        $this->verificado();

        $this->assertFalse(
            $this->requisitoDelMedio()->cumple,
            'Verificada pero en enfriamiento no cuenta para BR-CREATOR-006.',
        );

        $this->travel(25)->hours();

        $this->assertTrue($this->requisitoDelMedio()->cumple, 'Pasado el enfriamiento si cuenta.');
    }

    // ---------------------------------------------------------------- retirada

    public function test_retirar_una_cuenta_sin_verificar_la_rechaza_y_deja_rastro(): void
    {
        $id = $this->capturar($this->usuarioCon('finance'));

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/creadores/{$this->uuid}/pagos/{$id}/retirar", ['motivo' => 'El titular no coincide con el DNI que trajo.'])
            ->assertRedirect(route('creadores.pagos', $this->uuid));

        $medio = DB::table('creator_payment_methods')->where('id', $id)->first();

        // Nunca se verificó, así que es un rechazo, no una desactivación.
        $this->assertSame('rejected', $medio->status);
        $this->assertNotNull($medio->closed_at);
        $this->assertNotNull($medio->closed_by_user_id);
        // H-04, una tabla más allá: rechazar no es verificar.
        $this->assertNull($medio->verified_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator_payment_method.rejected']);
    }

    public function test_retirar_una_cuenta_verificada_la_desactiva(): void
    {
        $id = $this->verificado();

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/creadores/{$this->uuid}/pagos/{$id}/retirar", ['motivo' => 'El creador cambio de banco y trajo la constancia.']);

        $medio = DB::table('creator_payment_methods')->where('id', $id)->first();

        $this->assertSame('disabled', $medio->status);
        // Sirvió: la verificación se queda escrita, porque ocurrió.
        $this->assertNotNull($medio->verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator_payment_method.disabled']);
    }

    public function test_un_motivo_de_una_palabra_no_vale(): void
    {
        $id = $this->capturar($this->usuarioCon('finance'));

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/creadores/{$this->uuid}/pagos/{$id}/retirar", ['motivo' => 'mal'])
            ->assertSessionHasErrors('motivo');

        $this->assertSame('pending', DB::table('creator_payment_methods')->where('id', $id)->value('status'));
    }

    // ---------------------------------------------------------- predeterminado

    public function test_el_predeterminado_tiene_que_estar_verificado(): void
    {
        $id = $this->capturar($this->usuarioCon('finance'));

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/creadores/{$this->uuid}/pagos/{$id}/predeterminado")
            ->assertSessionHas('aviso');

        $this->assertSame(0, (int) DB::table('creator_payment_methods')->where('id', $id)->value('is_default'));
    }

    public function test_marcar_uno_nuevo_quita_el_anterior(): void
    {
        $primero = $this->verificado();
        $this->actingAs($this->usuarioCon('finance'))->post("/creadores/{$this->uuid}/pagos/{$primero}/predeterminado");

        $segundo = $this->capturar($this->usuarioCon('finance'), ['account_number' => '19100099998888']);
        $this->actingAs($this->usuarioCon('finance'))->post("/creadores/{$this->uuid}/pagos/{$segundo}/verificar");
        $this->actingAs($this->usuarioCon('finance'))->post("/creadores/{$this->uuid}/pagos/{$segundo}/predeterminado");

        // `uq_cpm_default` solo admite uno por creador: si el orden fuera el
        // contrario, la base rechazaría la operación entera.
        $this->assertSame(0, (int) DB::table('creator_payment_methods')->where('id', $primero)->value('is_default'));
        $this->assertSame(1, (int) DB::table('creator_payment_methods')->where('id', $segundo)->value('is_default'));
    }

    // -------------------------------------------------------- cuenta compartida

    public function test_la_misma_cuenta_en_dos_creadores_se_marca_pero_no_se_rechaza(): void
    {
        $this->capturar($this->usuarioCon('finance'));

        $otroUuid = (string) Str::uuid();
        $this->crearCreador('luisvega', 'luis@ejemplo.test', '40000002', $otroUuid);

        $this->actingAs($this->usuarioCon('finance'))
            ->post("/creadores/{$otroUuid}/pagos", $this->formulario(['holder_document_number' => '40000002']))
            ->assertRedirect(route('creadores.pagos', $otroUuid));

        // DEC-065: se admite. Un tutor puede cobrar por dos pupilos.
        $this->assertDatabaseCount('creator_payment_methods', 2);

        $segundo = DB::table('creator_payment_methods')->orderByDesc('id')->first();
        // La marca la pone el disparador, no la aplicación: si la escribiera la
        // aplicación podría afirmar «única» sin haber mirado nada (H-06).
        $this->assertSame('pending_review', $segundo->shared_account_status);
    }

    // ------------------------------------------- lo que impide la base, no el código

    public function test_cambiar_el_numero_de_una_cuenta_verificada_la_base_lo_impide(): void
    {
        $id = $this->verificado();

        // H-12. No hay ruta de edición, y si alguien escribe el UPDATE a mano
        // tampoco pasa: la regla vive en la base.
        $this->expectException(QueryException::class);

        DB::table('creator_payment_methods')->where('id', $id)->update([
            'account_number_encrypted' => 'otra',
            'account_number_fingerprint' => str_repeat('z', 64),
        ]);
    }

    public function test_acortar_el_enfriamiento_la_base_lo_impide(): void
    {
        $id = $this->verificado();

        // Sin esto, BR-FIN-006 estaría a un UPDATE de distancia.
        $this->expectException(QueryException::class);

        DB::table('creator_payment_methods')->where('id', $id)->update([
            'eligible_from' => now()->subDay(),
        ]);
    }

    public function test_borrar_un_medio_de_pago_la_base_lo_impide(): void
    {
        $id = $this->capturar($this->usuarioCon('finance'));

        // H-13 / BR-FIN-008: ningún registro financiero se elimina físicamente.
        $this->expectException(QueryException::class);

        DB::table('creator_payment_methods')->where('id', $id)->delete();
    }

    public function test_pagar_a_una_cuenta_sin_verificar_la_base_lo_impide(): void
    {
        $id = $this->capturar($this->usuarioCon('finance'));

        $lote = DB::table('payout_batches')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'code' => 'LOTE-TEST-1',
            'legal_entity_id' => DB::table('legal_entities')->value('id'),
            'currency_code' => 'PEN',
            'status' => 'draft',
            'created_by_user_id' => $this->usuarioCon('finance')->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // H-09. `fk_payout_method` solo comprobaba que la fila existiera: se
        // reprodujo un pago de 1500 PEN contra un medio en `pending`.
        $this->expectException(QueryException::class);

        DB::table('payouts')->insert([
            'uuid' => (string) Str::uuid(),
            'payout_batch_id' => $lote,
            'creator_id' => $this->creadorId,
            'payment_method_id' => $id,
            'beneficiary_name_snapshot' => 'Ana Torres',
            'account_masked_snapshot' => '****5678',
            'amount' => 1500.0000,
            'currency_code' => 'PEN',
            'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
