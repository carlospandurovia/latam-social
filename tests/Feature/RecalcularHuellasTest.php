<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Auth\Permisos;
use App\Shared\Crypto\CuentaBancaria;
use Database\Seeders\CimientosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\ConFixturas;
use Tests\TestCase;

/**
 * `pagos:recalcular-huellas` y la convergencia (`T-20`).
 *
 * ### El escenario, y por qué sólo aparece tras rotar la clave
 *
 * La huella de una cuenta es un HMAC con `APP_KEY`, y
 * `uq_cpm_open_account (open_gate, creator_id, account_number_fingerprint)`
 * impide que un creador tenga la misma cuenta dos veces abierta.
 *
 * Pero con la clave rotada, dos filas de la **misma** cuenta llevan huellas
 * **distintas** —una calculada con cada clave—, así que la única no las ve y
 * las dos entran. Nada falla: simplemente el control deja de controlar.
 *
 * Al recalcular, las dos huellas convergen y el segundo `UPDATE` se estrella.
 * Antes de `T-20` eso era un `Duplicate entry` en crudo desde una consola, con
 * la transacción caída y el recálculo sin hacer — o sea la detección de cuentas
 * repetidas apagada, que es exactamente lo que el comando venía a arreglar.
 *
 * ### Y por qué NO se absorbe
 *
 * Reintentar daría la misma huella y chocaría igual, tres veces. El choque no
 * es un valor mal calculado: es un dato real que estaba mal y que la rotación
 * saca a la luz. **Dos medios de pago abiertos del mismo creador que son la
 * misma cuenta.** Cuál de los dos sobrevive no lo decide un comando.
 */
final class RecalcularHuellasTest extends TestCase
{
    use ConFixturas;
    use RefreshDatabase;

    private const CUENTA = '19100012345678';

    private int $creadorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CimientosSeeder::class);
        Permisos::olvidar();

        $this->creadorId = $this->creadorPendiente();
    }

    /** Sin nada que recalcular, no hay nada que decir. */
    public function test_con_las_huellas_al_dia_no_hace_nada(): void
    {
        $this->medioDePago($this->creadorId, self::CUENTA);

        $this->artisan('pagos:recalcular-huellas')
            ->expectsOutputToContain('Todas las huellas cuadran con la clave actual')
            ->assertExitCode(0);
    }

    /** Una huella desfasada se detecta, y sin `--aplicar` no se escribe. */
    public function test_la_revision_no_escribe(): void
    {
        $id = $this->medioDePago($this->creadorId, self::CUENTA, ['account_number_fingerprint' => str_repeat('9', 64)]);

        $this->artisan('pagos:recalcular-huellas')->assertExitCode(0);

        $this->assertSame(str_repeat('9', 64), (string) DB::table('creator_payment_methods')
            ->where('id', $id)->value('account_number_fingerprint'), 'la revision no toca nada');
    }

    public function test_con_aplicar_recalcula(): void
    {
        $id = $this->medioDePago($this->creadorId, self::CUENTA, ['account_number_fingerprint' => str_repeat('9', 64)]);

        $this->artisan('pagos:recalcular-huellas --aplicar')->assertExitCode(0);

        $this->assertSame(
            CuentaBancaria::huella(self::CUENTA),
            (string) DB::table('creator_payment_methods')->where('id', $id)->value('account_number_fingerprint'),
        );
    }

    /**
     * **La prueba de la iteración.**
     *
     * Dos medios abiertos del mismo creador, la misma cuenta, huellas
     * distintas — el estado que deja una rotación de clave. Al recalcular
     * convergerían.
     */
    public function test_la_convergencia_se_ve_antes_de_escribir_y_no_se_escribe_nada(): void
    {
        $viejo = $this->medioDePago($this->creadorId, self::CUENTA, ['account_number_fingerprint' => str_repeat('9', 64)]);
        $nuevo = $this->medioDePago($this->creadorId, self::CUENTA, ['holder_document_number' => '40000002']);

        // La premisa: las dos ABIERTAS y con huellas distintas, o la unica las
        // habria rechazado y esta prueba pasaria por el motivo equivocado.
        $this->assertSame(2, DB::table('creator_payment_methods')
            ->where('creator_id', $this->creadorId)->whereIn('status', ['pending', 'verified'])->count());

        $this->artisan('pagos:recalcular-huellas --aplicar')
            ->expectsOutputToContain('No escribo nada')
            ->expectsOutputToContain('la MISMA cuenta, dada de alta dos veces')
            ->assertExitCode(1);

        // Y no se escribio NADA: ni siquiera la fila que no chocaba.
        $this->assertSame(str_repeat('9', 64), (string) DB::table('creator_payment_methods')
            ->where('id', $viejo)->value('account_number_fingerprint'));
        $this->assertSame(CuentaBancaria::huella(self::CUENTA), (string) DB::table('creator_payment_methods')
            ->where('id', $nuevo)->value('account_number_fingerprint'));
    }

    /** El mensaje nombra a los dos medios: hay que ir a mirarlos. */
    public function test_el_aviso_nombra_al_creador_y_a_los_dos_medios(): void
    {
        $viejo = $this->medioDePago($this->creadorId, self::CUENTA, ['account_number_fingerprint' => str_repeat('9', 64)]);
        $nuevo = $this->medioDePago($this->creadorId, self::CUENTA, ['holder_document_number' => '40000002']);

        $this->artisan('pagos:recalcular-huellas')
            ->expectsOutputToContain("creador {$this->creadorId}: medios {$viejo}, {$nuevo}")
            ->assertExitCode(1);
    }

    /**
     * Una cuenta **dada de baja** (`disabled`) no ocupa sitio en `uq_cpm_open_account`, así
     * que converger con ella no es un choque. Acusarlo sería bloquear un
     * recálculo legítimo por un dato que ya no estorba.
     */
    public function test_una_cuenta_desactivada_no_cuenta_como_convergencia(): void
    {
        $id = $this->medioDePago($this->creadorId, self::CUENTA, ['account_number_fingerprint' => str_repeat('9', 64), 'status' => 'disabled']);
        $this->medioDePago($this->creadorId, self::CUENTA, ['holder_document_number' => '40000002']);

        $this->artisan('pagos:recalcular-huellas --aplicar')->assertExitCode(0);

        $this->assertSame(
            CuentaBancaria::huella(self::CUENTA),
            (string) DB::table('creator_payment_methods')->where('id', $id)->value('account_number_fingerprint'),
        );
    }

    // ------------------------------------------------------------------ apoyo
}
