<?php

declare(strict_types=1);

namespace App\Modules\Content\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La permanencia mínima del post (8.8).
 *
 * `8.7` dejó `permanence_until` calculado y `permanence_checks` con cero filas.
 * Esto es lo que vigila que el post siga vivo hasta esa fecha, y lo que decide
 * qué pasa cuando no lo está.
 *
 * ### Retirar el post antes de tiempo BLOQUEA el pago. No hay descuento
 *
 * Decisión de negocio (2026-08-27, `DEC-145`). `BR-CONTENT-006` decía que el
 * sistema alerta y no decía nada del dinero, que es donde deja de ser técnico.
 *
 * La publicación pasa a `removed`, el entregable **deja de estar verificado** y
 * el pago se queda quieto hasta que una persona decida. El sistema **no
 * descuenta nada por su cuenta**: un descuento automático exige que el contrato
 * lo diga por escrito y que la detección sea fiable, y hoy no lo es —ver abajo—.
 *
 * ### La sonda marca; una persona confirma
 *
 * Decisión de negocio (2026-08-27, `DEC-146`), tomada con la misma limitación
 * que `8.7`: Instagram y TikTok responden igual ante un post borrado que ante un
 * perfil puesto en privado o un bloqueo geográfico. Ningún `403` puede acusar a
 * un creador de incumplir.
 *
 * Así que una comprobación se **archiva** —`permanence_checks`, append-only— y
 * no cambia el estado de nada. Lo que mueve una publicación a `removed` es una
 * persona, y la base se lo exige: hace falta una comprobación fallida Y una
 * captura tomada **después** de haber verificado el post. La captura vieja
 * —la que probó que el post existía— no sirve para probar que ya no está.
 *
 * ### `expired` se llama `fulfilled`
 *
 * `ck_pub_status` tenía `expired` desde `2.12` con cero filas y con un nombre
 * que se lee al revés de lo que significa: la ventana cumplida es lo BUENO, lo
 * que habilita el pago. Alguien iba a leer `expired` como «se le pasó» —y de
 * este estado cuelga el dinero—. Se renombra ahora, que no cuesta nada.
 *
 * ### El entregable deja de estar verificado, y eso necesita un estado propio
 *
 * `verified` es de lo que va a colgar el pago en `F9`. Un entregable cuyo post
 * se retiró no puede seguir ahí. Devolverlo a `published` habría bastado para
 * que no cobre, pero `published` significa «esperando a que alguien lo mire», y
 * el día que `F9` construya esa cola metería dentro incumplimientos: un estado
 * que significa dos cosas es exactamente el fallo de `T-50`. Así que
 * `ck_del_status` gana `removed`, y los dos disparadores que definen «entregable
 * cerrado» lo tratan como cerrado —si no, se le podría subir una versión nueva
 * encima o emitir un veredicto sobre él—.
 *
 * ### `permanence_checks` es evidencia y no se borra
 *
 * Entra en la lista de `3.12`: de esta fila depende que un pago se pare. Salió
 * al escribir esto, exactamente igual que salió en `3.11` — el criterio de
 * `T-16` la incluía desde el primer día y nadie la había mirado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table): void {
            // Por qué se dio por caída, quién lo firmó, y cuándo se cerró bien.
            // `removed_at` existía desde 2.12 sin nada que lo acompañara: decía
            // el CUANDO de algo que para un pago, sin el POR QUE ni el QUIEN.
            $table->string('removed_reason', 255)->nullable()->after('removed_at');
            $table->unsignedBigInteger('removed_by_user_id')->nullable()->after('removed_reason');
            $table->dateTime('fulfilled_at', 3)->nullable()->after('removed_by_user_id');
            $table->index('removed_by_user_id', 'ix_pub_remover');
            $table->foreign('removed_by_user_id', 'fk_pub_remover')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('permanence_checks', function (Blueprint $table): void {
            // De dónde salió la comprobación. Lista cerrada: «¿cuántas caídas
            // las vio una persona y cuántas una sonda?» tiene que poder
            // contestarse con un número, y con un texto libre no se puede.
            $table->string('source', 20)->default('manual')->after('publication_id');
            $table->unsignedBigInteger('checked_by_user_id')->nullable()->after('is_live');
            $table->index('checked_by_user_id', 'ix_pc_usuario');
            $table->foreign('checked_by_user_id', 'fk_pc_usuario')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // --- Columna puerta: una sonda por publicación y día ------------------
        //
        // `docs/18` §2 dice que en producción el planificador es una línea de
        // cron. Una línea de cron duplicada --dos servidores, o alguien que
        // ejecuta el comando a mano para ver si funciona-- mete la MISMA
        // comprobación dos veces, y cada una manda su correo al creador.
        //
        // La puerta hace la pasada diaria idempotente: la sonda escribe como
        // mucho una fila por publicación y día, y las comprobaciones MANUALES
        // no se limitan --una persona puede mirar tres veces la misma tarde--.
        DB::statement('ALTER TABLE `permanence_checks` ADD COLUMN `sonda_dia` DATE '
            ."GENERATED ALWAYS AS (CASE WHEN `source` = 'probe' THEN DATE(`checked_at`) ELSE NULL END) STORED");
        DB::statement('ALTER TABLE `permanence_checks` ADD UNIQUE KEY `uq_pc_sonda_dia` '
            .'(`publication_id`, `sonda_dia`)');

        // --- `expired` -> `fulfilled` ---------------------------------------
        // Cero filas: el estado existía desde 2.12 y nunca lo escribió nadie.
        DB::statement("UPDATE `publications` SET `status` = 'verified' WHERE `status` = 'expired'");

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::quitar($tabla, $nombre);
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
        foreach (['tg_pub_permanencia', 'tg_pc_publicacion_verificada',
            'tg_pc_inmutable', 'tg_pc_no_delete',
            'tg_cvw_entregable_abierto', 'tg_dv_entregable_abierto'] as $disparador) {
            DB::statement("DROP TRIGGER IF EXISTS `{$disparador}`");
        }

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        foreach (self::restriccionesPrevias() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        foreach (self::disparadoresDe82() as $sql) {
            DB::unprepared($sql);
        }

        DB::statement('ALTER TABLE `permanence_checks` DROP INDEX `uq_pc_sonda_dia`');
        DB::statement('ALTER TABLE `permanence_checks` DROP COLUMN `sonda_dia`');

        Schema::table('permanence_checks', function (Blueprint $table): void {
            $table->dropForeign('fk_pc_usuario');
            $table->dropIndex('ix_pc_usuario');
            $table->dropColumn(['source', 'checked_by_user_id']);
        });

        Schema::table('publications', function (Blueprint $table): void {
            $table->dropForeign('fk_pub_remover');
            $table->dropIndex('ix_pub_remover');
            $table->dropColumn(['removed_reason', 'removed_by_user_id', 'fulfilled_at']);
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // `expired` se va: se leía al revés de lo que significaba.
            ['publications', 'ck_pub_status',
                "status IN ('reported','verified','rejected','removed','fulfilled')",
                ['status'],
                'Estado de publicacion no valido.'],

            ['publications', 'ck_pub_permanence',
                "permanence_until IS NULL OR status IN ('verified','removed','fulfilled')",
                ['permanence_until', 'status'],
                'La permanencia se calcula al verificar: antes no hay post del que contarla.'],

            // Sustituye a la de 2.12, que sólo pedía el CUANDO. Dar un post por
            // caído para un pago: tiene que decir por qué y llevar firma.
            ['publications', 'ck_pub_removed',
                "status <> 'removed' OR (removed_at IS NOT NULL AND removed_by_user_id IS NOT NULL"
                ." AND CHAR_LENGTH(TRIM(COALESCE(removed_reason,''))) >= 5)",
                ['status', 'removed_at', 'removed_by_user_id', 'removed_reason'],
                'Dar un post por caido exige cuando, quien lo firma y por que.'],

            // Un post no puede caerse antes de publicarse.
            ['publications', 'ck_pub_removed_no_antes',
                'removed_at IS NULL OR removed_at >= published_at',
                ['removed_at', 'published_at'],
                'Un post no se puede haber retirado antes de publicarse.'],

            // Cumplida exige la fecha hasta la que se exigía y el momento en que
            // se cerró. Sin `permanence_until` no hay ventana que cumplir.
            ['publications', 'ck_pub_fulfilled',
                "status <> 'fulfilled' OR (fulfilled_at IS NOT NULL AND permanence_until IS NOT NULL)",
                ['status', 'fulfilled_at', 'permanence_until'],
                'Una permanencia cumplida dice hasta cuando se exigia y cuando se cerro.'],

            // El entregable cuyo post se retiro. Ver la nota de arriba: no
            // vale reutilizar `published`, que significa otra cosa.
            ['deliverables', 'ck_del_status',
                "status IN ('pending','in_production','submitted','in_review','changes_requested',"
                ."'approved','published','verified','removed','cancelled')",
                ['status'],
                'Estado de entregable no valido.'],

            ['deliverables', 'ck_del_submitted',
                "status NOT IN ('submitted','in_review','changes_requested','approved','published','verified','removed')"
                .' OR submitted_at IS NOT NULL',
                ['status', 'submitted_at'],
                'Un entregable en ese estado tiene que decir cuando se envio.'],

            ['permanence_checks', 'ck_pc_source',
                "source IN ('probe','manual')",
                ['source'],
                'Origen de comprobacion no valido: solo «probe» o «manual».'],

            // `is_live` es TINYINT(1) y TINYINT admite hasta 127. Un 7 ahí no es
            // ni vivo ni caído, y quien cuente caídas lo contaría como vivo.
            ['permanence_checks', 'ck_pc_is_live',
                'is_live IN (0,1)',
                ['is_live'],
                'is_live solo admite 0 o 1.'],

            // Una comprobación manual la firma alguien. La sonda no tiene firma
            // y por eso la columna es NULL: quien la puso fue el planificador.
            ['permanence_checks', 'ck_pc_manual',
                "source <> 'manual' OR checked_by_user_id IS NOT NULL",
                ['source', 'checked_by_user_id'],
                'Una comprobacion manual la firma quien miro.'],

            // Y una caída dice ALGO: el estado con que respondió, o una frase.
            // «No estaba» sin nada detrás no vale para parar un pago.
            ['permanence_checks', 'ck_pc_caida_motivada',
                'is_live = 1 OR http_status IS NOT NULL'
                ." OR CHAR_LENGTH(TRIM(COALESCE(notes,''))) >= 5",
                ['is_live', 'http_status', 'notes'],
                'Una comprobacion que dice que el post no esta tiene que decir que vio.'],

            ['permanence_checks', 'ck_pc_no_futuro',
                'created_at IS NULL OR checked_at <= created_at',
                ['created_at', 'checked_at'],
                'Una comprobacion no se puede haber hecho en el futuro.'],
        ];
    }

    /** Las versiones anteriores, para la vuelta atrás. @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restriccionesPrevias(): array
    {
        return [
            ['publications', 'ck_pub_status',
                "status IN ('reported','verified','rejected','removed','expired')",
                ['status'], 'Estado de publicacion no valido.'],
            ['publications', 'ck_pub_permanence',
                "permanence_until IS NULL OR status IN ('verified','removed','expired')",
                ['permanence_until', 'status'],
                'La permanencia se calcula al verificar: antes no hay post del que contarla.'],
            ['publications', 'ck_pub_removed',
                "status <> 'removed' OR removed_at IS NOT NULL",
                ['status', 'removed_at'],
                'Una publicacion retirada tiene que decir cuando.'],
            ['deliverables', 'ck_del_status',
                "status IN ('pending','in_production','submitted','in_review','changes_requested',"
                ."'approved','published','verified','cancelled')",
                ['status'], 'Estado de entregable no valido.'],
            ['deliverables', 'ck_del_submitted',
                "status NOT IN ('submitted','in_review','changes_requested','approved','published','verified')"
                .' OR submitted_at IS NOT NULL',
                ['status', 'submitted_at'],
                'Un entregable en ese estado tiene que decir cuando se envio.'],
        ];
    }

    /** @return list<string> */
    private static function disparadores(): array
    {
        // Los cuatro caben en 128 caracteres a propósito: por encima de ahí,
        // MySQL y Percona devuelven 1648 en vez de 45000 y el mensaje se pierde.
        // Es `T-43`, y lo vigila `tools/verificar-mensajes.py`.
        $sinCaida = 'Para dar un post por caido hace falta una comprobacion que diga que no esta.';

        $sinCaptura = 'Y una captura tomada DESPUES de verificarlo: la que probo que existia no prueba que ya no este.';

        $noVerificada = 'Solo se cae lo que se habia verificado. Si nunca se verifico, esto es un rechazo, no una caida.';

        $antesDeTiempo = 'La ventana no se puede dar por cumplida antes de su fecha.';

        $noVigilada = 'Solo se cierra la ventana de algo que estaba verificado.';

        $reponerSinPrueba = 'Para devolver a verificada una publicacion caida hace falta una captura posterior a la caida.';

        $sinVerificar = 'No se comprueba la permanencia de un post que nadie verifico.';

        $inmutable = 'Una comprobacion de permanencia no se edita: se anota otra.';

        $noBorrar = 'permanence_checks no admite borrado: es lo que para un pago, y de eso se discute despues.';

        // Los dos de 8.2, tal cual, mas `removed` en la lista de cerrados.
        $cerrado = 'Ese entregable no admite mas veredictos. Si hay que volver atras, reabralo diciendo por que.';

        $sinAbrir = 'No se entrega sobre un entregable cerrado. Si hay que cambiarlo, alguien tiene que reabrirlo.';

        return [
            // Un entregable `removed` es un entregable CERRADO. Sin esto se le
            // podria subir una version nueva encima o emitir un veredicto sobre
            // el, y el incumplimiento se disolveria solo.
            'DROP TRIGGER IF EXISTS `tg_cvw_entregable_abierto`',
            <<<SQL
                CREATE TRIGGER `tg_cvw_entregable_abierto` BEFORE INSERT ON `content_reviews`
                FOR EACH ROW
                BEGIN
                  IF EXISTS (SELECT 1 FROM `deliverable_versions` v
                               JOIN `deliverables` d ON d.`id` = v.`deliverable_id`
                              WHERE v.`id` = NEW.`deliverable_version_id`
                                AND (d.`status` IN ('published','verified','removed','cancelled')
                                     OR (d.`status` = 'approved' AND NEW.`outcome` <> 'reopened')))
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$cerrado}';
                  END IF;
                END
                SQL,
            'DROP TRIGGER IF EXISTS `tg_dv_entregable_abierto`',
            <<<SQL
                CREATE TRIGGER `tg_dv_entregable_abierto` BEFORE INSERT ON `deliverable_versions`
                FOR EACH ROW
                BEGIN
                  IF EXISTS (SELECT 1 FROM `deliverables` d
                              WHERE d.`id` = NEW.`deliverable_id`
                                AND d.`status` IN ('approved','published','verified','removed','cancelled'))
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$sinAbrir}';
                  END IF;
                END
                SQL,
            // CROSS-TABLE en los dos sentidos, así que un CHECK no sirve: mira
            // `permanence_checks` y `publication_evidence`.
            //
            // BEFORE UPDATE y no INSERT: una publicación nace `reported` y todo
            // lo de aquí pasa mucho después, moviendo la fila que ya existe.
            <<<SQL
                CREATE TRIGGER `tg_pub_permanencia` BEFORE UPDATE ON `publications`
                FOR EACH ROW
                BEGIN
                  IF NEW.`status` = 'removed' AND OLD.`status` <> 'removed' THEN
                    IF OLD.`status` <> 'verified' THEN
                      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$noVerificada}';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM `permanence_checks` c
                                    WHERE c.`publication_id` = OLD.`id` AND c.`is_live` = 0) THEN
                      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$sinCaida}';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM `publication_evidence` e
                                    WHERE e.`publication_id` = OLD.`id`
                                      AND e.`evidence_type` = 'screenshot'
                                      AND e.`file_id` IS NOT NULL
                                      AND e.`captured_at` > OLD.`verified_at`) THEN
                      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$sinCaptura}';
                    END IF;
                  END IF;

                  IF NEW.`status` = 'fulfilled' AND OLD.`status` <> 'fulfilled' THEN
                    IF OLD.`status` <> 'verified' THEN
                      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$noVigilada}';
                    END IF;
                    -- Sin `IS NULL` a proposito: con NULL la comparacion es NULL
                    -- --el IF no entra-- y de los NULL responde `ck_pub_fulfilled`.
                    -- Si el disparador los cubriera tambien, ese CHECK no seria
                    -- asertable: ganaria siempre el que se evalua primero, y cual
                    -- es depende del motor. Es la leccion de `T-48`.
                    IF NEW.`permanence_until` > DATE(NEW.`fulfilled_at`) THEN
                      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$antesDeTiempo}';
                    END IF;
                  END IF;

                  IF NEW.`status` = 'verified' AND OLD.`status` = 'removed'
                     AND NOT EXISTS (SELECT 1 FROM `publication_evidence` e
                                      WHERE e.`publication_id` = OLD.`id`
                                        AND e.`evidence_type` = 'screenshot'
                                        AND e.`file_id` IS NOT NULL
                                        AND e.`captured_at` > OLD.`removed_at`) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$reponerSinPrueba}';
                  END IF;
                END
                SQL,

            // Comprobar la permanencia de algo que nadie verificó no mide nada:
            // `permanence_until` sale de verificar (8.7) y hasta entonces no hay
            // ventana que vigilar.
            <<<SQL
                CREATE TRIGGER `tg_pc_publicacion_verificada` BEFORE INSERT ON `permanence_checks`
                FOR EACH ROW
                BEGIN
                  IF NOT EXISTS (SELECT 1 FROM `publications` p
                                  WHERE p.`id` = NEW.`publication_id`
                                    AND p.`status` IN ('verified','removed','fulfilled'))
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$sinVerificar}';
                  END IF;
                END
                SQL,

            // Append-only de verdad, como `tg_cvw_inmutable` en 8.3 y por lo
            // mismo: si una comprobación se puede editar, el histórico dice lo
            // que convenga el día que se discuta el pago.
            <<<SQL
                CREATE TRIGGER `tg_pc_inmutable` BEFORE UPDATE ON `permanence_checks`
                FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$inmutable}';
                END
                SQL,

            // 3.12 / T-16: la fila es evidencia y de ella depende dinero.
            <<<SQL
                CREATE TRIGGER `tg_pc_no_delete` BEFORE DELETE ON `permanence_checks`
                FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$noBorrar}';
                END
                SQL,
        ];
    }

    /** Los dos de 8.2, sin `removed`, para la vuelta atrás. @return list<string> */
    private static function disparadoresDe82(): array
    {
        $cerrado = 'Ese entregable no admite mas veredictos. Si hay que volver atras, reabralo diciendo por que.';

        $sinAbrir = 'No se entrega sobre un entregable cerrado. Si hay que cambiarlo, alguien tiene que reabrirlo.';

        return [
            <<<SQL
                CREATE TRIGGER `tg_cvw_entregable_abierto` BEFORE INSERT ON `content_reviews`
                FOR EACH ROW
                BEGIN
                  IF EXISTS (SELECT 1 FROM `deliverable_versions` v
                               JOIN `deliverables` d ON d.`id` = v.`deliverable_id`
                              WHERE v.`id` = NEW.`deliverable_version_id`
                                AND (d.`status` IN ('published','verified','cancelled')
                                     OR (d.`status` = 'approved' AND NEW.`outcome` <> 'reopened')))
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$cerrado}';
                  END IF;
                END
                SQL,
            <<<SQL
                CREATE TRIGGER `tg_dv_entregable_abierto` BEFORE INSERT ON `deliverable_versions`
                FOR EACH ROW
                BEGIN
                  IF EXISTS (SELECT 1 FROM `deliverables` d
                              WHERE d.`id` = NEW.`deliverable_id`
                                AND d.`status` IN ('approved','published','verified','cancelled'))
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$sinAbrir}';
                  END IF;
                END
                SQL,
        ];
    }
};
