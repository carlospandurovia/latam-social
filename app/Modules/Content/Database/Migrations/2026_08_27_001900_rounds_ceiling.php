<?php

declare(strict_types=1);

namespace App\Modules\Content\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El techo de rondas, en la base (8.4).
 *
 * `8.3` construyó el límite entero —`Revisiones::rondas()` calcula `agotadas`,
 * `vetoParaRevisar()` bloquea, `content.extra_round` autoriza y `over_included`
 * queda registrado— y lo dejó **todo en PHP**. Esta iteración no añade una
 * regla nueva: pone en la base las que ya se estaban aplicando, porque un `if`
 * de un servicio sólo protege al que pasa por ese servicio.
 *
 * Y hay una fecha para eso: **`8.5` escribe revisiones del cliente desde un
 * enlace firmado**, sin pasar por la pantalla de revisión. El agujero no es
 * teórico, está a una iteración de abrirse solo.
 *
 * ### Lo que faltaba, medido
 *
 * | Regla | Dónde vivía | Qué permitía |
 * |---|---|---|
 * | Sólo la corrección del **cliente** gasta ronda (`DEC-133`) | `consumeRonda()` | una revisión NUESTRA podía gastar una ronda del cliente |
 * | `over_included` dice la verdad | `vetoParaRevisar()` | una ronda de más sin firma, sin decisión de facturación y **sin cargo** |
 * | El contador no baja | nada | ponerlo a cero regalaba las rondas ya gastadas |
 *
 * La segunda es la cara: `over_included` es lo que `Revisiones::rondasCobrables()`
 * cuenta para facturar. Una fila que miente ahí es dinero que no se cobra.
 *
 * ### Los disparadores siguen siendo GUARDAS, no mecanismos
 *
 * Ninguno de los 502 disparadores de este esquema modifica una fila: todos
 * comprueban y hacen `SIGNAL`. Aquí se mantiene. Era tentador hacer que el
 * contador lo subiera un `AFTER INSERT` —así no podría desincronizarse nunca—
 * pero eso convierte el esquema en algo que **hace cosas** a espaldas de quien
 * lee el código, y la duda de si el contador debería existir o derivarse de un
 * `COUNT(*)` se decide cuando eso sea dinero de verdad (`Q-60`, `F9`).
 */
return new class extends Migration
{
    public function up(): void
    {
        self::exigirDatosLimpios();

        Restriccion::quitar('content_reviews', 'ck_cvw_round');
        Restriccion::comprobacion(
            tabla: 'content_reviews',
            nombre: 'ck_cvw_round',
            expresion: "consumes_round = 0 OR (outcome = 'changes_requested' AND reviewer_side = 'client')",
            columnas: ['consumes_round', 'outcome', 'reviewer_side'],
            mensaje: 'Solo la correccion del CLIENTE gasta ronda: las nuestras no cuentan contra el precio.',
        );

        foreach (self::disparadores() as $sql) {
            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        foreach (['tg_cvw_techo', 'tg_del_rondas'] as $disparador) {
            DB::statement("DROP TRIGGER IF EXISTS `{$disparador}`");
        }

        Restriccion::quitar('content_reviews', 'ck_cvw_round');
        Restriccion::comprobacion(
            tabla: 'content_reviews',
            nombre: 'ck_cvw_round',
            expresion: "consumes_round = 0 OR outcome = 'changes_requested'",
            columnas: ['consumes_round', 'outcome'],
            mensaje: 'Solo una correccion gasta ronda.',
        );
    }

    /**
     * No se reescribe historia de revisiones: se para y se avisa.
     *
     * Ninguna ruta de la aplicación puede crear estas filas —`emitir()` sólo
     * marca `consumes_round` cuando el lado es `client`— así que en una
     * instalación real esto es cero. Si no lo es, alguien las metió por fuera y
     * arreglarlas a ciegas sería inventarse qué pasó: de estas filas cuelga si
     * una ronda se cobró o se absorbió.
     */
    private static function exigirDatosLimpios(): void
    {
        $sospechosas = (int) DB::table('content_reviews')
            ->where('consumes_round', 1)
            ->where('reviewer_side', '<>', 'client')
            ->count();

        if ($sospechosas > 0) {
            throw new RuntimeException(
                "Hay {$sospechosas} revision(es) que gastan ronda sin ser del cliente, y esta migracion "
                .'las volveria imposibles. Mirelas antes de continuar: SELECT uuid, reviewer_side, outcome '
                ."FROM content_reviews WHERE consumes_round = 1 AND reviewer_side <> 'client'; "
                .'De cada una depende si una ronda se cobro o se absorbio, asi que no se corrigen solas.',
            );
        }
    }

    /** @return list<string> */
    private static function disparadores(): array
    {
        // Los tres caben en 128 caracteres. Lo vigila `tools/verificar-mensajes.py`.
        $agotadas = 'Esa pieza ya gasto las rondas incluidas: una correccion mas hay que autorizarla y decir quien la paga.';

        $noEsDeMas = 'Todavia quedan rondas incluidas: esa no es una ronda de mas y no se puede cobrar como tal.';

        $contador = 'El contador de rondas no baja. Bajarlo regala rondas ya gastadas y el techo deja de valer nada.';

        return [
            // CROSS-TABLE: el techo vive en `campaigns` y lo gastado en
            // `deliverables`, asi que un CHECK no puede verlo.
            //
            // BEFORE INSERT y no UPDATE: `content_reviews` es append-only desde
            // 8.3 (`tg_cvw_inmutable`), asi que insertar es la unica forma de
            // que aparezca una ronda.
            //
            // Lee `revision_rounds_used` ANTES de que `emitir()` lo suba --el
            // INSERT va primero y el UPDATE del contador despues, dentro de la
            // misma transaccion-- que es exactamente el mismo valor con el que
            // el servicio calculo `$exceso`. Los dos miran lo mismo y por eso no
            // se pueden contradecir.
            <<<SQL
                CREATE TRIGGER `tg_cvw_techo` BEFORE INSERT ON `content_reviews`
                FOR EACH ROW
                BEGIN
                  DECLARE v_usadas INT DEFAULT 0;
                  DECLARE v_incluidas INT DEFAULT 0;

                  IF NEW.`consumes_round` = 1 THEN
                    SELECT d.`revision_rounds_used`, c.`included_revision_rounds`
                      INTO v_usadas, v_incluidas
                      FROM `deliverable_versions` v
                      JOIN `deliverables` d ON d.`id` = v.`deliverable_id`
                      JOIN `campaign_creators` cc ON cc.`id` = d.`campaign_creator_id`
                      JOIN `campaigns` c ON c.`id` = cc.`campaign_id`
                     WHERE v.`id` = NEW.`deliverable_version_id`;

                    IF v_usadas >= v_incluidas AND NEW.`over_included` = 0 THEN
                      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$agotadas}';
                    END IF;

                    IF v_usadas < v_incluidas AND NEW.`over_included` = 1 THEN
                      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$noEsDeMas}';
                    END IF;
                  END IF;
                END
                SQL,

            // El contador es lo que el techo compara. Si se puede bajar, el
            // techo no vale nada: se pone a cero y vuelven a caber dos rondas
            // gratis, sin que nadie firme nada.
            //
            // MONOTONO, y no «de uno en uno». La primera version exigia
            // `+1` exacto y rompio siete pruebas que ponian el contador a 2 para
            // simular una pieza gastada --y tenian razon en hacerlo: es un
            // estado que la aplicacion alcanza sola--. Una importacion desde
            // otro sistema tendria el mismo problema.
            //
            // El dano no es simetrico y por eso la regla tampoco: BAJARLO no
            // necesita a nadie y regala rondas; SUBIRLO de golpe hace que la
            // siguiente correccion del cliente se cobre, y eso ya exige firma
            // (`authorized_by_user_id`) y decision de facturacion, las dos
            // auditadas. Se cierra la mitad que no tiene dueno.
            <<<SQL
                CREATE TRIGGER `tg_del_rondas` BEFORE UPDATE ON `deliverables`
                FOR EACH ROW
                BEGIN
                  IF NEW.`revision_rounds_used` < OLD.`revision_rounds_used` THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$contador}';
                  END IF;
                END
                SQL,
        ];
    }
};
