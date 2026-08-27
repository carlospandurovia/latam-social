<?php

declare(strict_types=1);

namespace App\Modules\Content\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El post sale al mundo (8.6).
 *
 * ### La publicación cuelga de la VERSIÓN, no sólo del entregable
 *
 * `publications` apuntaba a `deliverable_id` desde la Fase 2, y eso deja sin
 * responder la pregunta que importa: **qué se publicó exactamente**. Un
 * entregable puede tener tres versiones y haberse reabierto por el medio.
 *
 * Es un **snapshot**, igual que `amount_snapshot` en `7.6`: registra lo que se
 * publicó *entonces*, y sobrevive a que el entregable se reabra y se apruebe otra
 * versión después. Por eso se guarda aunque el puntero de `8.2` ya diga cuál es
 * la aprobada de hoy.
 *
 * ### Y sólo se publica lo aprobado
 *
 * Decisión de negocio (2026-08-26). `BR-CONTENT-002` dice que nada llega al
 * cliente sin aprobación interna, y registrar la publicación de algo no aprobado
 * es darlo por bueno a posteriori — con la firma de nadie.
 *
 * `tg_pub_version_aprobada` lo impone en la base y no sólo en la pantalla,
 * porque de aquí cuelga el pago: `8.7` verifica esta fila y `8.8` cuenta su
 * permanencia.
 *
 * ### La fecha no puede ser del futuro
 *
 * Un post «publicado mañana» no existe. Un `CHECK` no puede llamar a `NOW()`
 * —no es determinista— así que se compara contra `created_at`, que es cuando
 * entró la fila. Quien la escribe tiene que usar **el mismo instante** para las
 * dos: dos `now()` separados pueden caer a los dos lados de un milisegundo, que
 * es exactamente el fallo intermitente que costó `T-39`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table): void {
            // Qué versión se publicó. Nullable primero: la columna se rellena y
            // se endurece abajo, para que la migración funcione con datos.
            $table->unsignedBigInteger('deliverable_version_id')->nullable()->after('deliverable_id');
            // Quién lo reportó. Puede ser el creador desde su portal o alguien
            // del equipo por él --llega por WhatsApp y hay que meterlo-- y en los
            // dos casos «.quien dijo que esto estaba publicado?» tiene que
            // responderlo la fila.
            $table->unsignedBigInteger('reported_by_user_id')->nullable()->after('published_at');
            $table->binary('reported_ip', 16)->nullable()->after('reported_by_user_id');

            $table->index('deliverable_version_id', 'ix_pub_version');
            $table->index('reported_by_user_id', 'ix_pub_reporter');
            $table->foreign('reported_by_user_id', 'fk_pub_reporter')
                ->references('id')->on('users')->restrictOnDelete();
        });

        self::rellenar();

        DB::statement('ALTER TABLE `publications` MODIFY `deliverable_version_id` BIGINT UNSIGNED NOT NULL');

        // COMPUESTA, como el puntero de 8.2 y por lo mismo: una simple diria que
        // la version existe; esta dice que es DEL ENTREGABLE que se publica.
        DB::statement('ALTER TABLE `publications` ADD CONSTRAINT `fk_pub_version` '
            .'FOREIGN KEY (`deliverable_version_id`, `deliverable_id`) '
            .'REFERENCES `deliverable_versions` (`id`, `deliverable_id`) ON DELETE RESTRICT');

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
        DB::statement('DROP TRIGGER IF EXISTS `tg_pub_version_aprobada`');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        DB::statement('ALTER TABLE `publications` DROP FOREIGN KEY `fk_pub_version`');

        Schema::table('publications', function (Blueprint $table): void {
            $table->dropForeign('fk_pub_reporter');
            $table->dropIndex('ix_pub_reporter');
            $table->dropIndex('ix_pub_version');
            $table->dropColumn(['deliverable_version_id', 'reported_by_user_id', 'reported_ip']);
        });
    }

    /** Qué versión se publicó, para lo que ya estaba registrado. */
    private static function rellenar(): void
    {
        // La aprobada, si la hay; si no, la ultima. Es lo unico defendible: una
        // publicacion vieja dice QUE entregable, no que version, y adivinarlo
        // hacia atras no se puede hacer mejor que esto.
        DB::statement(<<<'SQL'
            UPDATE `publications` p
               SET p.`deliverable_version_id` = COALESCE(
                     (SELECT d.`approved_version_id` FROM `deliverables` d WHERE d.`id` = p.`deliverable_id`),
                     (SELECT v.`id` FROM `deliverable_versions` v
                       WHERE v.`deliverable_id` = p.`deliverable_id`
                       ORDER BY v.`version_number` DESC LIMIT 1))
             WHERE p.`deliverable_version_id` IS NULL
            SQL);

        // Y si quedara alguna sobre un entregable SIN NINGUNA version, se borra:
        // es una fila que no puede decir que se publico, y `publications` no
        // lleva `no_delete` --no es evidencia, la evidencia es
        // `publication_evidence`, que si lo lleva--.
        DB::statement('DELETE FROM `publications` WHERE `deliverable_version_id` IS NULL');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Un post «publicado manana» no existe. `NOW()` no se puede usar en un
            // CHECK --no es determinista-- asi que se compara contra el momento en
            // que entro la fila.
            ['publications', 'ck_pub_published_no_futuro',
                'created_at IS NULL OR published_at <= created_at',
                ['published_at', 'created_at'],
                'Un post no se puede haber publicado en el futuro.'],

            // `rejected` es un veredicto sobre la publicacion --el enlace no era
            // lo que decia-- y tiene que decir cuando se decidio, igual que
            // `verified` y `removed` desde 2.12.
            ['publications', 'ck_pub_rejected',
                "status <> 'rejected' OR verified_at IS NOT NULL",
                ['status', 'verified_at'],
                'Una publicacion rechazada tiene que decir cuando se reviso.'],
        ];
    }

    /** @return list<string> */
    private static function disparadores(): array
    {
        // Cabe en 128 caracteres a proposito. Lo vigila `tools/verificar-mensajes.py`.
        $sinAprobar = 'Solo se publica lo aprobado, y la version aprobada. Apruebe el entregable antes de registrar el post.';

        return [
            // CROSS-TABLE: mira `deliverables`, asi que un CHECK no sirve.
            <<<SQL
                CREATE TRIGGER `tg_pub_version_aprobada` BEFORE INSERT ON `publications`
                FOR EACH ROW
                BEGIN
                  IF NOT EXISTS (SELECT 1 FROM `deliverables` d
                                  WHERE d.`id` = NEW.`deliverable_id`
                                    AND d.`approved_at` IS NOT NULL
                                    AND d.`approved_version_id` = NEW.`deliverable_version_id`)
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$sinAprobar}';
                  END IF;
                END
                SQL,
        ];
    }
};
