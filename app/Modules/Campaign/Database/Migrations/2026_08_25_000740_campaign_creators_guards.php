<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nadie entra en una campaña cerrada (7.4).
 *
 * `campaign_creators` existe desde la Fase 2 y hasta 7.4 **nadie había escrito
 * una fila**. En cuanto se escribe la primera, aparece el hueco: nada impide
 * meter un creador en una campaña `completed` o `cancelled`.
 *
 * No es teórico ni cosmético. Una participación en una campaña terminada:
 *
 * - devenga en el ledger (`9.3`) contra un periodo que ya se liquidó;
 * - aparece en el reporte del cliente (`10.4`), que se prometía reproducible;
 * - y cuenta en el `Creator Score` (`14.3`) por un trabajo que nunca existió.
 *
 * Las tres son consecuencias caras de una fila barata.
 *
 * ### Por qué un disparador y no un `CHECK`
 *
 * Porque la condición está en **otra tabla**: `campaigns.closed_at`. Un `CHECK`
 * sólo puede mirar su propia fila. Es la misma razón por la que
 * `tg_cm_no_quitar_confirmada` (7.3) es un disparador.
 *
 * ### Y por qué también en `UPDATE`
 *
 * Porque cerrar la campaña y mover al creador son dos operaciones distintas, y
 * la segunda puede llegar después. Sin el `BEFORE UPDATE`, un `INSERT` legítimo
 * de ayer se podría mover hoy a `accepted` en una campaña que se cerró en medio
 * — que es exactamente el estado que esta regla existe para impedir.
 *
 * Se deja pasar **una** transición sobre campaña cerrada: pasar a `cancelled`.
 * Cerrar una campaña con candidatos dentro tiene que poder dejarlos resueltos;
 * si no, la única salida sería borrar la fila, y eso es justo lo que `3.12`
 * prohíbe para todo lo que tiene consecuencias económicas.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (self::disparadores() as $sql) {
            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_ccr_campana_cerrada_ins`');
        DB::statement('DROP TRIGGER IF EXISTS `tg_ccr_campana_cerrada_upd`');
    }

    /** @return list<string> */
    private static function disparadores(): array
    {
        // Cabe en 128 caracteres A PROPOSITO. `MESSAGE_TEXT` es VARCHAR(128) y
        // MySQL/Percona no truncan: sueltan `1648 Data too long for condition
        // item` en vez del `45000` que el mensaje queria dar. MariaDB si lo deja
        // pasar, asi que esto solo se ve en produccion. Gate permanente:
        // `tools/verificar-mensajes.py`, que lo mira contra Percona en el CI.
        $mensaje = 'No se anaden creadores a una campana cerrada: lo que se entrego ahi ya se conto. '
            .'Sumar a alguien es una campana nueva.';

        return [
            <<<SQL
                CREATE TRIGGER `tg_ccr_campana_cerrada_ins` BEFORE INSERT ON `campaign_creators`
                FOR EACH ROW
                BEGIN
                  IF EXISTS (SELECT 1 FROM `campaigns`
                              WHERE `id` = NEW.`campaign_id` AND `closed_at` IS NOT NULL)
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$mensaje}';
                  END IF;
                END
                SQL,
            <<<'SQL'
                CREATE TRIGGER `tg_ccr_campana_cerrada_upd` BEFORE UPDATE ON `campaign_creators`
                FOR EACH ROW
                BEGIN
                  IF NEW.`status` <> 'cancelled'
                     AND NOT (NEW.`status` <=> OLD.`status`)
                     AND EXISTS (SELECT 1 FROM `campaigns`
                                  WHERE `id` = NEW.`campaign_id` AND `closed_at` IS NOT NULL)
                  THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'La participacion de una campana cerrada solo se puede cancelar, no avanzar.';
                  END IF;
                END
                SQL,
        ];
    }
};
