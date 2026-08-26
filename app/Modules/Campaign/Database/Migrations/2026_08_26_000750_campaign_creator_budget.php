<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El presupuesto de creadores, y el compromiso congelado (7.5).
 *
 * ### `BR-CAMPAIGN-005` no es que nadie la comprobara: es que NO SE PODÍA
 *
 * > *El costo comprometido con creadores no puede exceder el **presupuesto de
 * > creadores de la campaña** sin aprobación explícita de un rol autorizado, que
 * > queda auditada.* 🔴
 *
 * `campaigns` tiene `revenue_amount` —lo que se le cobra al cliente— y **nada
 * más**. El presupuesto de creadores, que es el otro lado del margen, no existe
 * como columna. Es el cuarto caso del patrón de 7.1, 7.2, 7.3 y 7.4, y el más
 * grave de los cinco: en los otros la regla estaba escrita y sin código detrás;
 * aquí el dato que la regla nombra **no estaba en el modelo**.
 *
 * ### Las tres columnas de la autorización van juntas o no van
 *
 * Decisión de negocio (2026-08-26): pasarse del presupuesto **se bloquea**, y
 * finanzas puede autorizarlo dejando el motivo. La regla dice «que queda
 * auditada», así que la autorización es un dato de la fila: quién, cuándo y por
 * qué.
 *
 * `ck_camp_budget_override` exige que las tres estén o no estén. Una
 * autorización sin motivo es una firma sin explicación, y dentro de un año
 * «¿por qué esta campaña se pasó 3.000 soles?» tiene que responderlo la fila.
 * Misma forma que `ck_inv_responded`.
 *
 * ### Y el compromiso se congela al ACEPTAR
 *
 * `BR-CREATOR-008`: *la tarifa declarada es una referencia; el precio vinculante
 * es el monto acordado congelado en la participación*.
 *
 * Decisión de negocio (2026-08-26): el importe **se fija al invitar** —el creador
 * no puede aceptar un número que no ha visto— y **se congela al aceptar**.
 * Mientras está invitado se corrige y se reenvía; en cuanto acepta, cambiarlo
 * exige una enmienda (`BR-CAMPAIGN-003`), no un `UPDATE`.
 *
 * Es la misma forma que `tg_camp_entidad_congelada` (7.1), y por la misma razón:
 * de este número sale lo que se le paga a una persona. Tiene que sobrevivir a un
 * mantenimiento y a la próxima pantalla que alguien escriba.
 *
 * ### Por qué un disparador y no un `CHECK`
 *
 * El congelado compara `OLD` con `NEW`, que un `CHECK` no ve. Y la segunda regla
 * —a partir de `invited` el importe está declarado— tiene que mirar
 * `campaigns.is_gratis`: una campaña regalada (7.2) puede tener creadores por
 * canje, y exigirles importe sería contradecir la decisión de 7.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            // Nace en 0 como `revenue_amount`, y como aquel, un cero fuera de
            // borrador hay que poder explicarlo. Aqui no hace falta columna
            // nueva: cero comprometido es cero gastado, y eso se ve solo.
            $table->decimal('creator_budget_amount', 18, 4)->default(0)->after('is_gratis');
            $table->unsignedBigInteger('budget_override_by_user_id')->nullable()->after('creator_budget_amount');
            $table->dateTime('budget_override_at', 3)->nullable()->after('budget_override_by_user_id');
            $table->string('budget_override_reason', 255)->nullable()->after('budget_override_at');

            $table->foreign('budget_override_by_user_id', 'fk_camp_budget_override')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Restriccion::comprobacion(
            tabla: 'campaigns',
            nombre: 'ck_camp_creator_budget',
            expresion: 'creator_budget_amount >= 0',
            columnas: ['creator_budget_amount'],
            mensaje: 'El presupuesto de creadores no puede ser negativo.',
        );

        Restriccion::comprobacion(
            tabla: 'campaigns',
            nombre: 'ck_camp_budget_override',
            expresion: '(budget_override_at IS NULL AND budget_override_by_user_id IS NULL '
                .'AND budget_override_reason IS NULL) '
                .'OR (budget_override_at IS NOT NULL AND budget_override_by_user_id IS NOT NULL '
                .'AND budget_override_reason IS NOT NULL)',
            columnas: ['budget_override_at', 'budget_override_by_user_id', 'budget_override_reason'],
            mensaje: 'Autorizar un sobrecosto exige quien, cuando y por que: las tres o ninguna (BR-CAMPAIGN-005).',
        );

        DB::unprepared(self::disparador());
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_ccr_compromiso`');

        Restriccion::quitar('campaigns', 'ck_camp_budget_override');
        Restriccion::quitar('campaigns', 'ck_camp_creator_budget');

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropForeign('fk_camp_budget_override');
            $table->dropColumn([
                'creator_budget_amount', 'budget_override_by_user_id',
                'budget_override_at', 'budget_override_reason',
            ]);
        });
    }

    private static function disparador(): string
    {
        return <<<'SQL'
            CREATE TRIGGER `tg_ccr_compromiso` BEFORE UPDATE ON `campaign_creators`
            FOR EACH ROW
            BEGIN
              IF OLD.`accepted_at` IS NOT NULL
                 AND NOT (NEW.`agreed_amount` <=> OLD.`agreed_amount`)
              THEN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'El monto acordado de una participacion aceptada no se cambia (BR-CREATOR-008): exige una enmienda aceptada por las dos partes.';
              END IF;

              IF NEW.`status` NOT IN ('shortlisted', 'cancelled')
                 AND NEW.`agreed_amount` <= 0
                 AND NOT EXISTS (SELECT 1 FROM `campaigns`
                                  WHERE `id` = NEW.`campaign_id` AND `is_gratis` = 1)
              THEN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'No se invita a un creador sin decirle cuanto se le paga (BR-CREATOR-008). Si la campana es un canje, marquela como gratuita.';
              END IF;
            END
            SQL;
    }
};
