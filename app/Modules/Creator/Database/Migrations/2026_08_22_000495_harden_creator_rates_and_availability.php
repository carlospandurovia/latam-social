<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El precio del creador, con las tres ambigüedades cerradas (iteración 3.9).
 *
 * `campaign_creators.agreed_amount` congela lo que se pactó, y eso está bien.
 * Pero la tarifa es **de dónde sale ese número**, y era la tabla con menos
 * controles del bloque comercial. Los tres hallazgos se reprodujeron contra una
 * base real antes de escribir nada.
 *
 * **`H-16` — el historial tenía dos respuestas para la misma fecha.**
 * `uq_creator_rates_current` garantiza una tarifa *vigente* por creador,
 * formato y moneda. No garantiza que el histórico sea coherente: dos tarifas
 * cerradas con periodos solapados entraban sin protestar y entonces
 *
 *     el 2026-05-01 la tarifa era: 1000.0000, 2500.0000
 *
 * Un histórico de precios con dos respuestas para una fecha no sirve para lo
 * único para lo que existe, que es explicar por qué se pagó lo que se pagó.
 *
 * **`H-17` — `source` afirmaba algo que nadie había dicho.** `DEFAULT
 * 'self_declared'` convertía el silencio en «el creador declaró este precio».
 * La diferencia entre `self_declared` y `estimated` es la diferencia entre un
 * número que el creador sostiene y uno que nos inventamos nosotros, y un valor
 * por omisión no puede decidir eso. Es `DEC-048` aplicado al precio.
 *
 * **`H-18` — nadie firmaba el precio.** No existía `created_by_user_id`. Si no
 * hay autor en la referencia, no hay a quién preguntarle por qué subió.
 *
 * Y una decisión: **cero es un precio válido pero hay que declararlo**
 * (`DEC-068`). Canje por producto y primera colaboración existen; «trabaja
 * gratis» y «nadie le preguntó su tarifa» no pueden ser el mismo cero.
 *
 * `valid_to` es **inclusivo** —lo dice `ck_creator_rates_dates`, que admite
 * `valid_to = valid_from`—, así que cerrar una tarifa es ponerle el día
 * **anterior** al inicio de la siguiente. De eso se encarga el controlador; la
 * base solo impide el solape.
 */
return new class extends Migration
{
    public function up(): void
    {
        self::comprobarQueSePuedeEndurecer();

        Schema::table('creator_rates', function (Blueprint $table): void {
            $table->boolean('is_gratis')->default(false)->after('amount');
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('is_gratis');
        });

        $autor = self::autorDeMigracion();

        if ($autor !== null) {
            DB::table('creator_rates')->update(['created_by_user_id' => $autor]);
        }

        // Igual que en `000490`: la columna nace NULL y se endurece cuando
        // todavía no tiene foránea encima, para no tropezar con el `ERROR 1832`
        // de MySQL 8 (`H-08`). La foránea va al final.
        DB::statement('ALTER TABLE creator_rates MODIFY created_by_user_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE creator_rates ADD KEY ix_creator_rates_author (created_by_user_id)');
        DB::statement(
            'ALTER TABLE creator_rates ADD CONSTRAINT fk_crate_author '
            .'FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT',
        );

        // `H-17`. Sin `DEFAULT`, en modo estricto una insercion que no diga de
        // donde sale el precio falla. Que es exactamente lo que se quiere.
        DB::statement('ALTER TABLE creator_rates ALTER COLUMN source DROP DEFAULT');

        Restriccion::quitar('creator_rates', 'ck_creator_rates_amount');
        Restriccion::comprobacion(
            tabla: 'creator_rates',
            nombre: 'ck_creator_rates_amount',
            expresion: '(is_gratis = 1 AND amount = 0) OR (is_gratis = 0 AND amount > 0)',
            columnas: ['is_gratis', 'amount'],
            mensaje: 'Una tarifa es mayor que cero, o es cero y esta declarada como gratuita.',
        );

        foreach (self::disparadores() as $nombre => $cuerpo) {
            DB::unprepared("CREATE TRIGGER `{$nombre}` {$cuerpo}");
        }
    }

    /**
     * Se comprueba TODO antes de tocar el esquema, y se dice de una vez.
     *
     * Misma lección que `000490`: una migración que revienta a mitad deja el
     * esquema en un estado que nadie declaró, y fallar de una en una invita a
     * vaciar la tabla sin mirar qué había dentro.
     */
    private static function comprobarQueSePuedeEndurecer(): void
    {
        $problemas = [];
        $filas = DB::table('creator_rates')->count();

        if ($filas > 0 && self::autorDeMigracion() === null) {
            $problemas[] = "Hay {$filas} tarifas y `created_by_user_id` pasa a ser obligatorio (H-18). "
                .'Ponga en `config/latam.php` `tarifas.autor_migracion` el id del usuario que las '
                .'fijó, o vacíe la tabla si son datos de prueba.';
        }

        $enCero = DB::table('creator_rates')->where('amount', 0)->count();

        if ($enCero > 0) {
            $problemas[] = "Hay {$enCero} tarifas en cero y ahora hay que decir si son gratuitas "
                .'(`is_gratis = 1`) o si nadie fijó el precio, en cuyo caso la fila sobra (DEC-068). '
                .'No lo decido yo: cada una significa una cosa distinta ante un cliente.';
        }

        // El solape se comprueba con una tabla derivada, no con una subconsulta
        // correlacionada: `ERROR 1093` si se lee la misma tabla que se modifica
        // (`DEC-052`). Aquí no se modifica, pero la costumbre evita el susto.
        $solapadas = DB::select(<<<'SQL'
            SELECT COUNT(*) AS n FROM (
              SELECT a.id
                FROM creator_rates a
                JOIN creator_rates b
                  ON b.id <> a.id
                 AND b.creator_id = a.creator_id
                 AND b.content_format_id = a.content_format_id
                 AND b.currency_code = a.currency_code
                 AND a.valid_from <= IFNULL(b.valid_to, '9999-12-31')
                 AND b.valid_from <= IFNULL(a.valid_to, '9999-12-31')
               GROUP BY a.id
            ) t
            SQL);

        $nSolapadas = (int) ($solapadas[0]->n ?? 0);

        if ($nSolapadas > 0) {
            $problemas[] = "Hay {$nSolapadas} tarifas con periodos solapados (H-16). Cierre cada una "
                .'el día ANTERIOR al inicio de la siguiente: `valid_to` es inclusivo.';
        }

        if ($problemas !== []) {
            throw new RuntimeException(
                "No puedo endurecer `creator_rates` con los datos que hay:\n  - "
                .implode("\n  - ", $problemas),
            );
        }
    }

    private static function autorDeMigracion(): ?int
    {
        $valor = config('latam.tarifas.autor_migracion');

        return is_numeric($valor) ? (int) $valor : null;
    }

    /**
     * Que dos periodos no se pisen mira OTRAS FILAS, y eso ningún `CHECK` lo
     * puede hacer ni en MySQL ni en MariaDB.
     *
     * @return array<string, string>
     */
    private static function disparadores(): array
    {
        $solapeTarifa = <<<'SQL'
            SELECT 1 FROM creator_rates
             WHERE creator_id = NEW.creator_id
               AND content_format_id = NEW.content_format_id
               AND currency_code = NEW.currency_code
            SQL;

        return [
            'tg_crate_sin_solape_ins' => <<<SQL
                BEFORE INSERT ON `creator_rates`
                FOR EACH ROW
                BEGIN
                  IF EXISTS (
                    {$solapeTarifa}
                       AND NEW.valid_from <= IFNULL(valid_to, '9999-12-31')
                       AND valid_from <= IFNULL(NEW.valid_to, '9999-12-31')
                  ) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Ya hay una tarifa para ese formato y moneda en esas fechas: cierre la anterior el dia antes.';
                  END IF;
                END
                SQL,
            'tg_crate_sin_solape_upd' => <<<SQL
                BEFORE UPDATE ON `creator_rates`
                FOR EACH ROW
                BEGIN
                  IF EXISTS (
                    {$solapeTarifa}
                       AND id <> NEW.id
                       AND NEW.valid_from <= IFNULL(valid_to, '9999-12-31')
                       AND valid_from <= IFNULL(NEW.valid_to, '9999-12-31')
                  ) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'El cambio dejaria dos tarifas solapadas para el mismo formato y moneda.';
                  END IF;
                END
                SQL,
            'tg_cav_sin_solape_ins' => <<<'SQL'
                BEFORE INSERT ON `creator_availability`
                FOR EACH ROW
                BEGIN
                  IF EXISTS (
                    SELECT 1 FROM creator_availability
                     WHERE creator_id = NEW.creator_id
                       AND NEW.valid_from <= IFNULL(valid_to, '9999-12-31')
                       AND valid_from <= IFNULL(NEW.valid_to, '9999-12-31')
                  ) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Ya hay una disponibilidad declarada en esas fechas: cierre la anterior el dia antes.';
                  END IF;
                END
                SQL,
            'tg_cav_sin_solape_upd' => <<<'SQL'
                BEFORE UPDATE ON `creator_availability`
                FOR EACH ROW
                BEGIN
                  IF EXISTS (
                    SELECT 1 FROM creator_availability
                     WHERE id <> NEW.id
                       AND creator_id = NEW.creator_id
                       AND NEW.valid_from <= IFNULL(valid_to, '9999-12-31')
                       AND valid_from <= IFNULL(NEW.valid_to, '9999-12-31')
                  ) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'El cambio dejaria dos disponibilidades solapadas.';
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

        Restriccion::quitar('creator_rates', 'ck_creator_rates_amount');
        Restriccion::comprobacion(
            tabla: 'creator_rates',
            nombre: 'ck_creator_rates_amount',
            expresion: 'amount >= 0',
            columnas: ['amount'],
            mensaje: 'La tarifa no puede ser negativa.',
        );

        DB::statement("ALTER TABLE creator_rates ALTER COLUMN source SET DEFAULT 'self_declared'");
        DB::statement('ALTER TABLE creator_rates DROP FOREIGN KEY fk_crate_author');
        DB::statement('ALTER TABLE creator_rates DROP INDEX ix_creator_rates_author');

        Schema::table('creator_rates', function (Blueprint $table): void {
            $table->dropColumn(['created_by_user_id', 'is_gratis']);
        });
    }
};
