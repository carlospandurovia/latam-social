<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dos defectos del modelo social, de la misma familia (iteración 3.7).
 *
 * **H-05 — verificar una cuenta no obligaba a decir cómo ni quién.**
 * `verification_method` era un `VARCHAR(20)` sin lista cerrada: la única
 * columna con pinta de estado en todo el modelo que admitía texto libre. Y
 * `ck_social_accounts_verified_at` solo exigía la fecha, así que una cuenta
 * podía quedar `verified` sin constancia del método ni de la persona.
 *
 * Es la misma lección que `DEC-058` con la identidad, una tabla más allá: una
 * marca sin método y sin persona no es evidencia, es una casilla.
 *
 * **H-06 — «no es anómalo» y «nadie lo ha mirado» eran el mismo cero.**
 * `is_anomalous TINYINT NOT NULL DEFAULT 0` es exactamente el fallo que
 * `DEC-048` corrigió en la retención: un valor por defecto que parece una
 * respuesta. Cada snapshot insertado hasta hoy afirmaba haber pasado los
 * chequeos de coherencia de `BR-CREATOR-004` **sin que ninguno se hubiera
 * ejecutado nunca** — no hay una sola línea de código que los ejecute.
 *
 * Pasa a `coherence_status` con tres estados, partiendo de `pending_review`.
 * No se puede afirmar que una métrica está limpia hasta que alguien la mire.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------ H-05: la evidencia
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('verified_by_user_id')->nullable()->after('verification_method');

            $table->index('verified_by_user_id', 'ix_social_accounts_verifier');
            $table->foreign('verified_by_user_id', 'fk_social_accounts_verifier')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Restriccion::comprobacion(
            tabla: 'social_accounts',
            nombre: 'ck_social_accounts_method',
            expresion: 'verification_method IS NULL OR verification_method IN '
                ."('bio_code','dm_challenge','post_mention','oauth','manual_review')",
            columnas: ['verification_method'],
            mensaje: 'Metodo de verificacion de cuenta social no valido.',
        );

        // La antigua solo miraba la fecha; esta mira además el método.
        Restriccion::quitar('social_accounts', 'ck_social_accounts_verified_at');
        Restriccion::comprobacion(
            tabla: 'social_accounts',
            nombre: 'ck_social_accounts_evidence',
            expresion: "verification_status <> 'verified' "
                .'OR (verified_at IS NOT NULL AND verification_method IS NOT NULL)',
            columnas: ['verification_status', 'verified_at', 'verification_method'],
            mensaje: 'Una cuenta verificada necesita fecha y metodo de verificacion.',
        );

        // `oauth` se exceptúa a propósito: ahí quien verifica es la plataforma,
        // no un operador. Cualquier método manual sí exige una persona detrás.
        Restriccion::comprobacion(
            tabla: 'social_accounts',
            nombre: 'ck_social_accounts_verifier',
            expresion: "verification_method IS NULL OR verification_method = 'oauth' "
                .'OR verified_by_user_id IS NOT NULL',
            columnas: ['verification_method', 'verified_by_user_id'],
            mensaje: 'Una verificacion manual tiene que decir quien la hizo.',
        );

        // --------------------------------------------- H-06: los tres estados
        $sinRevisar = DB::table('social_account_snapshots')->count();

        Schema::table('social_account_snapshots', function (Blueprint $table): void {
            $table->string('coherence_status', 20)->default('pending_review')->after('extra');
        });

        // Las filas que ya existían decían `is_anomalous = 0` sin que nadie
        // hubiera comprobado nada. Se convierten a `pending_review`, que es lo
        // que de verdad eran: no se puede ascender un olvido a «limpio».
        DB::statement("UPDATE social_account_snapshots SET coherence_status = 'pending_review'");

        Schema::table('social_account_snapshots', function (Blueprint $table): void {
            $table->dropIndex('ix_sas_anomaly');
            $table->dropColumn('is_anomalous');
            $table->index(['coherence_status', 'captured_at'], 'ix_sas_anomaly');
        });

        Restriccion::comprobacion(
            tabla: 'social_account_snapshots',
            nombre: 'ck_sas_coherence',
            expresion: "coherence_status IN ('pending_review','clean','anomalous')",
            columnas: ['coherence_status'],
            mensaje: 'Estado de coherencia de la metrica no valido.',
        );
        Restriccion::comprobacion(
            tabla: 'social_account_snapshots',
            nombre: 'ck_sas_anomaly_note',
            expresion: "coherence_status <> 'anomalous' OR anomaly_note IS NOT NULL",
            columnas: ['coherence_status', 'anomaly_note'],
            mensaje: 'Una metrica marcada como anomala tiene que decir por que.',
        );

        // ------------------------------------------- H-07: solo inserción de verdad
        foreach (self::inmutabilidadDelHistorico() as $nombre => $cuerpo) {
            DB::unprepared("CREATE TRIGGER `{$nombre}` {$cuerpo}");
        }

        if ($sinRevisar > 0) {
            // No es un aviso decorativo: son filas que afirmaban estar limpias.
            echo "  {$sinRevisar} snapshots pasan a `pending_review`: decian no ser anomalos ".
                "sin que ningun chequeo se hubiera ejecutado (H-06).\n";
        }
    }

    /**
     * `H-07` — la tabla era «solo inserción» por **convención**.
     *
     * No tiene `updated_at`, y `esquema:verificar` daba eso por bueno. Pero la
     * ausencia de una columna no es un candado: un `DELETE` se llevaba por
     * delante el histórico de métricas, que es con lo que se justifica cuánto
     * se le pagó a un creador. `audit_logs` y `ledger_entries` ya tenían sus
     * disparadores desde 2.4 y 2.13; esta tabla se quedó sin ellos y nadie lo
     * notó hasta que una aserción lo preguntó.
     *
     * Prohibir un *verbo* no lo puede expresar ningún `CHECK`, así que van
     * disparadores — iguales en los dos motores, sin pasar por el compilador.
     *
     * @return array<string, string>
     */
    private static function inmutabilidadDelHistorico(): array
    {
        return [
            'tg_sas_no_update' => <<<'SQL'
                BEFORE UPDATE ON `social_account_snapshots`
                FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'social_account_snapshots es solo-insercion (BR-CREATOR-005): un valor nuevo nunca sobrescribe al anterior.';
                END
                SQL,
            'tg_sas_no_delete' => <<<'SQL'
                BEFORE DELETE ON `social_account_snapshots`
                FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'social_account_snapshots no admite borrado: es la prueba de por que se pago lo que se pago.';
                END
                SQL,
        ];
    }

    public function down(): void
    {
        foreach (array_keys(self::inmutabilidadDelHistorico()) as $nombre) {
            DB::statement("DROP TRIGGER IF EXISTS `{$nombre}`");
        }

        foreach (['ck_sas_anomaly_note', 'ck_sas_coherence'] as $r) {
            Restriccion::quitar('social_account_snapshots', $r);
        }

        Schema::table('social_account_snapshots', function (Blueprint $table): void {
            $table->dropIndex('ix_sas_anomaly');
            $table->boolean('is_anomalous')->default(false)->after('extra');
            $table->index(['is_anomalous', 'captured_at'], 'ix_sas_anomaly');
            $table->dropColumn('coherence_status');
        });

        foreach (['ck_social_accounts_verifier', 'ck_social_accounts_evidence', 'ck_social_accounts_method'] as $r) {
            Restriccion::quitar('social_accounts', $r);
        }

        Restriccion::comprobacion(
            tabla: 'social_accounts',
            nombre: 'ck_social_accounts_verified_at',
            expresion: "verification_status <> 'verified' OR verified_at IS NOT NULL",
            columnas: ['verification_status', 'verified_at'],
            mensaje: 'Una cuenta verificada necesita fecha de verificacion.',
        );

        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropForeign('fk_social_accounts_verifier');
            $table->dropIndex('ix_social_accounts_verifier');
            $table->dropColumn('verified_by_user_id');
        });
    }
};
