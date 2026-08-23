<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `H-09` — se podía pagar a una cuenta que nadie había verificado.
 *
 * `fk_payout_method` solo comprobaba que la fila **existiera**. Se reprodujo
 * contra una base real antes de escribir una línea de esto: un pago de
 * `1500.0000 PEN` contra un medio en estado `pending`, sin verificar y sin
 * fecha de elegibilidad, entraba sin protestar.
 *
 * `BR-FIN-003` dice que un *earning* solo pasa a `payable` cuando, entre otras
 * cosas, «el medio de pago está verificado». Esa regla estaba **escrita**, no
 * impuesta: vivía en `CompletitudOperativa`, que decide si un creador se activa
 * — no en el camino del dinero. Y `BR-FIN-006`, el enfriamiento, no se
 * consultaba en ningún sitio al pagar.
 *
 * Tampoco se comprobaba que la cuenta fuera **del creador al que se le paga**.
 *
 * Va en disparadores y no en `CHECK` porque mira otra tabla, y eso no lo puede
 * hacer ningún `CHECK` ni en MySQL ni en MariaDB.
 *
 * El segundo disparador no es adorno: sin él, la comprobación del primero se
 * saltaría con un `UPDATE` detrás — se inserta el pago apuntando a una cuenta
 * buena y luego se cambia el destino.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (self::disparadores() as $nombre => $cuerpo) {
            DB::unprepared("CREATE TRIGGER `{$nombre}` {$cuerpo}");
        }
    }

    /**
     * @return array<string, string>
     */
    private static function disparadores(): array
    {
        return [
            'tg_payout_medio_valido' => <<<'SQL'
                BEFORE INSERT ON `payouts`
                FOR EACH ROW
                BEGIN
                  DECLARE v_creador BIGINT UNSIGNED;
                  DECLARE v_estado  VARCHAR(15);
                  DECLARE v_desde   DATETIME(3);

                  SELECT creator_id, status, eligible_from
                    INTO v_creador, v_estado, v_desde
                    FROM creator_payment_methods
                   WHERE id = NEW.payment_method_id;

                  IF v_creador <> NEW.creator_id THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'El medio de pago no es del creador al que se le paga.';
                  END IF;

                  IF v_estado <> 'verified' THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'No se paga a un medio sin verificar (BR-FIN-003).';
                  END IF;

                  IF v_desde IS NULL OR v_desde > NOW(3) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'El medio de pago sigue en el periodo de enfriamiento (BR-FIN-006).';
                  END IF;
                END
                SQL,
            'tg_payout_medio_inmutable' => <<<'SQL'
                BEFORE UPDATE ON `payouts`
                FOR EACH ROW
                BEGIN
                  IF NEW.payment_method_id <> OLD.payment_method_id OR NEW.creator_id <> OLD.creator_id THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'El destino de un pago no se cambia: anule el pago y cree otro.';
                  END IF;
                END
                SQL,
        ];
    }

    public function down(): void
    {
        foreach (array_keys(self::disparadores()) as $nombre) {
            DB::statement("DROP TRIGGER IF EXISTS `{$nombre}`");
        }
    }
};
