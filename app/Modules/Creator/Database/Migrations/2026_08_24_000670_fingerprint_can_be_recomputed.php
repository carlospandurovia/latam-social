<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La huella de una cuenta se puede recalcular; la cuenta no (iteración 3.14, `T-11`).
 *
 * `tg_cpm_inmutable` (`H-12`) trataba `account_number_fingerprint` como parte de
 * la cuenta y bloqueaba cualquier cambio. Eso hacía **imposible** cumplir `T-11`:
 * el día que se rote `APP_KEY`, las huellas —que son un HMAC con esa clave—
 * dejan de casar y la detección de cuentas repetidas (`DEC-065`) se apaga en
 * silencio sobre las filas viejas. Sin poder reescribir la huella no había
 * forma de arreglarlo salvo dar de alta todas las cuentas otra vez.
 *
 * La huella **no es la cuenta**: es un índice derivado de ella. Así que la regla
 * se afina en vez de relajarse:
 *
 * - `account_number_encrypted` sigue siendo inmutable, igual que antes.
 * - `account_number_fingerprint` puede cambiar **solo mientras el cifrado se
 *   queda donde estaba**, que es exactamente el caso «misma cuenta, clave
 *   nueva».
 *
 * Lo garantiza la propia comprobación que ya existía: si alguien intenta cambiar
 * el número, cambia el cifrado, y eso se rechaza igual que siempre. Si el
 * cifrado es el mismo, la cuenta es la misma, y volver a derivar su índice no es
 * editarla.
 *
 * Lo hace `php artisan pagos:recalcular-huellas`.
 */
return new class extends Migration
{
    public function up(): void
    {
        self::rehacer(conHuella: false);
    }

    public function down(): void
    {
        self::rehacer(conHuella: true);
    }

    private static function rehacer(bool $conHuella): void
    {
        $huella = $conHuella
            ? "\n     OR NEW.account_number_fingerprint <> OLD.account_number_fingerprint"
            : '';

        DB::statement('DROP TRIGGER IF EXISTS `tg_cpm_inmutable`');
        DB::unprepared(<<<SQL
            CREATE TRIGGER `tg_cpm_inmutable` BEFORE UPDATE ON creator_payment_methods
            FOR EACH ROW
            BEGIN
              IF NEW.creator_id <> OLD.creator_id
                 OR NEW.uuid <> OLD.uuid
                 OR NEW.method_type <> OLD.method_type
                 OR NEW.country_id <> OLD.country_id
                 OR NEW.currency_code <> OLD.currency_code
                 OR NEW.owner_type <> OLD.owner_type
                 OR NOT (NEW.owner_guardian_id <=> OLD.owner_guardian_id)
                 OR NEW.account_number_encrypted <> OLD.account_number_encrypted
                 OR NEW.account_number_masked <> OLD.account_number_masked{$huella}
                 OR NEW.holder_name <> OLD.holder_name
                 OR NEW.holder_document_type <> OLD.holder_document_type
                 OR NEW.holder_document_number <> OLD.holder_document_number
                 OR NEW.created_by_user_id <> OLD.created_by_user_id
              THEN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'La cuenta de un medio de pago es inmutable (H-12): de alta una nueva y desactive esta.';
              END IF;

              IF OLD.verified_at IS NOT NULL
                 AND (NOT (NEW.verified_at <=> OLD.verified_at)
                      OR NOT (NEW.verified_by_user_id <=> OLD.verified_by_user_id))
              THEN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'La verificacion de un medio de pago no se reescribe.';
              END IF;

              IF OLD.eligible_from IS NOT NULL AND NOT (NEW.eligible_from <=> OLD.eligible_from) THEN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'La fecha de elegibilidad no se cambia una vez fijada (BR-FIN-006).';
              END IF;
            END
            SQL);
    }
};
