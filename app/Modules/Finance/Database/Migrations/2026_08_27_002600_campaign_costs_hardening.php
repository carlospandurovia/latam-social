<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El gasto de una campaña deja de ser una tabla vacía (9.10a).
 *
 * `campaign_costs` existe desde la Fase 2 con cinco restricciones, su
 * `tg_cco_no_delete` — y **cero filas y ni una línea de PHP que escriba en
 * ella**. Es el mismo estado en que estaban `exchange_rates` antes de `9.1` y
 * `ledger_entries` antes de `9.3`: el esquema decía la verdad y nadie la usaba.
 *
 * Lo que faltaba, además del código:
 *
 * ### Un costo se podía reescribir
 *
 * El comentario de la tabla dice, desde la Fase 2, que un costo mal tecleado
 * *«se anula, no se borra»*, **porque el margen de ayer tiene que poder
 * reconstruirse**. `tg_cco_no_delete` impedía el `DELETE` y nada impedía el
 * `UPDATE`: cambiar el importe de 4.000 a 400 dejaba el margen de ayer
 * irrecuperable exactamente igual que borrarlo, y sin dejar rastro de que
 * alguien lo hizo. `tg_cco_inmutable` cierra la puerta que quedaba abierta —
 * mismo criterio que `tg_pe_inmutable` en `9.6`.
 *
 * ### Un gasto no se incurre mañana
 *
 * `incurred_on` es la fecha en que se gastó, y eso es pasado. Un `2027` tecleado
 * por un `2026` mete el costo en un ejercicio que no es, y quien lo busque el
 * mes que viene no lo va a encontrar. Va en disparador y no en `CHECK` porque
 * un `CHECK` no puede llamar a `CURDATE()`.
 *
 * Se admite **un día de margen**: la máquina y el usuario pueden estar en husos
 * distintos, y rechazar el gasto de hoy porque en UTC ya es mañana sería un
 * error que nadie sabría explicar.
 *
 * ### Una descripción vacía no describe nada
 *
 * `description` era `NOT NULL`, que en MySQL admite la cadena vacía. Un costo
 * sin descripción es una cifra que dentro de seis meses no se puede auditar.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // Un costo vivo no se reescribe: se anula y se vuelve a anotar. Lo unico
        // que un UPDATE puede tocar son las tres columnas de la anulacion.
        DB::statement('DROP TRIGGER IF EXISTS `tg_cco_inmutable`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_cco_inmutable`
            BEFORE UPDATE ON `campaign_costs`
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.`campaign_id` <=> OLD.`campaign_id`)
                   OR NOT (NEW.`cost_type` <=> OLD.`cost_type`)
                   OR NOT (NEW.`amount` <=> OLD.`amount`)
                   OR NOT (NEW.`currency_code` <=> OLD.`currency_code`)
                   OR NOT (NEW.`incurred_on` <=> OLD.`incurred_on`)
                   OR NOT (NEW.`description` <=> OLD.`description`)
                   OR NOT (NEW.`file_id` <=> OLD.`file_id`) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Un costo no se reescribe: se anula y se vuelve a anotar.';
                END IF;

                IF OLD.`voided_at` IS NOT NULL AND NOT (NEW.`voided_at` <=> OLD.`voided_at`) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Un costo ya anulado no se desanula: vuelva a anotarlo.';
                END IF;
            END
            SQL);

        // Un gasto se incurre en el pasado. Un dia de margen por los husos.
        DB::statement('DROP TRIGGER IF EXISTS `tg_cco_fecha`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_cco_fecha`
            BEFORE INSERT ON `campaign_costs`
            FOR EACH ROW
            BEGIN
                IF NEW.`incurred_on` > DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Un gasto no se incurre en el futuro: revise la fecha.';
                END IF;
            END
            SQL);

        // ------------------------------------------------------------------
        // El margen deja de verlo quien lleva la campana (DEC-181)
        //
        // Y NO basta con quitarlo de la matriz del seeder: `CimientosSeeder`
        // usa `updateOrInsert`, asi que CONCEDE y no revoca nunca. Quitar la
        // linea deja intacta la concesion en cualquier base que ya la tenga
        // --incluida produccion--, y el permiso seguiria puesto sin que nada
        // lo dijera. Se revoca aqui, que es el unico sitio que corre en todas.
        // ------------------------------------------------------------------
        $permiso = DB::table('permissions')->where('code', 'campaign.view_margin')->value('id');
        $rol = DB::table('roles')->where('code', 'campaign_manager')->value('id');

        if ($permiso !== null && $rol !== null) {
            DB::table('permission_role')
                ->where('role_id', $rol)->where('permission_id', $permiso)->delete();
        }
    }

    public function down(): void
    {
        $permiso = DB::table('permissions')->where('code', 'campaign.view_margin')->value('id');
        $rol = DB::table('roles')->where('code', 'campaign_manager')->value('id');

        if ($permiso !== null && $rol !== null) {
            DB::table('permission_role')->updateOrInsert(
                ['role_id' => $rol, 'permission_id' => $permiso],
            );
        }

        foreach (['tg_cco_inmutable', 'tg_cco_fecha'] as $disparador) {
            DB::statement("DROP TRIGGER IF EXISTS `{$disparador}`");
        }

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // `NOT NULL` admite la cadena vacia, y una cifra sin descripcion no
            // se puede auditar dentro de seis meses.
            ['campaign_costs', 'ck_cco_descripcion', "TRIM(description) <> ''", ['description'],
                'Un costo sin descripcion es una cifra que nadie va a poder explicar.'],
        ];
    }
};
