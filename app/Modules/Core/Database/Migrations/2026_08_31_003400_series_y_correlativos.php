<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un número sale una sola vez, y lo que sale queda escrito (9.12).
 *
 * ### De dónde sale
 *
 * `DEC-021` y `BR-LE-007` (🔴): *«la numeración de documentos es correlativa por
 * (entidad legal, país, tipo de documento, serie) y se asigna bajo bloqueo, sin
 * huecos ni duplicados, incluso bajo concurrencia»*.
 *
 * `document_series` existe desde la Fase 2 y **nunca la escribió nadie**: no hay
 * servicio, ni pantalla, ni semilla. Era una tabla con la forma correcta y sin
 * la mecánica: el `next_number` no lo reservaba nadie bajo bloqueo, y el tipo de
 * comprobante era un `CHECK` con cinco palabras peruanas escritas en el código.
 *
 * Es lo que le falta a `9.9` por el otro lado: `9.17d` le dio dónde poner las
 * credenciales; esto le da de dónde sacar el número.
 *
 * ### El país declara sus tipos; el código no los conoce
 *
 * `ck_ds_type` decía `IN ('invoice','boleta','credit_note','debit_note','other')`.
 * Eso es `DEC-190` roto: la boleta es peruana, el CFDI mexicano no está, y añadir
 * uno exigía desplegar. Ahora hay `document_types`, **por país**, y cada tipo
 * declara su código oficial (`01`, `03`, `07`, `08` de SUNAT), **la forma de su
 * serie** y **cuántos dígitos tiene su correlativo**. El mismo patrón de `9.17c`:
 * la regla la pone el país, no el código.
 *
 * No contradice a `DEC-026` —el `purpose` de una integración sí es un enum de
 * código—, y la diferencia importa: el código **se ramifica** por propósito
 * (`invoicing` implica una interfaz distinta de `email`), mientras que el tipo de
 * comprobante **no se ramifica: viaja**. Es un dato que se le entrega al emisor
 * electrónico. Un tipo nuevo no necesita código nuevo; un propósito nuevo, sí.
 *
 * ### Por qué la tabla se rehace en vez de parchearse
 *
 * Cambiar el tipo de una cadena a una foránea son cinco `ALTER` encadenados
 * —quitar la única, quitar el `CHECK`, añadir la columna, rellenarla, quitar la
 * vieja— sobre una tabla que **no tiene una sola fila que rellenar**. Se rehace,
 * y `up()` **se niega a seguir si encontrara filas**: si alguien las escribió a
 * mano, esta migración se para y lo dice, en vez de borrarlas en silencio.
 *
 * ### Lo que NO se construye todavía
 *
 * El documento en sí —la factura, con su emisor congelado y sus líneas— es `9.9`.
 * Aquí sólo está el número y a qué se le entregó: `entity_type` / `entity_id`
 * quedan sin foránea a propósito, porque la tabla a la que apuntarán aún no
 * existe. `9.13` añadirá el `integration_connection_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Rehacer una tabla vacia es barato; rehacer una con datos fiscales es
        // imperdonable. Si alguien la escribio a mano, aqui se para.
        if (Schema::hasTable('document_series') && DB::table('document_series')->exists()) {
            throw new RuntimeException(
                'La tabla `document_series` tiene filas y esta migracion la rehace. '
                .'Nadie deberia haberlas escrito: no hay servicio, ni pantalla, ni semilla. '
                .'Guardelas antes de continuar y borre este bloqueo a mano.',
            );
        }

        // ------------------------------------------------ los tipos, por pais
        //
        // `sort_order` porque el orden de esta lista sale en un desplegable y
        // «factura, boleta, nota de credito, nota de debito» no es alfabetico:
        // es el orden en que se usan.
        Schema::create('document_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id');
            // Como lo llamamos aqui: `invoice`, `boleta`, `credit_note`. Sigue
            // siendo un codigo estable --se cita en informes-- pero YA NO es una
            // lista cerrada en el codigo: es una fila de este catalogo.
            $table->string('code', 30);
            $table->string('name', 80);
            // Como lo llama la administracion tributaria del pais: '01' factura,
            // '03' boleta, '07' nota de credito, '08' nota de debito en SUNAT.
            // Es lo que viaja en el XML, y por eso vive aqui y no en el codigo.
            $table->string('official_code', 5)->nullable();
            // La forma de la serie. En Peru una factura va con `F` y tres mas;
            // una boleta con `B`. Nulo = cualquier serie con la forma general.
            $table->string('series_pattern', 120)->nullable();
            // Como se le pide a una persona. Media configuracion es peor que
            // ninguna: un patron sin etiqueta deja el formulario pidiendo
            // «serie» sin decir cual --misma leccion que `ck_countries_localidad`--.
            $table->string('series_label', 60)->nullable();
            // Cuantos digitos tiene el correlativo: ocho en SUNAT. De aqui sale
            // el «F001-00000123» y de aqui sale tambien cuando se agota.
            $table->unsignedTinyInteger('number_length')->default(8);
            $table->boolean('requires_customer_tax_id')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['country_id', 'code'], 'uq_dtype_code');
            $table->index(['country_id', 'is_active', 'sort_order'], 'ix_dtype_pais');

            $table->foreign('country_id', 'fk_dtype_country')
                ->references('id')->on('countries')->restrictOnDelete();
        });

        // ------------------------------------------------------- las series
        Schema::dropIfExists('document_series');

        Schema::create('document_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id');
            $table->foreignId('document_type_id');
            $table->string('series', 10);
            $table->unsignedBigInteger('next_number')->default(1);
            // La serie de pruebas y la real conviven, y no se pueden confundir:
            // es la barrera de `DEC-029` aplicada a los correlativos.
            $table->string('environment', 15)->default('production');
            $table->boolean('is_active')->default(true);
            // Cual se usa cuando nadie dice cual. Con dos series activas del
            // mismo tipo --y las hay: una por local, una por caja-- «emitir una
            // factura» no tendria respuesta hasta que alguien eligiera.
            $table->boolean('is_default')->default(false);
            $table->string('notes', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['legal_entity_id', 'document_type_id', 'series', 'environment'], 'uq_ds_series');
            $table->index(['legal_entity_id', 'is_active'], 'ix_ds_entity');
            $table->index('document_type_id', 'ix_ds_tipo');

            $table->foreign('legal_entity_id', 'fk_ds_entity')
                ->references('id')->on('legal_entities')->restrictOnDelete();
            $table->foreign('document_type_id', 'fk_ds_tipo')
                ->references('id')->on('document_types')->restrictOnDelete();
        });

        // Columna puerta: una sola serie POR DEFECTO por (sociedad, tipo,
        // entorno). El empate se rechaza al configurar y no al emitir, que es
        // el criterio de `uq_lec_country` desde 2.10.
        DB::statement(
            'ALTER TABLE `document_series` ADD COLUMN `default_gate` VARCHAR(60) '
            .'GENERATED ALWAYS AS (CASE WHEN `is_active` = 1 AND `is_default` = 1 THEN CONCAT('
            .'`legal_entity_id`, \':\', `document_type_id`, \':\', `environment`) ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE `document_series` ADD UNIQUE KEY `uq_ds_default` (`default_gate`)',
        );

        // ------------------------------------------- el libro de los numeros
        //
        // Sin esta tabla, «sin huecos» es indemostrable: el contador solo dice
        // por donde va, no que paso con cada numero que salio. Un hueco puede
        // existir --se reservo y no se emitio-- pero tiene que estar EXPLICADO,
        // que es lo que exige un requerimiento de SUNAT y lo que no puede
        // contestar un `AUTO_INCREMENT`.
        Schema::create('document_numbers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_series_id');
            $table->unsignedBigInteger('number');
            // Redundante a proposito: es lo que se imprime y lo que la gente
            // busca. Se calcula al reservar, con la longitud del tipo de
            // ENTONCES; cambiar el tipo despues no reescribe lo ya emitido.
            $table->string('full_number', 40);
            $table->string('status', 15)->default('reserved');
            $table->dateTime('reserved_at', 3);
            $table->unsignedBigInteger('reserved_by_user_id')->nullable();
            $table->dateTime('used_at', 3)->nullable();
            // A que se le entrego. Sin foranea: la tabla de documentos es 9.9 y
            // todavia no existe. Se escribe el nombre de la entidad para que el
            // dia que exista se pueda comprobar, no para que el motor lo valide.
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->dateTime('voided_at', 3)->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            // Lo que hace imposible el duplicado aunque el codigo se equivoque:
            // el bloqueo es la primera linea y esta es la ultima.
            $table->unique(['document_series_id', 'number'], 'uq_dn_number');
            $table->index(['status', 'reserved_at'], 'ix_dn_estado');
            $table->index(['entity_type', 'entity_id'], 'ix_dn_entidad');

            $table->foreign('document_series_id', 'fk_dn_serie')
                ->references('id')->on('document_series')->restrictOnDelete();
            $table->foreign('reserved_by_user_id', 'fk_dn_autor')
                ->references('id')->on('users')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        self::disparadores();
    }

    public function down(): void
    {
        foreach (['tg_ds_forma_ins', 'tg_ds_forma_upd', 'tg_dn_inmutable', 'tg_dn_no_delete'] as $t) {
            DB::statement("DROP TRIGGER IF EXISTS `{$t}`");
        }

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('document_numbers');
        Schema::dropIfExists('document_series');
        Schema::dropIfExists('document_types');

        // La forma que tenia antes de 9.12, para que la vuelta atras deje la
        // base como estaba y no a medias.
        Schema::create('document_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id');
            $table->string('document_type', 30);
            $table->string('series', 10);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->string('environment', 15)->default('production');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['legal_entity_id', 'document_type', 'series', 'environment'], 'uq_ds_series');
            $table->index(['legal_entity_id', 'is_active'], 'ix_ds_entity');

            $table->foreign('legal_entity_id', 'fk_ds_entity')
                ->references('id')->on('legal_entities')->restrictOnDelete();
        });

        foreach ([
            ['document_series', 'ck_ds_type', "document_type IN ('invoice','boleta','credit_note','debit_note','other')", ['document_type'], 'Tipo de documento no valido.'],
            ['document_series', 'ck_ds_env', "environment IN ('sandbox','production')", ['environment'], 'Entorno no valido.'],
            ['document_series', 'ck_ds_number', 'next_number >= 1', ['next_number'], 'El correlativo empieza en 1.'],
        ] as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }
    }

    /**
     * Los disparadores: la forma de la serie y la inmutabilidad del libro.
     */
    private static function disparadores(): void
    {
        // La forma de la serie la declara el TIPO, que a su vez es de un pais.
        // Es una regla entre tablas, y eso ningun CHECK lo admite --tampoco en
        // MySQL 8--.
        //
        // `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` en la variable no
        // es adorno: sin ello un `DECLARE` toma la colacion del SERVIDOR, la
        // columna trae la de la TABLA, y el `REGEXP` revienta con un 1267 --
        // «Illegal mix of collations»-- en cada alta. Se aprendio en 9.17c y
        // costo encontrarlo porque el campo iba siempre nulo.
        foreach (['ins' => 'INSERT', 'upd' => 'UPDATE'] as $sufijo => $evento) {
            DB::statement("DROP TRIGGER IF EXISTS `tg_ds_forma_{$sufijo}`");
            DB::unprepared(<<<SQL
                CREATE TRIGGER `tg_ds_forma_{$sufijo}`
                BEFORE {$evento} ON `document_series`
                FOR EACH ROW
                BEGIN
                    DECLARE v_patron VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                    DECLARE v_largo TINYINT UNSIGNED;
                    DECLARE v_pais_tipo BIGINT UNSIGNED;
                    DECLARE v_pais_soc  BIGINT UNSIGNED;

                    SELECT `series_pattern`, `number_length`, `country_id`
                      INTO v_patron, v_largo, v_pais_tipo
                      FROM `document_types` WHERE `id` = NEW.`document_type_id`;

                    SELECT `country_id` INTO v_pais_soc
                      FROM `legal_entities` WHERE `id` = NEW.`legal_entity_id`;

                    IF v_pais_tipo <> v_pais_soc THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Ese tipo de comprobante es de otro pais: la serie es de la sociedad que lo emite.';
                    END IF;

                    IF v_patron IS NOT NULL AND NEW.`series` NOT REGEXP v_patron THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'La serie no tiene la forma que exige ese tipo de comprobante.';
                    END IF;

                    IF NEW.`next_number` > POW(10, v_largo) - 1 THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'La serie se agoto: el correlativo ya no cabe en su longitud.';
                    END IF;
                END
                SQL);
        }

        // Informacion fiscal: no se borra. Es `DEC-062` otra vez --lo que
        // sostiene una cifra no se borra fisicamente-- aplicado al numero.
        DB::statement('DROP TRIGGER IF EXISTS `tg_dn_no_delete`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_dn_no_delete`
            BEFORE DELETE ON `document_numbers`
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'Un numero emitido no se borra: anulelo con su motivo.';
            END
            SQL);

        // Y no se reescribe. Un numero, su serie y su fecha de reserva son lo
        // que hace que «sin huecos» se pueda demostrar; si se pudieran editar,
        // el libro contaria lo que hiciera falta.
        //
        // Los estados van en una direccion: `reserved` es el unico del que se
        // sale. Un numero usado no vuelve a estar libre --se corrige con nota
        // de credito, `BR-FIN-010`-- y uno anulado no se reutiliza NUNCA:
        // reutilizarlo es exactamente el duplicado que esta tabla impide.
        DB::statement('DROP TRIGGER IF EXISTS `tg_dn_inmutable`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_dn_inmutable`
            BEFORE UPDATE ON `document_numbers`
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.`number` <=> OLD.`number`)
                   OR NOT (NEW.`document_series_id` <=> OLD.`document_series_id`)
                   OR NOT (NEW.`full_number` <=> OLD.`full_number`)
                   OR NOT (NEW.`reserved_at` <=> OLD.`reserved_at`) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Un numero no se reescribe: es lo que hace demostrable que no hay huecos.';
                END IF;

                IF OLD.`status` <> 'reserved' AND NEW.`status` <> OLD.`status` THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Solo un numero reservado cambia de estado: usado y anulado son finales.';
                END IF;
            END
            SQL);
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // El codigo se cita en informes y se escribe a mano: se le pide la
            // forma de un identificador, no la de una frase.
            // Dos mitades, y la segunda no es adorno: con la colacion de la
            // tabla --`utf8mb4_unicode_ci`-- el `REGEXP` compara SIN distinguir
            // mayusculas, asi que `^[a-z]` acepta `INVOICE` y la regla no dice
            // nada. Lo cazo la suite: la asercion de minusculas salia verde por
            // el espacio, no por la mayuscula.
            //
            // La primera forma que se escribio fue `CAST(code AS BINARY) REGEXP
            // ...`, que funciona en MariaDB y **MySQL 8 rechaza con un 3995**
            // --«Character set 'binary' cannot be used in conjunction with
            // 'utf8mb4_0900_ai_ci' in call to regexp_like»--. Segunda vez en dos
            // iteraciones que hacen falta los dos motores para ver una regla.
            ['document_types', 'ck_dtype_code',
                "code REGEXP '^[a-z][a-z0-9_]{1,29}$' AND code COLLATE utf8mb4_bin = LOWER(code)",
                ['code'], 'El codigo del tipo va en minusculas, sin espacios.'],
            ['document_types', 'ck_dtype_largo', 'number_length BETWEEN 1 AND 12',
                ['number_length'], 'El correlativo tiene entre 1 y 12 digitos.'],
            // Misma leccion que `ck_countries_localidad`: un patron sin etiqueta
            // deja el formulario pidiendo «serie» sin decir de que forma.
            ['document_types', 'ck_dtype_patron', 'series_pattern IS NULL OR series_label IS NOT NULL',
                ['series_pattern', 'series_label'], 'Si la serie tiene forma, hay que decir cual es.'],

            ['document_series', 'ck_ds_env', "environment IN ('sandbox','production')",
                ['environment'], 'Entorno de serie no valido: sandbox o production.'],
            ['document_series', 'ck_ds_number', 'next_number >= 1',
                ['next_number'], 'El correlativo empieza en 1.'],
            // Mismo motivo que `ck_dtype_code`: sin la segunda mitad la regla
            // admitia `f912`, y entonces `uq_ds_series` --que tambien compara
            // sin distinguir-- daba un 1062 confuso al declarar la de verdad.
            ['document_series', 'ck_ds_serie',
                "series REGEXP '^[A-Z0-9]{1,10}$' AND series COLLATE utf8mb4_bin = UPPER(series)",
                ['series'], 'La serie va en mayusculas y digitos, sin espacios.'],
            // Una serie inactiva no puede ser la de por defecto: la puerta ya lo
            // impide para el hueco, pero esto lo dice con palabras.
            ['document_series', 'ck_ds_defecto', 'is_default = 0 OR is_active = 1',
                ['is_default', 'is_active'], 'Una serie apagada no puede ser la de por defecto.'],

            ['document_numbers', 'ck_dn_status', "status IN ('reserved','used','voided')",
                ['status'], 'Estado de numero no valido.'],
            ['document_numbers', 'ck_dn_numero', 'number >= 1',
                ['number'], 'El correlativo empieza en 1.'],
            // Un numero usado sin decir en que se uso es un numero que nadie
            // puede rastrear: es la mitad del motivo de que exista el libro.
            ['document_numbers', 'ck_dn_usado',
                "status <> 'used' OR (used_at IS NOT NULL AND entity_type IS NOT NULL AND entity_id IS NOT NULL)",
                ['status', 'used_at', 'entity_type', 'entity_id'],
                'Un numero usado dice cuando y en que documento.'],
            // Y un hueco sin motivo es un hueco a secas, que es justo lo que no
            // se puede tener. Diez caracteres: «error» no explica nada.
            // `void_reason IS NOT NULL` ANTES del largo, y no es redundante:
            // `CHAR_LENGTH(NULL)` es NULL, la conjuncion entera es NULL, y un
            // CHECK solo rechaza cuando es FALSO. Sin esa mitad, anular SIN
            // NINGUN motivo pasaba --justo el caso que la regla existe para
            // impedir-- y solo fallaba el motivo corto. Lo cazo la suite.
            ['document_numbers', 'ck_dn_anulado',
                "status <> 'voided' OR (voided_at IS NOT NULL AND void_reason IS NOT NULL "
                .'AND CHAR_LENGTH(void_reason) >= 10)',
                ['status', 'voided_at', 'void_reason'],
                'Un numero anulado dice cuando y por que, con un motivo escrito.'],
            ['document_numbers', 'ck_dn_reservado',
                "status <> 'reserved' OR (used_at IS NULL AND voided_at IS NULL)",
                ['status', 'used_at', 'voided_at'],
                'Un numero reservado no puede estar ya usado ni anulado.'],
        ];
    }
};
