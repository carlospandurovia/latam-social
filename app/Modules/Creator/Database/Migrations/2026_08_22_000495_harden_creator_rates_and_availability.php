<?php

declare(strict_types=1);

use App\Shared\Database\Periodo;
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

        // `T-14`: esto eran CUATRO disparadores tecleados a mano --dos por
        // tabla, doce lineas cada uno-- que hacian exactamente lo que genera
        // `Periodo`. La regla estaba bien; el problema era que un arreglo
        // futuro habria que aplicarlo en dos sitios y acordarse de los dos.
        //
        // No es hipotetico: `Periodo` usa `<=>` en las columnas de serie y las
        // copias a mano usaban `=`. Aqui da igual --las tres columnas de serie
        // son NOT NULL, comprobado-- pero el dia que una admita NULL, la copia
        // a mano deja pasar el solape en silencio y la generada no.
        //
        // Y ademas quedan registrados en `schema_constraints`: cuatro reglas
        // que hasta ahora no salian en el inventario del esquema.
        Periodo::sinSolape(
            tabla: 'creator_rates',
            nombre: 'crate_sin_solape',
            serie: ['creator_id', 'content_format_id', 'currency_code'],
            mensaje: 'Ese periodo se solapa con otra tarifa del mismo formato y moneda: cierre la anterior el dia antes.',
        );

        Periodo::sinSolape(
            tabla: 'creator_availability',
            nombre: 'cav_sin_solape',
            serie: ['creator_id'],
            mensaje: 'Ese periodo se solapa con otra disponibilidad declarada: cierre la anterior el dia antes.',
        );
    }

    /**
     * Deshace `up()` en orden inverso.
     *
     * **No existía**, y eso es un defecto y no una omisión razonada: la clase
     * base de Laravel no declara `down()`, así que `php artisan migrate:rollback`
     * moría aquí con un error fatal —no con un mensaje— y se llevaba por delante
     * la vuelta atrás de todo lo posterior. Lo destapó `verificar-ddl-crudo.py`,
     * que ejecuta de verdad el `down()` y el `up()` de cada migración contra una
     * copia del esquema; era la única de las cuarenta que no tenía ninguno.
     *
     * `is_gratis` se pierde al volver atrás, y no hay forma de que no: el
     * esquema anterior no tiene dónde guardar la diferencia entre «gratis» y
     * «nadie fijó el precio». Volver atrás de esta migración es aceptar eso.
     */
    public function down(): void
    {
        Periodo::quitar('creator_availability', 'cav_sin_solape');
        Periodo::quitar('creator_rates', 'crate_sin_solape');

        Restriccion::quitar('creator_rates', 'ck_creator_rates_amount');
        Restriccion::comprobacion(
            tabla: 'creator_rates',
            nombre: 'ck_creator_rates_amount',
            expresion: 'amount >= 0',
            columnas: ['amount'],
            mensaje: 'Una tarifa no puede ser negativa.',
        );

        DB::statement("ALTER TABLE creator_rates ALTER COLUMN source SET DEFAULT 'self_declared'");
        DB::statement('ALTER TABLE creator_rates DROP FOREIGN KEY fk_crate_author');
        DB::statement('ALTER TABLE creator_rates DROP KEY ix_creator_rates_author');

        Schema::table('creator_rates', function (Blueprint $table): void {
            $table->dropColumn(['created_by_user_id', 'is_gratis']);
        });
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

        // Los solapes que YA hay dentro, con `Periodo::solapes()`.
        //
        // Un disparador **no valida lo que ya esta en la tabla**: se crea, dice
        // que si, y las filas que se pisaban siguen pisandose. La regla quedaria
        // puesta y el historico seguiria mintiendo, que es el peor de los dos
        // mundos: nadie vuelve a mirar una tabla que tiene una restriccion
        // encima.
        //
        // Se preguntaba con SQL tecleado a mano, y **solo por `creator_rates`**.
        // `creator_availability` recibia su disparador sin que nadie hubiera
        // mirado si ya tenia solapes. Salio al migrar los cuatro disparadores a
        // `Periodo` (`T-14`): la pregunta que faltaba estaba a una linea de
        // distancia, en la misma clase que genera la regla.
        foreach ([
            ['creator_rates', ['creator_id', 'content_format_id', 'currency_code'], 'tarifas', 'H-16'],
            ['creator_availability', ['creator_id'], 'disponibilidades', 'la misma regla'],
        ] as [$tabla, $serie, $comoSeLlaman, $hallazgo]) {
            $solapes = Periodo::solapes(tabla: $tabla, serie: $serie, limite: 100);

            if ($solapes !== []) {
                $n = count($solapes);
                $problemas[] = "Hay {$n} par(es) de {$comoSeLlaman} con periodos solapados ({$hallazgo}). "
                    .'Cierre cada una el dia ANTERIOR al inicio de la siguiente: `valid_to` es inclusivo. '
                    .'El primero es '.json_encode($solapes[0], JSON_UNESCAPED_UNICODE).'.';
            }
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
};
