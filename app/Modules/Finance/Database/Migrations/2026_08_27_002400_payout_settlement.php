<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué paga cada pago, y que lo pague quien debe (9.6).
 *
 * ### El hueco que `DEC-157` mandó cerrar aquí
 *
 * `campaigns.billing_legal_entity_id` dice quién factura. `payout_batches.legal_entity_id`
 * dice quién paga. **Entre los dos no había nada**: un lote de CTS Colombia podía
 * pagar el trabajo de una campaña de CTS Perú y ninguna restricción lo notaba.
 * Eso es una operación intercompañía sin documentar — precios de transferencia,
 * consolidación, retención en la fuente— que es justo lo que `DEC-020` existía
 * para evitar.
 *
 * El roadmap tenía esa comprobación en `9.11`, la undécima de catorce.
 * `DEC-157` la adelantó **a la iteración que estrene `payout_batches`**, y es
 * ésta.
 *
 * ### Y para poder comprobarlo hacía falta saber qué paga cada pago
 *
 * `ledger_entries.payout_id` ata **un** asiento de pago a su `payout`
 * (`BR-FIN-013`, `uq_ledger_payout`). Lo que no existía era el detalle: **qué
 * devengos liquida ese pago**. Sin eso, «¿de qué campañas es este dinero?» no
 * tiene respuesta, y sin respuesta no hay nada que comparar con la sociedad del
 * lote.
 *
 * `payout_earnings` es ese detalle. Y con él la regla se vuelve una consulta de
 * dos saltos que cabe en un disparador.
 *
 * ### `voided_at` y no borrar
 *
 * Sacar un pago de un lote aprobado —porque se retuvo un devengo entre la firma
 * y la ejecución— anula su liquidación, **no la borra**: es evidencia de que
 * estuvo dentro y de que alguien lo sacó. La columna puerta hace que un devengo
 * sólo pueda estar en **una liquidación viva**, y que al anularla vuelva a la
 * cola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_earnings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payout_id');
            $table->foreignId('ledger_entry_id');
            // Copia del importe liquidado. Un devengo se liquida entero hoy,
            // pero el pago parcial existe en el mundo y esta columna es lo que
            // permitiria admitirlo sin rehacer la tabla.
            $table->decimal('amount', 18, 4);
            $table->dateTime('voided_at', 3)->nullable();
            $table->unsignedBigInteger('voided_by_user_id')->nullable();
            $table->string('voided_reason', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index('payout_id', 'ix_pe_payout');
            $table->index('voided_by_user_id', 'ix_pe_voider');

            $table->foreign('payout_id', 'fk_pe_payout')
                ->references('id')->on('payouts')->restrictOnDelete();
            $table->foreign('ledger_entry_id', 'fk_pe_asiento')
                ->references('id')->on('ledger_entries')->restrictOnDelete();
            $table->foreign('voided_by_user_id', 'fk_pe_voider')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // Un devengo, una liquidacion VIVA. Anularla lo devuelve a la cola.
        DB::statement(
            'ALTER TABLE `payout_earnings` ADD COLUMN `viva_gate` TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN `voided_at` IS NULL THEN 1 ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE `payout_earnings` ADD UNIQUE KEY `uq_pe_viva` (`viva_gate`, `ledger_entry_id`)',
        );

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // ------------------------------------------------------ `BR-LE-009`
        //
        // La sociedad del LOTE tiene que ser la de la CAMPANA del devengo que
        // liquida. Es cross-table --tres saltos-- asi que ningun CHECK puede
        // verla: es un disparador en los dos motores.
        //
        // Se comprueba al ENGANCHAR el devengo al pago y no al aprobar el lote:
        // aprobar es una firma sobre algo que ya tiene que estar bien, y una
        // regla que solo mira al final deja existir el estado malo mientras
        // tanto --que es como alguien lo ve, lo exporta, o lo cree--.
        DB::statement('DROP TRIGGER IF EXISTS `tg_pe_sociedad`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_pe_sociedad`
            BEFORE INSERT ON `payout_earnings`
            FOR EACH ROW
            BEGIN
                DECLARE v_lote BIGINT UNSIGNED;
                DECLARE v_campana BIGINT UNSIGNED;
                DECLARE v_estado VARCHAR(15);

                SELECT pb.`legal_entity_id` INTO v_lote
                  FROM `payouts` p JOIN `payout_batches` pb ON pb.`id` = p.`payout_batch_id`
                 WHERE p.`id` = NEW.`payout_id`;

                SELECT c.`billing_legal_entity_id`, le.`status`
                  INTO v_campana, v_estado
                  FROM `ledger_entries` le
                  JOIN `campaign_creators` cc ON cc.`id` = le.`campaign_creator_id`
                  JOIN `campaigns` c ON c.`id` = cc.`campaign_id`
                 WHERE le.`id` = NEW.`ledger_entry_id`;

                IF v_estado IS NULL OR v_estado <> 'payable' THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Solo se liquida un devengo pagable, y con la campana que lo origina.';
                END IF;

                IF NOT (v_lote <=> v_campana) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'La sociedad que paga tiene que ser la de la campana (BR-LE-009).';
                END IF;
            END
            SQL);

        // Una liquidacion no se reescribe: solo se anula, y anularla exige decir
        // quien y por que. Misma forma que `tg_ledger_estado`.
        DB::statement('DROP TRIGGER IF EXISTS `tg_pe_inmutable`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_pe_inmutable`
            BEFORE UPDATE ON `payout_earnings`
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.`payout_id` <=> OLD.`payout_id`)
                   OR NOT (NEW.`ledger_entry_id` <=> OLD.`ledger_entry_id`)
                   OR NOT (NEW.`amount` <=> OLD.`amount`) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Una liquidacion no se reescribe: se anula.';
                END IF;

                IF NEW.`voided_at` IS NOT NULL
                   AND (NEW.`voided_by_user_id` IS NULL OR NEW.`voided_reason` IS NULL) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Sacar un pago de un lote exige quien lo saco y por que.';
                END IF;
            END
            SQL);

        DB::statement('DROP TRIGGER IF EXISTS `tg_pe_no_delete`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_pe_no_delete`
            BEFORE DELETE ON `payout_earnings`
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'payout_earnings no admite borrado: dice que devengos pago cada pago.';
            END
            SQL);
    }

    public function down(): void
    {
        foreach (['tg_pe_sociedad', 'tg_pe_inmutable', 'tg_pe_no_delete'] as $disparador) {
            DB::statement("DROP TRIGGER IF EXISTS `{$disparador}`");
        }

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('payout_earnings');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['payout_earnings', 'ck_pe_amount', 'amount > 0', ['amount'],
                'Una liquidacion de cero no liquida nada.'],
            ['payout_earnings', 'ck_pe_void', 'voided_at IS NULL OR voided_reason IS NOT NULL',
                ['voided_at', 'voided_reason'],
                'Sacar un pago de un lote exige decir por que.'],
        ];
    }
};
