<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La política de precios: la retención y el umbral de rentabilidad (9.18).
 *
 * ### De dónde sale
 *
 * `Q-40`, con tus palabras: *«debo tener un campo donde ponga el valor a pagar,
 * y otro donde vaya la retención por defecto (que se leería desde el admin), y
 * luego un tercero donde vaya el monto que realmente le pagaré»*, y *«yo debería
 * tener configurado umbrales de rentabilidad aceptable para poner por ejemplo
 * 20 %»*.
 *
 * Dos números, y ninguno de los dos puede estar en el código: el 29,5 % es una
 * tasa tributaria que cambia por decreto, y el 20 % es un juicio comercial que
 * se ajusta con datos reales (`DEC-190`).
 *
 * ### El tercer campo: `margin_basis`, y por qué es configuración y no una pregunta
 *
 * Con 100 de neto y 29,5 % de retención, el costo real es **141,84**. Tu ejemplo
 * decía que el ingreso aceptable más bajo sería **170,21**, que es
 * `141,84 × 1,20`: un **veinte por ciento sobre el costo**. Un veinte por ciento
 * de **margen sobre el ingreso** habría dado 177,30, que no es lo mismo.
 *
 * Iba a preguntarlo. No hay que preguntarlo: `DEC-190` dice que estas cosas se
 * configuran, así que `margin_basis` es una columna con dos valores —`cost`
 * (recargo sobre el costo) y `revenue` (margen sobre el ingreso)—, se siembra en
 * `cost` porque es lo que dice tu ejemplo, y la pantalla **enseña las dos
 * cifras** para que el cambio se vea antes de guardarlo.
 *
 * ### Con vigencia, porque cambiar el umbral no reescribe el pasado
 *
 * Subir el umbral del 20 al 25 % no puede convertir en «mala» una participación
 * que se pactó cuando el umbral era 20. La política tiene **historia**:
 * `current_gate` + `uq_pp_current` dejan **una sola vigente**, y lo que se pactó
 * bajo la anterior guarda su propia copia de los números (`9.18`, en
 * `campaign_creators`).
 *
 * `Periodo::sinSolape()` **no sirve aquí** y lo dice él mismo: se niega a
 * trabajar con una serie vacía porque «prohibiría dos periodos cualesquiera de
 * la tabla entera». Eso es exactamente lo que hace falta —la tabla entera es UNA
 * serie— así que el disparador va escrito a mano, con esta nota para que nadie
 * lo tome por un descuido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_policies', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            // La retencion que se le aplica al creador que no emite comprobante
            // (Q-13). Porcentaje, no fraccion: 29.5 y no 0.295. Es como se
            // escribe en un decreto y como se teclea sin equivocarse.
            $table->decimal('withholding_rate', 7, 4)->default(0);
            $table->decimal('min_margin_pct', 7, 4)->default(0);
            $table->string('margin_basis', 10)->default('cost');
            // Por que se puso este numero. Un umbral sin explicacion es un
            // numero que nadie se atreve a cambiar dentro de un ano.
            $table->string('note', 255)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_pp_uuid');
            $table->index('valid_from', 'ix_pp_desde');
            $table->index('created_by_user_id', 'ix_pp_autor');

            $table->foreign('created_by_user_id', 'fk_pp_autor')
                ->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE `pricing_policies` ADD COLUMN `current_gate` TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN `valid_to` IS NULL THEN 1 ELSE NULL END) STORED',
        );
        // UNA sola politica vigente. Con dos, la mitad de las participaciones se
        // pactarian con una tasa y la otra mitad con otra, sin que nada fallara.
        DB::statement('ALTER TABLE `pricing_policies` ADD UNIQUE KEY `uq_pp_current` (`current_gate`)');

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // Sin solape, con la tabla entera como serie: ver la cabecera.
        foreach (['ins' => 'INSERT', 'upd' => 'UPDATE'] as $sufijo => $momento) {
            $excluirse = $momento === 'UPDATE' ? 'AND `id` <> NEW.`id`' : '';

            DB::statement("DROP TRIGGER IF EXISTS `tg_pp_sin_solape_{$sufijo}`");
            DB::unprepared(<<<SQL
                CREATE TRIGGER `tg_pp_sin_solape_{$sufijo}`
                BEFORE {$momento} ON `pricing_policies`
                FOR EACH ROW
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM `pricing_policies`
                         WHERE NEW.`valid_from` <= IFNULL(`valid_to`, '9999-12-31')
                           AND `valid_from` <= IFNULL(NEW.`valid_to`, '9999-12-31')
                           {$excluirse}
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Ya hay una politica de precios en esas fechas: cierre la anterior el dia antes.';
                    END IF;
                END
                SQL);
        }

        // Una politica CERRADA no se reescribe: es la que explica por que un
        // compromiso de hace tres meses se pacto como se pacto. Lo que si se
        // puede tocar de una cerrada es nada; y de la vigente, todo menos las
        // fechas hacia atras --de eso se encarga el no-solape--.
        DB::statement('DROP TRIGGER IF EXISTS `tg_pp_inmutable`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_pp_inmutable`
            BEFORE UPDATE ON `pricing_policies`
            FOR EACH ROW
            BEGIN
                IF OLD.`valid_to` IS NOT NULL THEN
                    IF NOT (NEW.`withholding_rate` <=> OLD.`withholding_rate`)
                       OR NOT (NEW.`min_margin_pct` <=> OLD.`min_margin_pct`)
                       OR NOT (NEW.`margin_basis` <=> OLD.`margin_basis`)
                       OR NOT (NEW.`valid_from` <=> OLD.`valid_from`) THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Una politica cerrada no se reescribe: publique la siguiente.';
                    END IF;
                END IF;
            END
            SQL);

        DB::statement('DROP TRIGGER IF EXISTS `tg_pp_no_delete`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_pp_no_delete`
            BEFORE DELETE ON `pricing_policies`
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'pricing_policies no admite borrado: explica como se pacto cada compromiso.';
            END
            SQL);
    }

    public function down(): void
    {
        foreach (['tg_pp_no_delete', 'tg_pp_inmutable',
            'tg_pp_sin_solape_ins', 'tg_pp_sin_solape_upd'] as $disparador) {
            DB::statement("DROP TRIGGER IF EXISTS `{$disparador}`");
        }

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('pricing_policies');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Cien por cien de retencion deja el bruto en infinito: `neto / (1 -
            // 1)`. No es una exageracion teorica, es una division por cero en la
            // pantalla que calcula el costo.
            ['pricing_policies', 'ck_pp_tasa', 'withholding_rate >= 0 AND withholding_rate < 100',
                ['withholding_rate'], 'La retencion va de 0 a 99,99 %: al 100 % el bruto seria infinito.'],

            // Un umbral del 100 % sobre el INGRESO tambien divide por cero. Sobre
            // el costo no, pero el limite se pone igual: un 100 % de recargo es
            // un numero que casi seguro es una errata de tecleo.
            ['pricing_policies', 'ck_pp_umbral', 'min_margin_pct >= 0 AND min_margin_pct < 100',
                ['min_margin_pct'], 'El umbral va de 0 a 99,99 %.'],

            ['pricing_policies', 'ck_pp_base', "margin_basis IN ('cost','revenue')",
                ['margin_basis'], 'La base del umbral es el costo o el ingreso.'],

            ['pricing_policies', 'ck_pp_fechas', 'valid_to IS NULL OR valid_to >= valid_from',
                ['valid_to', 'valid_from'], 'La politica no puede terminar antes de empezar.'],
        ];
    }
};
