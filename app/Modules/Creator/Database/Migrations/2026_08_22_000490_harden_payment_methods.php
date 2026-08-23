<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seis defectos del medio de pago, todos reproducidos antes de cerrarlos
 * (iteración 3.8).
 *
 * `creator_payment_methods` es la fila que dice **a dónde va el dinero**, y era
 * la tabla con menos controles de las tres del bloque fiscal. Cada hallazgo se
 * ejecutó primero contra una base real; ninguno se dedujo leyendo el esquema.
 *
 * **H-02 — verificado sin decir desde cuándo.** `status='verified'` con
 * `eligible_from` NULL entraba sin protestar. `CompletitudOperativa` ya se
 * defendía con un `whereNotNull`, pero eso es una defensa en **una** consulta:
 * la de pagos no la tenía. Una regla que vive en una consulta no es una regla.
 *
 * **H-10 — una restricción con forma de control que no controlaba nada.** El
 * comentario decía *«la máscara nunca puede contener más de 4 dígitos»* y
 * debajo había un `CHAR_LENGTH(...) <= 30`, que es el largo de la columna. Se
 * comprobó: `00212345678901234567` —el número de cuenta entero, en claro— era
 * una máscara válida. Lo peor no es el hueco: es que el comentario aseguraba lo
 * contrario, así que quien lo leyera se quedaba tranquilo.
 *
 * **H-11 — quien captura la cuenta podía verificarla él mismo.** Solo existía
 * `verified_by_user_id`; faltaba la otra mitad del par. Es `H-03` una tabla más
 * allá, y aquí es donde va el dinero. La columna nace `NOT NULL`, que es
 * exactamente lo que `H-03` enseñó a hacer desde el principio.
 *
 * **H-12 — la cuenta de un medio verificado se podía editar.** Un `UPDATE`
 * cambiaba el número y la fila seguía diciendo `verified`, apuntando a otro
 * sitio. Eso vacía `BR-FIN-006` entero, porque el enfriamiento existe
 * justamente para las modificaciones. Ahora la cuenta es inmutable (`DEC-066`).
 *
 * **H-13 — se podía borrar un medio de pago.** `BR-FIN-008` dice que ningún
 * registro financiero se elimina físicamente. No había disparador.
 *
 * **H-14 — el predeterminado podía estar rechazado.** `default_gate`
 * garantizaba que hubiera **uno solo**, no que sirviera.
 *
 * `H-09` —un pago contra un medio sin verificar— se cierra en la migración
 * `000810` del módulo de finanzas, que es donde vive `payouts`.
 */
return new class extends Migration
{
    public function up(): void
    {
        self::comprobarQueSePuedeEndurecer();

        // ---------------------------------------------- H-11: el capturador
        Schema::table('creator_payment_methods', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('holder_document_number');
            $table->dateTime('closed_at', 3)->nullable()->after('eligible_from');
            $table->unsignedBigInteger('closed_by_user_id')->nullable()->after('closed_at');
            $table->string('shared_account_status', 15)->default('pending_review')->after('closed_by_user_id');
        });

        $capturador = self::capturadorDeMigracion();

        if ($capturador !== null) {
            DB::table('creator_payment_methods')->update(['created_by_user_id' => $capturador]);
        }

        // La columna se añade NULL y se endurece después: al hacer el MODIFY
        // todavía no tiene foránea encima, así que MySQL 8 no lanza el 1832 de
        // `H-08`. La foránea va al final, a propósito.
        DB::statement('ALTER TABLE creator_payment_methods MODIFY created_by_user_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE creator_payment_methods ADD KEY ix_cpm_capturer (created_by_user_id)');
        DB::statement(
            'ALTER TABLE creator_payment_methods ADD CONSTRAINT fk_cpm_capturer '
            .'FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT',
        );

        Schema::table('creator_payment_methods', function (Blueprint $table): void {
            $table->index('closed_by_user_id', 'ix_cpm_closer');
            $table->index('shared_account_status', 'ix_cpm_shared');
            $table->foreign('closed_by_user_id', 'fk_cpm_closer')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // --------------------------------- La cuenta repetida entre creadores
        //
        // Para las filas que ya existen la marca se CALCULA, no se supone: un
        // `pending_review` a ciegas obligaría a revisar a mano cuentas que se
        // sabe que son únicas, y un `unique` a ciegas sería el fallo de `H-06`
        // otra vez. La tabla derivada no es adorno: sin ella, MySQL responde
        // `ERROR 1093` por leer la misma tabla que está modificando (`DEC-052`).
        DB::statement(<<<'SQL'
            UPDATE creator_payment_methods m
              JOIN (SELECT account_number_fingerprint AS huella,
                           COUNT(DISTINCT creator_id)  AS creadores
                      FROM creator_payment_methods
                     GROUP BY account_number_fingerprint) x
                ON x.huella = m.account_number_fingerprint
               SET m.shared_account_status = CASE WHEN x.creadores > 1
                                                  THEN 'pending_review' ELSE 'unique' END
            SQL);

        // ------------------------ La misma cuenta dos veces en el mismo creador
        //
        // Devolver la huella desde el CASE sería lo natural y **MariaDB lo
        // rechaza** con `ERROR 1901` en cuanto una columna generada STORED
        // produce una cadena; MySQL 8 lo acepta sin decir nada. Misma familia
        // que `H-08`. La salida es el patrón de puerta que ya usa todo el
        // esquema: la generada vale 1 o NULL y la huella entra en el índice
        // como columna normal.
        DB::statement(
            'ALTER TABLE creator_payment_methods ADD COLUMN open_gate TINYINT UNSIGNED '
            ."GENERATED ALWAYS AS (CASE WHEN status = 'pending' OR status = 'verified' "
            .'THEN 1 ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE creator_payment_methods ADD UNIQUE KEY uq_cpm_open_account '
            .'(open_gate, creator_id, account_number_fingerprint)',
        );

        foreach (self::restricciones() as [$nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: 'creator_payment_methods',
                nombre: $nombre,
                expresion: $expresion,
                columnas: $columnas,
                mensaje: $mensaje,
            );
        }

        foreach (self::disparadores() as $nombre => $cuerpo) {
            DB::unprepared("CREATE TRIGGER `{$nombre}` {$cuerpo}");
        }
    }

    /**
     * Mira si el estado actual de la tabla admite las reglas nuevas, y lo dice
     * TODO de una vez.
     *
     * Una migración que revienta a mitad deja el esquema en un estado que nadie
     * declaró (`H-03`, iteración 3.6). Y fallar de uno en uno obliga a repetir
     * el ciclo entero por cada fila mala, que es la forma más rápida de que
     * alguien decida vaciar la tabla sin mirar qué había dentro.
     */
    private static function comprobarQueSePuedeEndurecer(): void
    {
        $problemas = [];

        $filas = DB::table('creator_payment_methods')->count();

        if ($filas > 0 && self::capturadorDeMigracion() === null) {
            $problemas[] = "Hay {$filas} medios de pago y `created_by_user_id` pasa a ser obligatorio (H-11). "
                .'No hay ningún valor verdadero que inventar: ponga en `config/latam.php` '
                .'`pagos.capturador_migracion` el id del usuario que los capturó, o vacíe la tabla '
                .'si son datos de prueba.';
        }

        $capturador = self::capturadorDeMigracion();

        if ($capturador !== null) {
            $choque = DB::table('creator_payment_methods')->where('verified_by_user_id', $capturador)->count();

            if ($choque > 0) {
                $problemas[] = "El capturador declarado (usuario {$capturador}) es también el verificador de "
                    ."{$choque} medios. La segregación de funciones lo prohíbe (H-11): elija otro usuario "
                    .'o corrija esas filas.';
            }
        }

        // El `hasColumn` va FUERA y la consulta DENTRO, y esto costo una tanda
        // entera de 18 pruebas rojas.
        //
        // La primera version lo tenia al reves: la consulta suelta y el
        // `hasColumn` en el `if` de abajo. PHP evalua el `count()` en el acto,
        // asi que en una base recien migrada --donde `closed_at` todavia no
        // existe, porque la anade esta misma migracion doce lineas mas abajo--
        // el chequeo previo reventaba con
        //
        //   SQLSTATE[42S22]: Unknown column 'closed_at' in 'where clause'
        //
        // y como reventaba en `setUp()`, fallaban las 18 pruebas a la vez sin
        // que ninguna llegara a ejecutarse. Un cortocircuito en un `if` no
        // protege a una consulta que ya se ha ejecutado.
        //
        // El recuento solo puede dar algo en una segunda pasada; se deja porque
        // una migracion que se reintenta tiene que decir la verdad las dos veces.
        if (Schema::hasColumn('creator_payment_methods', 'closed_at')) {
            $sinCierre = DB::table('creator_payment_methods')
                ->whereIn('status', ['rejected', 'disabled'])
                ->where(fn ($q) => $q->whereNull('closed_at')->orWhereNull('closed_by_user_id'))
                ->count();

            if ($sinCierre > 0) {
                $problemas[] = "Hay {$sinCierre} medios rechazados o desactivados sin decir quién ni cuándo.";
            }
        }

        $verificadoSinFecha = DB::table('creator_payment_methods')
            ->where('status', 'verified')->whereNull('eligible_from')->count();

        if ($verificadoSinFecha > 0) {
            $problemas[] = "Hay {$verificadoSinFecha} medios verificados sin `eligible_from` (H-02). "
                .'Decida desde cuándo se les puede pagar antes de aplicar esta migración.';
        }

        $predeterminadoInutil = DB::table('creator_payment_methods')
            ->where('is_default', 1)->where('status', '<>', 'verified')->count();

        if ($predeterminadoInutil > 0) {
            $problemas[] = "Hay {$predeterminadoInutil} medios marcados como predeterminados sin estar "
                .'verificados (H-14).';
        }

        // `REGEXP` y no `REGEXP_REPLACE`: producción es Percona 5.7 y ahí la
        // segunda no existe. «No más de cuatro dígitos» dicho al revés: que no
        // exista una quinta cifra en el texto.
        $mascaraLarga = DB::table('creator_payment_methods')
            ->whereRaw("account_number_masked REGEXP '[0-9].*[0-9].*[0-9].*[0-9].*[0-9]'")
            ->count();

        if ($mascaraLarga > 0) {
            $problemas[] = "Hay {$mascaraLarga} máscaras con más de cuatro dígitos (H-10). "
                .'Alguna puede ser el número de cuenta entero en claro: revíselas antes de seguir.';
        }

        $repetidas = DB::table('creator_payment_methods')
            ->selectRaw('creator_id, account_number_fingerprint, COUNT(*) AS n')
            ->whereIn('status', ['pending', 'verified'])
            ->groupBy('creator_id', 'account_number_fingerprint')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($repetidas > 0) {
            $problemas[] = "Hay {$repetidas} cuentas repetidas dentro del mismo creador entre las que siguen "
                .'abiertas. Desactive las sobrantes.';
        }

        if ($problemas !== []) {
            throw new RuntimeException(
                "No puedo endurecer `creator_payment_methods` con los datos que hay:\n  - "
                .implode("\n  - ", $problemas),
            );
        }
    }

    private static function capturadorDeMigracion(): ?int
    {
        $valor = config('latam.pagos.capturador_migracion');

        return is_numeric($valor) ? (int) $valor : null;
    }

    /**
     * @return list<array{0: string, 1: string, 2: list<string>, 3: string}>
     */
    private static function restricciones(): array
    {
        return [
            ['ck_cpm_masked_digits',
                "account_number_masked NOT REGEXP '[0-9].*[0-9].*[0-9].*[0-9].*[0-9]'",
                ['account_number_masked'],
                'La mascara de cuenta no puede contener mas de cuatro digitos.'],
            ['ck_cpm_eligible',
                "status <> 'verified' OR eligible_from IS NOT NULL",
                ['status', 'eligible_from'],
                'Un medio verificado tiene que decir desde cuando se le puede pagar.'],
            ['ck_cpm_eligible_after',
                'eligible_from IS NULL OR verified_at IS NULL OR eligible_from >= verified_at',
                ['eligible_from', 'verified_at'],
                'La elegibilidad no puede ser anterior a la verificacion.'],
            ['ck_cpm_segregation',
                'verified_by_user_id IS NULL OR verified_by_user_id <> created_by_user_id',
                ['verified_by_user_id', 'created_by_user_id'],
                'Quien captura un medio de pago no puede ser quien lo verifica.'],
            ['ck_cpm_rejected_clean',
                "status <> 'rejected' OR (verified_at IS NULL AND verified_by_user_id IS NULL)",
                ['status', 'verified_at', 'verified_by_user_id'],
                'Un medio rechazado no puede llevar verificador escrito.'],
            ['ck_cpm_closed',
                "status NOT IN ('rejected','disabled') OR (closed_at IS NOT NULL AND closed_by_user_id IS NOT NULL)",
                ['status', 'closed_at', 'closed_by_user_id'],
                'Retirar un medio de pago exige decir quien y cuando.'],
            ['ck_cpm_default_usable',
                "is_default = 0 OR status = 'verified'",
                ['is_default', 'status'],
                'El medio predeterminado tiene que estar verificado.'],
            ['ck_cpm_shared_status',
                "shared_account_status IN ('unique','pending_review','cleared')",
                ['shared_account_status'],
                'Estado de cuenta compartida no valido.'],
        ];
    }

    /**
     * Tres cosas que ningún `CHECK` puede expresar, porque no hablan de los
     * valores de una fila sino de **verbos** y de **otras filas**.
     *
     * @return array<string, string>
     */
    private static function disparadores(): array
    {
        return [
            'tg_cpm_no_delete' => <<<'SQL'
                BEFORE DELETE ON `creator_payment_methods`
                FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'creator_payment_methods no admite borrado (BR-FIN-008): una cuenta se desactiva, y queda quien y cuando.';
                END
                SQL,
            'tg_cpm_inmutable' => <<<'SQL'
                BEFORE UPDATE ON `creator_payment_methods`
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
                     OR NEW.account_number_masked <> OLD.account_number_masked
                     OR NEW.account_number_fingerprint <> OLD.account_number_fingerprint
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
                SQL,
            'tg_cpm_compartida' => <<<'SQL'
                BEFORE INSERT ON `creator_payment_methods`
                FOR EACH ROW
                BEGIN
                  IF EXISTS (
                    SELECT 1 FROM creator_payment_methods
                     WHERE account_number_fingerprint = NEW.account_number_fingerprint
                       AND creator_id <> NEW.creator_id
                  ) THEN
                    SET NEW.shared_account_status = 'pending_review';
                  ELSE
                    SET NEW.shared_account_status = 'unique';
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

        foreach (array_reverse(self::restricciones()) as [$nombre]) {
            Restriccion::quitar('creator_payment_methods', $nombre);
        }

        DB::statement('ALTER TABLE creator_payment_methods DROP INDEX uq_cpm_open_account');
        DB::statement('ALTER TABLE creator_payment_methods DROP COLUMN open_gate');

        DB::statement('ALTER TABLE creator_payment_methods DROP FOREIGN KEY fk_cpm_capturer');
        DB::statement('ALTER TABLE creator_payment_methods DROP INDEX ix_cpm_capturer');
        DB::statement('ALTER TABLE creator_payment_methods MODIFY created_by_user_id BIGINT UNSIGNED NULL');

        Schema::table('creator_payment_methods', function (Blueprint $table): void {
            $table->dropForeign('fk_cpm_closer');
            $table->dropIndex('ix_cpm_closer');
            $table->dropIndex('ix_cpm_shared');
            $table->dropColumn(['shared_account_status', 'closed_by_user_id', 'closed_at', 'created_by_user_id']);
        });
    }
};
