<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La invitación (7.6): lo que le faltaba a una tabla diseñada en la Fase 2.
 *
 * ### `invitations` existía y nadie había escrito una fila
 *
 * Tercera vez que pasa —`campaign_creators` antes de 7.4, `domain_events` antes
 * de 4.13, y ahora ésta— y las tres veces la estructura estaba bien pensada:
 * entidad propia porque *«se envía, expira, se reenvía por otro canal, y cuántas
 * veces hubo que insistir alimenta el Creator Score»* (2.2 `P-04`). Incluso
 * guardaba `token_hash` y no el token, dos meses antes de que `5.9` tomara esa
 * misma decisión para las contraseñas.
 *
 * Lo que le faltaba no era diseño: era lo que sólo se ve cuando alguien intenta
 * usarla.
 *
 * ### `amount_snapshot` — el hueco caro
 *
 * `BR-CREATOR-008` dice que el precio vinculante es el **monto acordado
 * congelado**, y `tg_ccr_compromiso` (7.5) lo congela **al aceptar**. Entre que
 * se manda la invitación y el creador contesta, `agreed_amount` se podía mover
 * libremente.
 *
 * O sea: al creador le llega *«te pagamos 1.500»*, alguien lo baja a 900, el
 * creador pulsa «Acepto» dos días después y queda comprometido a 900 sin haberlo
 * visto nunca. No hace falta mala fe — basta con dos personas trabajando sobre la
 * misma campaña.
 *
 * Se cierra por los dos lados:
 *
 * 1. La invitación **copia el importe** con el que salió, igual que
 *    `payment_term_days_snapshot` o `billing_legal_entity_id`. Dentro de un año,
 *    *«¿cuánto le ofrecimos?»* lo responde esta fila.
 * 2. `tg_ccr_monto_con_invitacion` **impide cambiar `agreed_amount` mientras hay
 *    una invitación viva**. Si hay que renegociar, se anula la invitación y se
 *    manda otra — que es exactamente lo que pasa en la realidad.
 *
 * ### Vivas, respondidas y anuladas
 *
 * Misma lección que `password_links` en `5.9`, aplicada aquí: **responder y
 * anular son dos columnas**. `responded_at` tiene que poder contestar *«¿llegó a
 * contestar?»* sin ambigüedad, y una invitación que se sustituye no la contestó
 * nadie.
 *
 * `viva_gate` es la decimocuarta columna puerta del esquema y garantiza **una
 * invitación viva por participación**. Caducar no la mata: la mata el comando que
 * pasa la participación a `expired`, y lo hace anulándola con motivo. Un enlace
 * caducado sigue ocupando su hueco hasta que alguien lo cierra, que es lo que
 * hace que reinvitar sea una decisión y no un accidente.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El plazo vive en la CAMPANA (decision de negocio, 2026-08-26): un solo
        // numero que explicar y un solo sitio donde cambiarlo. Por invitacion
        // seria un dato que hay que decidir cuarenta veces y que nadie mira dos.
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->unsignedSmallInteger('invitation_hours')->default(72)->after('is_gratis');
        });

        Schema::table('invitations', function (Blueprint $table): void {
            // Quien invito. Una invitacion es un compromiso economico con una
            // persona; «alguien la mando» no es una respuesta.
            $table->unsignedBigInteger('invited_by_user_id')->nullable()->after('campaign_creator_id');
            // El importe CON EL QUE SALIO. Ver la cabecera.
            $table->decimal('amount_snapshot', 18, 4)->default(0)->after('expires_at');
            $table->char('currency_snapshot', 3)->nullable()->after('amount_snapshot');
            // Motivo del rechazo, de lista cerrada, mas una nota libre.
            //
            // La lista existe para poder contestar «.por que nos dicen que no?»
            // --si el 70% es el importe, el problema es el presupuesto y no el
            // reclutamiento--. NO decide quien se puede reinvitar: eso se
            // descarto expresamente al elegir «si, y queda constancia de las dos».
            $table->string('decline_reason', 20)->nullable()->after('response');
            $table->string('decline_note', 255)->nullable()->after('decline_reason');
            $table->binary('responded_ip', 16)->nullable()->after('decline_note');
            $table->dateTime('revoked_at', 3)->nullable()->after('responded_ip');
            $table->string('revoked_reason', 40)->nullable()->after('revoked_at');
            $table->dateTime('updated_at', 3)->nullable();

            $table->index('invited_by_user_id', 'ix_inv_invitador');
            $table->foreign('invited_by_user_id', 'fk_inv_invitador')
                ->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE invitations ADD COLUMN viva_gate TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN responded_at IS NULL AND revoked_at IS NULL '
            .'THEN 1 ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE invitations ADD UNIQUE KEY uq_inv_viva (viva_gate, campaign_creator_id)',
        );

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        foreach (self::disparadores() as $sql) {
            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_ccr_monto_con_invitacion`');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        DB::statement('ALTER TABLE invitations DROP INDEX uq_inv_viva');
        DB::statement('ALTER TABLE invitations DROP COLUMN viva_gate');

        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropForeign('fk_inv_invitador');
            $table->dropIndex('ix_inv_invitador');
            $table->dropColumn([
                'invited_by_user_id', 'amount_snapshot', 'currency_snapshot',
                'decline_reason', 'decline_note', 'responded_ip',
                'revoked_at', 'revoked_reason', 'updated_at',
            ]);
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('invitation_hours');
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Entre una hora y treinta dias. El limite de abajo evita el cero
            // --una invitacion que nace caducada-- y el de arriba evita el
            // teclazo que deja un compromiso economico abierto tres anos.
            ['campaigns', 'ck_camp_invitation_hours', 'invitation_hours BETWEEN 1 AND 720',
                ['invitation_hours'],
                'El plazo para contestar una invitacion va de 1 hora a 30 dias.'],

            // Rechazar exige DECIR POR QUE, y aceptar exige NO decirlo: un
            // «aceptada por el importe» seria una fila que no significa nada.
            ['invitations', 'ck_inv_decline',
                "(response = 'declined' AND decline_reason IS NOT NULL)"
                ." OR (response <> 'declined' AND decline_reason IS NULL)"
                .' OR (response IS NULL AND decline_reason IS NULL)',
                ['response', 'decline_reason'],
                'Un rechazo tiene que decir por que; una aceptacion no lleva motivo de rechazo.'],

            ['invitations', 'ck_inv_reason_valido',
                "decline_reason IS NULL OR decline_reason IN ('amount','dates','brand','workload','other')",
                ['decline_reason'],
                'Motivo de rechazo no valido.'],

            // Contestada exige DESDE DONDE, igual que `password_links`: es la
            // fila que se mira cuando alguien dice «yo no acepte eso».
            ['invitations', 'ck_inv_responded_ip', 'responded_at IS NULL OR responded_ip IS NOT NULL',
                ['responded_at', 'responded_ip'],
                'Una invitacion contestada tiene que registrar desde donde se contesto.'],

            ['invitations', 'ck_inv_revoked', 'revoked_at IS NULL OR revoked_reason IS NOT NULL',
                ['revoked_at', 'revoked_reason'],
                'Una invitacion anulada tiene que decir por que se anulo.'],

            // Contestar y anular se excluyen. Si convivieran, `viva_gate`
            // seguiria diciendo «muerta» y la evidencia diria dos cosas a la vez.
            ['invitations', 'ck_inv_terminal', 'responded_at IS NULL OR revoked_at IS NULL',
                ['responded_at', 'revoked_at'],
                'Una invitacion no puede estar contestada y anulada a la vez.'],

            ['invitations', 'ck_inv_amount', 'amount_snapshot >= 0',
                ['amount_snapshot'],
                'El importe de una invitacion no puede ser negativo.'],
        ];
    }

    /** @return list<string> */
    private static function disparadores(): array
    {
        // Cabe en 128 caracteres A PROPOSITO. `MESSAGE_TEXT` es VARCHAR(128) y
        // MySQL/Percona no truncan: sueltan `1648 Data too long for condition
        // item` en vez del `45000` que el mensaje queria dar. MariaDB si lo deja
        // pasar, asi que esto solo se ve en produccion. Gate permanente:
        // `tools/verificar-mensajes.py`, que lo mira contra Percona en el CI.
        $mensaje = 'No se cambia el monto con una invitacion viva (BR-CREATOR-008): '
            .'el creador mira la cifra anterior. Anule esa invitacion.';

        return [
            <<<SQL
                CREATE TRIGGER `tg_ccr_monto_con_invitacion` BEFORE UPDATE ON `campaign_creators`
                FOR EACH ROW
                BEGIN
                  IF NOT (NEW.`agreed_amount` <=> OLD.`agreed_amount`)
                     AND EXISTS (SELECT 1 FROM `invitations`
                                  WHERE `campaign_creator_id` = OLD.`id` AND `viva_gate` = 1)
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$mensaje}';
                  END IF;
                END
                SQL,
        ];
    }
};
