<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El libro mayor deja de ser una tabla y pasa a ser una máquina (9.3).
 *
 * `ledger_entries` está en el esquema desde la Fase 2, con doce `CHECK`,
 * `tg_ledger_no_update` y `tg_ledger_no_delete`. **Y cero filas: nadie escribe
 * en ella todavía.** Antes de que alguien empiece, faltan dos garantías que sólo
 * se ven cuando la tabla tiene datos.
 *
 * ### 1. El estado podía ir a cualquier sitio
 *
 * `tg_ledger_no_update` es **columna por columna** —y eso está bien pensado: deja
 * pasar `status` a propósito, porque `accrued → payable → paid` es el ciclo de
 * vida del asiento y sin él la tabla no serviría—. Pero de ahí no salía **qué
 * transición es válida**: un `paid` podía volver a `accrued`, y eso es dinero ya
 * pagado que reaparece como pendiente de pagar.
 *
 * Es la misma forma de `tg_del_rondas` en `8.4`: un contador que podía bajar.
 *
 * ### 2. Y un devengo se podía crear dos veces
 *
 * Nada impedía dos `earning` para la misma participación. `BR-FIN-015` dice que
 * un devengo exige la participación que lo origina; no decía que fuera **una**.
 * Con `uq_ledger_devengo` la base lo garantiza, y no un `if` de PHP que sólo
 * protege al que pase por él —que es exactamente lo que `8.4` tuvo que arreglar
 * después—.
 *
 * La columna puerta mira `status <> 'void'`: un devengo anulado **libera el
 * sitio**, porque anularlo significa que ese devengo no debió existir y la
 * participación vuelve a poder devengar.
 *
 * ### 3. Y una transición sin motivo es un pago parado que nadie firmó
 *
 * `status_reason` es obligatoria en **toda** transición. En las automáticas la
 * escribe el sistema («las cinco condiciones de `BR-FIN-003` se cumplieron»); en
 * las manuales, la persona. Sin ella, «¿por qué este creador no cobró?» se
 * contesta mirando la fecha y adivinando.
 *
 * **Y `status_changed_at` tiene que CAMBIAR**, no sólo estar presente. La primera
 * versión sólo miraba que no fuera nula, y el motivo de la transición anterior
 * —que sigue en la fila— satisfacía la siguiente: un `UPDATE ... SET
 * status='on_hold'` a secas pasaba, explicándose con el motivo de otro
 * movimiento. Lo cazó la suite, no yo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dateTime('status_changed_at', 3)->nullable()->after('status');
            $table->unsignedBigInteger('status_changed_by_user_id')->nullable()->after('status_changed_at');
            $table->string('status_reason', 255)->nullable()->after('status_changed_by_user_id');

            $table->index('status_changed_by_user_id', 'ix_ledger_status_user');
            $table->foreign('status_changed_by_user_id', 'fk_ledger_status_user')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // UN devengo por participacion. `status <> 'void'` a proposito: anular un
        // devengo significa que no debio existir, y entonces la participacion
        // tiene que poder volver a devengar.
        DB::statement(
            'ALTER TABLE `ledger_entries` ADD COLUMN `devengo_gate` TINYINT UNSIGNED '
            ."GENERATED ALWAYS AS (CASE WHEN `entry_type` = 'earning' AND `status` <> 'void' "
            .'THEN 1 ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE `ledger_entries` ADD UNIQUE KEY `uq_ledger_devengo` '
            .'(`devengo_gate`, `campaign_creator_id`)',
        );

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // La maquina de estados. Cross-row --mira OLD y NEW-- asi que ningun
        // CHECK puede verla: es un disparador en los dos motores.
        //
        // `paid` y `void` son TERMINALES. Un pago que se deshace no se deshace
        // cambiando este estado: se corrige con un asiento de `payment_reversal`,
        // que es lo que dice `BR-FIN-002` y lo que deja rastro de las dos cosas.
        DB::statement('DROP TRIGGER IF EXISTS `tg_ledger_estado`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_ledger_estado`
            BEFORE UPDATE ON `ledger_entries`
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.`status` <=> OLD.`status`) THEN
                    IF NOT (
                           (OLD.`status` = 'accrued' AND NEW.`status` IN ('payable','on_hold','void'))
                        OR (OLD.`status` = 'payable' AND NEW.`status` IN ('paid','on_hold','void'))
                        OR (OLD.`status` = 'on_hold' AND NEW.`status` IN ('payable','void'))
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Ese cambio de estado no existe en el libro mayor: un pagado o un anulado no vuelven.';
                    END IF;

                    IF NEW.`status_reason` IS NULL OR NEW.`status_reason` = ''
                       OR NEW.`status_changed_at` IS NULL
                       OR NEW.`status_changed_at` <=> OLD.`status_changed_at` THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Mover un asiento exige decir cuando y por que, en ESE movimiento.';
                    END IF;
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_ledger_estado`');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        DB::statement('ALTER TABLE `ledger_entries` DROP INDEX `uq_ledger_devengo`');
        DB::statement('ALTER TABLE `ledger_entries` DROP COLUMN `devengo_gate`');

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropForeign('fk_ledger_status_user');
            $table->dropIndex('ix_ledger_status_user');
            $table->dropColumn(['status_changed_at', 'status_changed_by_user_id', 'status_reason']);
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Un asiento recien nacido no ha cambiado de estado: los tres campos
            // van juntos o no van. Media firma parece que la pregunta «quien lo
            // movio» tiene respuesta.
            ['ledger_entries', 'ck_ledger_status_firma',
                'status_changed_at IS NULL OR status_reason IS NOT NULL',
                ['status_changed_at', 'status_reason'],
                'Mover un asiento exige decir por que.'],
            // Un asiento de pago nace `paid`; los demas nacen `accrued`. Nacer
            // `payable` sin pasar por `accrued` se salta las cinco condiciones.
            ['ledger_entries', 'ck_ledger_estado_inicial',
                "status_changed_at IS NOT NULL OR status IN ('accrued','paid')",
                ['status_changed_at', 'status'],
                'Un asiento nace devengado o pagado; a pagable se LLEGA.'],
        ];
    }
};
