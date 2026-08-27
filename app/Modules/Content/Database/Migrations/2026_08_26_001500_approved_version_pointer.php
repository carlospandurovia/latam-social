<?php

declare(strict_types=1);

namespace App\Modules\Content\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué versión es la buena (8.2).
 *
 * ### Por qué esta columna esperó dos iteraciones
 *
 * El roadmap pedía «puntero de versión actual» desde la Fase 2, y hasta ahora
 * habría sido **una columna que nadie escribe**: el patrón que este proyecto ya
 * ha visto cinco veces. Desde `8.3` hay exactamente quien la escribe — el que
 * aprueba, aprueba **una versión concreta**.
 *
 * ### Apunta a la APROBADA, no a la última
 *
 * «La última» sale de un `MAX(version_number)` y guardarla sería una copia que
 * se puede desviar. La aprobada, en cambio, no es derivable sin recorrer
 * `content_reviews`, y es el dato del que van a colgar `8.6` —se publica lo
 * aprobado— y `8.7` —se archiva evidencia de eso—. Sin ella, las dos tendrían
 * que adivinar cuál.
 *
 * ### La clave ajena es COMPUESTA, y ahí está casi todo el valor
 *
 * `(approved_version_id, id) REFERENCES deliverable_versions(id, deliverable_id)`.
 * Una clave ajena simple garantizaría que la versión existe; ésta garantiza que
 * es **de este entregable**. Sin eso, un `UPDATE` mal escrito puede dejar el
 * entregable de Ana apuntando a la versión aprobada del de Luis, y la fila
 * seguiría siendo válida para la base.
 *
 * Mismo patrón que `fk_ccr_market_campaign` en `7.3`, y por la misma razón.
 *
 * ### Reabrir, con motivo y firma
 *
 * Decisión de negocio (2026-08-26). Con `8.3`, un entregable aprobado era un
 * callejón sin salida: ni más veredictos ni más versiones. Pero el cliente
 * cambia de opinión y a veces se aprueba por error, y el único camino cuando eso
 * pasara —y va a pasar— era que alguien tocara la base a mano.
 *
 * La reapertura **no deshace nada**: es otra fila en `content_reviews`, con su
 * motivo y su firma, y la aprobación anterior se queda en el historial. Es lo
 * mismo que hace `3.11` con la anulación de un perfil fiscal.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Lo que hace posible la clave ajena compuesta. `deliverable_versions.id`
        // ya es unica por ser PK; InnoDB necesita ademas un indice que empiece
        // por las columnas EN EL ORDEN de la referencia.
        Schema::table('deliverable_versions', function (Blueprint $table): void {
            $table->unique(['id', 'deliverable_id'], 'uq_dv_id_deliverable');
        });

        Schema::table('deliverables', function (Blueprint $table): void {
            $table->unsignedBigInteger('approved_version_id')->nullable()->after('approved_by_user_id');
            $table->index('approved_version_id', 'ix_del_approved_version');
        });

        DB::statement('ALTER TABLE `deliverables` ADD CONSTRAINT `fk_del_approved_version` '
            .'FOREIGN KEY (`approved_version_id`, `id`) '
            .'REFERENCES `deliverable_versions` (`id`, `deliverable_id`) ON DELETE RESTRICT');

        // Relleno ANTES del CHECK: si quedara un entregable aprobado sin puntero,
        // la restriccion no se podria crear. Hoy no hay filas, pero una migracion
        // que solo funciona con la base vacia no es una migracion.
        self::rellenar();

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            // Dos de las tres YA EXISTEN --`ck_cvw_outcome` desde 2.12 y
            // `ck_cvw_comments` desde 8.3-- y se redefinen. `comprobacion()` no
            // reemplaza: crea. Sin este `quitar()` la migracion revienta con un
            // duplicado en el motor con CHECK y deja dos disparadores
            // contradictorios en el que no los tiene, que es peor.
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
        DB::statement('DROP TRIGGER IF EXISTS `tg_dv_entregable_abierto`');
        DB::statement('DROP TRIGGER IF EXISTS `tg_cvw_entregable_abierto`');

        // Se repone la version de 8.3, que no conocia `reopened`.
        DB::unprepared(self::disparadorDe83());

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Restriccion::comprobacion(
            tabla: 'content_reviews', nombre: 'ck_cvw_outcome',
            expresion: "outcome IN ('approved','changes_requested','rejected')",
            columnas: ['outcome'],
            mensaje: 'El veredicto solo puede ser aprobado, cambios pedidos o rechazado.',
        );
        Restriccion::comprobacion(
            tabla: 'content_reviews', nombre: 'ck_cvw_comments',
            expresion: "outcome <> 'changes_requested' OR CHAR_LENGTH(TRIM(COALESCE(comments,''))) >= 10",
            columnas: ['outcome', 'comments'],
            mensaje: 'Para pedir cambios hay que decir cuales: el comentario es obligatorio.',
        );

        DB::statement('ALTER TABLE `deliverables` DROP FOREIGN KEY `fk_del_approved_version`');

        Schema::table('deliverables', function (Blueprint $table): void {
            $table->dropIndex('ix_del_approved_version');
            $table->dropColumn('approved_version_id');
        });

        Schema::table('deliverable_versions', function (Blueprint $table): void {
            $table->dropUnique('uq_dv_id_deliverable');
        });
    }

    /** El puntero de lo que ya estaba aprobado antes de que existiera la columna. */
    private static function rellenar(): void
    {
        // La version del veredicto de aprobacion. `tg_cvw_ultima_version` y
        // `tg_cvw_entregable_abierto` garantizan que hay UNA como mucho.
        DB::statement(<<<'SQL'
            UPDATE `deliverables` d
               JOIN `deliverable_versions` v ON v.`deliverable_id` = d.`id`
               JOIN `content_reviews` r ON r.`deliverable_version_id` = v.`id`
                                       AND r.`outcome` = 'approved'
               SET d.`approved_version_id` = v.`id`
             WHERE d.`approved_at` IS NOT NULL AND d.`approved_version_id` IS NULL
            SQL);

        // Y el caso raro: aprobado sin veredicto --lo pudo dejar asi un arreglo a
        // mano--. Se le pone la ultima version, que es lo unico defendible, en
        // vez de dejar la migracion a medias.
        DB::statement(<<<'SQL'
            UPDATE `deliverables` d
               SET d.`approved_version_id` = (
                     SELECT v.`id` FROM `deliverable_versions` v
                      WHERE v.`deliverable_id` = d.`id`
                      ORDER BY v.`version_number` DESC LIMIT 1)
             WHERE d.`approved_at` IS NOT NULL AND d.`approved_version_id` IS NULL
            SQL);

        // Si algo quedo aprobado y SIN NINGUNA version, no hay puntero posible y
        // el CHECK de abajo lo rechazaria. Es una fila imposible --`ck_del_submitted`
        // exige entregado-- pero si existiera, dejarla como no aprobada es menos
        // malo que una migracion que revienta a medias en produccion.
        DB::statement(<<<'SQL'
            UPDATE `deliverables`
               SET `approved_at` = NULL, `approved_by_user_id` = NULL, `status` = 'in_review'
             WHERE `approved_at` IS NOT NULL AND `approved_version_id` IS NULL
            SQL);
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Aprobado y puntero van juntos o no van. Sin esto, la columna es un
            // dato que a veces esta: exactamente igual de util que no tenerlo.
            ['deliverables', 'ck_del_approved_version',
                '(approved_at IS NULL AND approved_version_id IS NULL)'
                .' OR (approved_at IS NOT NULL AND approved_version_id IS NOT NULL)',
                ['approved_at', 'approved_version_id'],
                'Un entregable aprobado tiene que decir QUE version se aprobo.'],

            // `reopened` entra en la lista de veredictos. Reabrir no deshace la
            // aprobacion: la deja donde estaba y anade una fila que dice por que
            // se volvio atras. Misma forma que la anulacion de 3.11.
            ['content_reviews', 'ck_cvw_outcome',
                "outcome IN ('approved','changes_requested','rejected','reopened')",
                ['outcome'],
                'El veredicto solo puede ser aprobado, cambios pedidos, rechazado o reabierto.'],

            // Y reabrir tambien exige decir POR QUE. Una reapertura sin motivo es
            // la que nadie sabe explicar tres meses despues, cuando el cliente
            // pregunta por que se retraso su campana.
            ['content_reviews', 'ck_cvw_comments',
                "outcome NOT IN ('changes_requested','reopened')"
                ." OR CHAR_LENGTH(TRIM(COALESCE(comments,''))) >= 10",
                ['outcome', 'comments'],
                'Pedir cambios o reabrir exige decir por que: el comentario es obligatorio.'],
        ];
    }

    /** @return list<string> */
    private static function disparadores(): array
    {
        // Caben en 128 caracteres a proposito: MySQL y Percona cortan
        // `MESSAGE_TEXT` ahi y sueltan `1648` en vez del `45000`.
        // Lo vigila `tools/verificar-mensajes.py`.
        $cerrado = 'Ese entregable no admite mas veredictos. Si hay que volver atras, reabralo diciendo por que.';

        $sinAbrir = 'No se entrega sobre un entregable cerrado. Si hay que cambiarlo, alguien tiene que reabrirlo.';

        return [
            // Sustituye al de 8.3: aquel bloqueaba TODO veredicto sobre un
            // entregable aprobado, y la reapertura es justo el veredicto que
            // tiene que poder entrar ahi.
            'DROP TRIGGER IF EXISTS `tg_cvw_entregable_abierto`',
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
            // La mitad que faltaba de `Entregables::vetoParaEntregar()`. Ese veto
            // vive en el servicio desde 8.1 y NADA lo respaldaba en la base: un
            // comando, un import o la pantalla de manana podian meter una version
            // encima de algo ya aprobado, y el entregable pasaria a tener
            // aprobado un contenido que nadie aprobo.
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

    /** El disparador de 8.3, tal cual, para la vuelta atras. */
    private static function disparadorDe83(): string
    {
        $cerrado = 'Ese entregable ya esta aprobado o cancelado y no admite mas veredictos. '
            .'Un veredicto no se edita.';

        return <<<SQL
            CREATE TRIGGER `tg_cvw_entregable_abierto` BEFORE INSERT ON `content_reviews`
            FOR EACH ROW
            BEGIN
              IF EXISTS (SELECT 1 FROM `deliverable_versions` v
                           JOIN `deliverables` d ON d.`id` = v.`deliverable_id`
                          WHERE v.`id` = NEW.`deliverable_version_id`
                            AND d.`status` IN ('approved','published','verified','cancelled'))
              THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$cerrado}';
              END IF;
            END
            SQL;
    }
};
