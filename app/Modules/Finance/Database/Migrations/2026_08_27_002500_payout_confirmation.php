<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El pago confirmado contra el extracto, y el que vuelve (9.7).
 *
 * `9.6` deja los pagos en `sent`. Eso significa **«lo mandamos»**, no «llegó»:
 * entre las dos cosas está el banco, y ahí un pago se rechaza porque la cuenta
 * estaba mal, porque el titular no coincide, o porque el archivo iba mal.
 *
 * `payouts` ya tenía los cinco estados y sus fechas desde la Fase 2. Lo que
 * faltaba era **el grafo** —igual que en `ledger_entries` antes de `9.3`— y el
 * comprobante.
 *
 * ### Qué exige cada final
 *
 * Confirmar exige **referencia bancaria y fecha valor**: sin ellas, «este pago
 * está confirmado» es la palabra de quien lo marcó, y conciliar un extracto
 * dentro de seis meses se vuelve imposible.
 *
 * Devolver exige **motivo**. Un pago que vuelve y no dice por qué se reintenta a
 * ciegas contra la misma cuenta equivocada.
 *
 * ### El comprobante
 *
 * `proof_file_id` cuelga de `files`, como las evidencias de publicación de
 * `8.7`. Es opcional a propósito: el extracto puede llegar como una línea en una
 * pantalla del banco antes que como un PDF, y bloquear la confirmación por un
 * archivo dejaría pagos «enviados» que todo el mundo sabe que llegaron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('proof_file_id')->nullable()->after('bank_reference');
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable()->after('confirmed_at');
            $table->unsignedBigInteger('returned_by_user_id')->nullable()->after('returned_at');

            $table->index('proof_file_id', 'ix_payout_proof');
            $table->index('confirmed_by_user_id', 'ix_payout_confirmer');
            $table->index('returned_by_user_id', 'ix_payout_returner');

            $table->foreign('proof_file_id', 'fk_payout_proof')
                ->references('id')->on('files')->restrictOnDelete();
            $table->foreign('confirmed_by_user_id', 'fk_payout_confirmer')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('returned_by_user_id', 'fk_payout_returner')
                ->references('id')->on('users')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // El grafo de estados de un pago. Mismo criterio que `tg_ledger_estado`:
        // `confirmed` y `returned` son FINALES --lo que pasa despues es un pago
        // nuevo, no este cambiando de opinion--.
        DB::statement('DROP TRIGGER IF EXISTS `tg_payout_estado`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_payout_estado`
            BEFORE UPDATE ON `payouts`
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.`status` <=> OLD.`status`) THEN
                    IF NOT (
                           (OLD.`status` = 'pending' AND NEW.`status` IN ('sent','cancelled'))
                        OR (OLD.`status` = 'sent' AND NEW.`status` IN ('confirmed','returned'))
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Ese cambio de estado no existe en un pago: confirmado y devuelto son finales.';
                    END IF;
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_payout_estado`');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropForeign('fk_payout_proof');
            $table->dropForeign('fk_payout_confirmer');
            $table->dropForeign('fk_payout_returner');
            $table->dropIndex('ix_payout_proof');
            $table->dropIndex('ix_payout_confirmer');
            $table->dropIndex('ix_payout_returner');
            $table->dropColumn(['proof_file_id', 'confirmed_by_user_id', 'returned_by_user_id']);
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Sin referencia y fecha valor, «confirmado» es la palabra de quien
            // lo marco, y conciliar un extracto dentro de seis meses se vuelve
            // imposible.
            ['payouts', 'ck_payout_conciliado',
                "status <> 'confirmed' OR (bank_reference IS NOT NULL AND value_date IS NOT NULL "
                .'AND confirmed_at IS NOT NULL AND confirmed_by_user_id IS NOT NULL)',
                ['status', 'bank_reference', 'value_date', 'confirmed_at', 'confirmed_by_user_id'],
                'Confirmar un pago exige referencia, fecha valor, cuando y quien.'],
            // Un pago que vuelve y no dice por que se reintenta a ciegas contra
            // la misma cuenta equivocada.
            ['payouts', 'ck_payout_devuelto',
                "status <> 'returned' OR (return_reason IS NOT NULL AND returned_by_user_id IS NOT NULL)",
                ['status', 'return_reason', 'returned_by_user_id'],
                'Devolver un pago exige el motivo y quien lo registro.'],
        ];
    }
};
