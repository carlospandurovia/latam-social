<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los mercados de la campaña: que sean SUYOS, y que no desaparezcan (7.3).
 *
 * `campaign_markets` existe desde la Fase 2 sin una sola pantalla encima —la
 * misma situación que tenía `campaigns` antes de 7.1—, y con ella dos huecos que
 * sólo se ven cuando alguien empieza a escribir filas.
 *
 * ### Hueco 1: el mercado podía ser de OTRA campaña
 *
 * `campaign_requirements.campaign_market_id` y
 * `campaign_creators.campaign_market_id` apuntan a `campaign_markets(id)`. Una
 * foránea así **sólo comprueba que el mercado exista**, no que sea de la campaña
 * de la fila. Nada impedía un requisito de la campaña A colgado del mercado
 * «México» de la campaña B.
 *
 * Es el mismo hueco que `GuardarCampanaRequest` tapa con palabras para el par
 * (cliente, marca), pero aquí **se puede cerrar en el esquema**: se añade
 * `uq_cm_id_campaign (id, campaign_id)` a `campaign_markets` —redundante como
 * clave, necesaria como destino— y las dos foráneas pasan a ser **compuestas**.
 *
 * Y sigue funcionando el `NULL` con significado de `N-03`: en MySQL una foránea
 * compuesta con un componente `NULL` no se comprueba, así que
 * `campaign_market_id IS NULL` —«todos los mercados»— pasa igual que antes. La
 * excepción consciente de 2.3 §9 sobrevive sin un solo caso especial.
 *
 * Un disparador habría hecho lo mismo. Se prefiere la foránea porque la comprueba
 * el motor en las dos direcciones —también impide mover un mercado de campaña— y
 * porque Percona 5.7 sí tiene foráneas compuestas, mientras que de los `CHECK`
 * hay que compilarle un equivalente.
 *
 * ### Hueco 2: cero creadores no es un objetivo
 *
 * `target_creators` es `NULL`-able y `NULL` significa *«sin cupo fijado»*, que es
 * legítimo. Un **cero** no: «esta campaña corre en Colombia con cero creadores»
 * no es un objetivo, es un mercado que no debería estar en la lista. Distinto de
 * `is_gratis` (7.2), donde el cero sí era una respuesta posible y por eso hubo
 * que añadir una columna para declararlo; aquí `NULL` ya dice «no fijado» y el
 * cero no dice nada.
 *
 * ### Hueco 3: un mercado confirmado no se quita
 *
 * Decisión de negocio (2026-08-25): **añadir sí, quitar no**. Ampliar a un país
 * nuevo es comercial y no rompe nada de lo prometido; quitar un mercado puede
 * dejar fuera a creadores ya invitados o aceptados, y eso exige una enmienda
 * (`BR-CAMPAIGN-003`), no un botón.
 *
 * El disparador es `BEFORE DELETE` y mira el estado de la campaña, no una
 * columna propia: el congelado de un mercado no es un hecho del mercado, es un
 * hecho de la campaña a la que pertenece.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El destino de las foráneas compuestas. `id` ya es PRIMARY, así que
        // este índice es redundante para buscar; existe porque MySQL exige que
        // las columnas referidas por una foránea sean prefijo de algún índice.
        Schema::table('campaign_markets', function (Blueprint $table): void {
            $table->unique(['id', 'campaign_id'], 'uq_cm_id_campaign');
        });

        foreach (self::compuestas() as [$tabla, $vieja, $nueva]) {
            Schema::table($tabla, function (Blueprint $table) use ($vieja): void {
                $table->dropForeign($vieja);
            });

            Schema::table($tabla, function (Blueprint $table) use ($nueva): void {
                $table->foreign(['campaign_market_id', 'campaign_id'], $nueva)
                    ->references(['id', 'campaign_id'])->on('campaign_markets')
                    ->restrictOnDelete();
            });
        }

        Restriccion::comprobacion(
            tabla: 'campaign_markets',
            nombre: 'ck_cm_target',
            expresion: 'target_creators IS NULL OR target_creators >= 1',
            columnas: ['target_creators'],
            mensaje: 'Un mercado con cero creadores no es un objetivo: dejelo sin fijar o quite el mercado.',
        );

        DB::unprepared(self::disparador());
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_cm_no_quitar_confirmada`');

        Restriccion::quitar('campaign_markets', 'ck_cm_target');

        foreach (self::compuestas() as [$tabla, $vieja, $nueva]) {
            Schema::table($tabla, function (Blueprint $table) use ($nueva): void {
                $table->dropForeign($nueva);
            });

            Schema::table($tabla, function (Blueprint $table) use ($vieja): void {
                $table->foreign('campaign_market_id', $vieja)
                    ->references('id')->on('campaign_markets')
                    ->restrictOnDelete();
            });
        }

        Schema::table('campaign_markets', function (Blueprint $table): void {
            $table->dropUnique('uq_cm_id_campaign');
        });
    }

    /** @return list<array{0:string,1:string,2:string}> tabla, foranea vieja, foranea nueva */
    private static function compuestas(): array
    {
        return [
            ['campaign_requirements', 'fk_creq_market', 'fk_creq_market_campaign'],
            ['campaign_creators', 'fk_ccr_market', 'fk_ccr_market_campaign'],
        ];
    }

    /**
     * El disparador no se compila con `Restriccion` porque no es una
     * comprobación de fila: mira OTRA tabla, y sólo al borrar.
     */
    private static function disparador(): string
    {
        return <<<'SQL'
            CREATE TRIGGER `tg_cm_no_quitar_confirmada` BEFORE DELETE ON `campaign_markets`
            FOR EACH ROW
            BEGIN
              IF EXISTS (SELECT 1 FROM `campaigns`
                          WHERE `id` = OLD.`campaign_id` AND `confirmed_at` IS NOT NULL)
              THEN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'De una campana confirmada no se quita un mercado (BR-CAMPAIGN-003): puede dejar fuera a creadores ya invitados. Anadir si se puede.';
              END IF;
            END
            SQL;
    }
};
