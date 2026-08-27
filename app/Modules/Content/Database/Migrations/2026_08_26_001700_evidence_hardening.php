<?php

declare(strict_types=1);

namespace App\Modules\Content\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La prueba de que el post existió (8.7).
 *
 * ### Por qué la evidencia es una CAPTURA y no una comprobación HTTP
 *
 * Decisión de negocio (2026-08-26), tomada con la limitación técnica delante.
 *
 * Instagram y TikTok devuelven `200` con un muro de login, o `403` a todo lo que
 * no sea un navegador de verdad. Un `http_status` **no distingue** «el post
 * existe» de «nos bloquearon», así que archivar sólo eso sería archivar un dato
 * que no demuestra nada — y `BR-CONTENT-004` es 🔴: de esto cuelga el pago.
 *
 * La sonda HTTP se guarda igual, como dato complementario, y **no decide**. Lo
 * que permite pasar a `verified` es una captura con archivo detrás
 * (`tg_pub_verificada_con_evidencia`).
 *
 * La solución buena son las APIs oficiales —Instagram Graph, TikTok— que sí
 * devuelven el post. Exigen una app revisada por Meta y tokens del creador: son
 * semanas y son `F12`. Hasta entonces, esto es lo honesto con lo que se puede
 * probar hoy, y `§56` del prompt maestro pide exactamente eso.
 *
 * ### `permanence_until` se calcula al verificar
 *
 * `published_at + permanence_days` del requisito. Al verificar y no al reportar,
 * porque hasta que alguien mira no se sabe si hay post que permanezca. Es lo que
 * `8.8` necesita para vigilar que siga vivo.
 *
 * ### Si el post no está, el entregable vuelve al creador
 *
 * Decisión de negocio (2026-08-26). La publicación queda `rejected` con su
 * motivo y el entregable vuelve a `approved`: el contenido no tenía nada malo
 * —ya se aprobó— y lo que falla es que no está publicado. Reabrir el entregable
 * entero sería más duro de lo necesario, y dejarlo rechazado sin más deja una
 * pieza en un estado que nadie va a mover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table): void {
            // Por que se rechazo. `ck_pub_rejected` (8.6) ya exigia CUANDO;
            // faltaba el POR QUE, que es lo que el creador necesita para
            // arreglarlo y lo que contesta «.por que se retraso esta campana?».
            $table->string('rejected_reason', 255)->nullable()->after('verified_by_user_id');
        });

        Schema::table('publication_evidence', function (Blueprint $table): void {
            // `captured_at` es cuando se hizo la captura --puede venir de fuera--
            // y esto es cuando entro la fila. La tabla es de solo insercion y NO
            // lleva `updated_at` a proposito (2.12).
            $table->dateTime('created_at', 3)->nullable();
        });

        Schema::table('permanence_checks', function (Blueprint $table): void {
            // Las dos que le faltaban para poder nombrarse desde fuera y para
            // saber cuando entro cada comprobacion.
            $table->uuid('uuid')->nullable()->after('id');
            $table->dateTime('created_at', 3)->nullable();
        });

        // --- La huella deja de ser unica GLOBALMENTE ------------------------
        //
        // `uq_pub_fingerprint` (2.12) impedia que dos entregables reclamaran el
        // mismo post. Correcto, y con un agujero que aparecio al conectar el
        // rechazo: si la publicacion se rechaza porque el enlace no lleva a
        // ningun post, el creador arregla el post y **vuelve a registrar el
        // mismo enlace** --que es exactamente lo que se le pide-- y se estrella
        // contra la clave unica con un 1062 en la cara.
        //
        // Una fila rechazada no reclama nada: se miro y no valia. Asi que la
        // unicidad pasa a mirar solo las VIVAS, con el mismo mecanismo de
        // columna puerta que el proyecto usa desde 2.4. Es la decimoquinta.
        DB::statement('ALTER TABLE `publications` DROP INDEX `uq_pub_fingerprint`');
        DB::statement('ALTER TABLE `publications` ADD COLUMN `viva_gate` TINYINT UNSIGNED '
            ."GENERATED ALWAYS AS (CASE WHEN `status` <> 'rejected' THEN 1 ELSE NULL END) STORED");
        DB::statement('ALTER TABLE `publications` ADD UNIQUE KEY `uq_pub_fingerprint` '
            .'(`viva_gate`, `url_fingerprint`)');

        DB::statement('UPDATE `permanence_checks` SET `uuid` = UUID() WHERE `uuid` IS NULL');
        DB::statement('ALTER TABLE `permanence_checks` MODIFY `uuid` CHAR(36) NOT NULL');
        DB::statement('ALTER TABLE `permanence_checks` ADD UNIQUE KEY `uq_pc_uuid` (`uuid`)');

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
        DB::statement('DROP TRIGGER IF EXISTS `tg_pub_verificada_con_evidencia`');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Restriccion::comprobacion(
            tabla: 'publications', nombre: 'ck_pub_rejected',
            expresion: "status <> 'rejected' OR verified_at IS NOT NULL",
            columnas: ['status', 'verified_at'],
            mensaje: 'Una publicacion rechazada tiene que decir cuando se reviso.',
        );

        DB::statement('ALTER TABLE `publications` DROP INDEX `uq_pub_fingerprint`');
        DB::statement('ALTER TABLE `publications` DROP COLUMN `viva_gate`');
        DB::statement('ALTER TABLE `publications` ADD UNIQUE KEY `uq_pub_fingerprint` (`url_fingerprint`)');

        DB::statement('ALTER TABLE `permanence_checks` DROP INDEX `uq_pc_uuid`');

        Schema::table('permanence_checks', function (Blueprint $table): void {
            $table->dropColumn(['uuid', 'created_at']);
        });

        Schema::table('publication_evidence', function (Blueprint $table): void {
            $table->dropColumn('created_at');
        });

        Schema::table('publications', function (Blueprint $table): void {
            $table->dropColumn('rejected_reason');
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Rechazada dice CUANDO y POR QUE. La de 8.6 solo exigia el cuando.
            ['publications', 'ck_pub_rejected',
                "status <> 'rejected'"
                ." OR (verified_at IS NOT NULL AND CHAR_LENGTH(TRIM(COALESCE(rejected_reason,''))) >= 5)",
                ['status', 'verified_at', 'rejected_reason'],
                'Una publicacion rechazada tiene que decir cuando se reviso y por que.'],

            // Una CAPTURA sin archivo no es una captura. `ck_pev_content` (2.12)
            // solo pedia «algo»: archivo, payload o estado HTTP. Una fila que
            // dice `screenshot` y trae un 200 pelado se lee como una captura que
            // nadie hizo, y esa es justo la que va a mirar quien discuta el pago.
            ['publication_evidence', 'ck_pev_screenshot',
                "evidence_type <> 'screenshot' OR file_id IS NOT NULL",
                ['evidence_type', 'file_id'],
                'Una captura de pantalla sin archivo no es una captura.'],

            // Y una sonda HTTP dice su estado.
            ['publication_evidence', 'ck_pev_http',
                "evidence_type <> 'http_check' OR http_status IS NOT NULL",
                ['evidence_type', 'http_status'],
                'Una comprobacion HTTP tiene que decir con que estado respondio.'],

            // `permanence_until` solo existe si se verifico: es
            // `published_at + permanence_days` y hasta que alguien mira no se
            // sabe si hay post que permanezca.
            ['publications', 'ck_pub_permanence',
                "permanence_until IS NULL OR status IN ('verified','removed','expired')",
                ['permanence_until', 'status'],
                'La permanencia se calcula al verificar: antes no hay post del que contarla.'],
        ];
    }

    /** @return list<string> */
    private static function disparadores(): array
    {
        // Cabe en 128 caracteres a proposito. Lo vigila `tools/verificar-mensajes.py`.
        $sinPrueba = 'No se da por verificada una publicacion sin una captura archivada. Un estado HTTP no prueba que el post exista.';

        return [
            // CROSS-TABLE: mira `publication_evidence`, asi que un CHECK no sirve.
            //
            // Y es BEFORE UPDATE y no INSERT: una publicacion nace `reported` y
            // la evidencia se archiva DESPUES, asi que el momento en que hay que
            // exigirla es cuando alguien la marca verificada.
            <<<SQL
                CREATE TRIGGER `tg_pub_verificada_con_evidencia` BEFORE UPDATE ON `publications`
                FOR EACH ROW
                BEGIN
                  IF NEW.`status` = 'verified' AND OLD.`status` <> 'verified'
                     AND NOT EXISTS (SELECT 1 FROM `publication_evidence` e
                                      WHERE e.`publication_id` = OLD.`id`
                                        AND e.`evidence_type` = 'screenshot'
                                        AND e.`file_id` IS NOT NULL)
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$sinPrueba}';
                  END IF;
                END
                SQL,
        ];
    }
};
