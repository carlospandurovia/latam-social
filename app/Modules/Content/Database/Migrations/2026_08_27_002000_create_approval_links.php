<?php

declare(strict_types=1);

namespace App\Modules\Content\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La aprobación del cliente, por enlace firmado (8.5).
 *
 * La primera vez que entra al sistema alguien de la **marca**. Sin portal, sin
 * cuenta y sin contraseña: la autorización es el token, exactamente como la
 * invitación del creador de `7.6` y el enlace de contraseña de `5.9`.
 *
 * ### `DEC-151` — La respuesta se registra; el equipo cierra
 *
 * Decisión de negocio (2026-08-27). El cliente dice «me vale» o «cambiad esto»,
 * y eso queda escrito con su hora y su IP — pero **no mueve el entregable**.
 * Quien emite el veredicto que lo mueve sigue siendo alguien de la plataforma.
 *
 * La alternativa era que su OK aprobara la pieza directamente. Habría obligado a
 * partir `approved` en dos estados: hoy significa «listo para publicar» y `8.6`
 * publica desde ahí, así que sin un «aprobado interno, esperando al cliente» el
 * mismo estado significaría dos cosas — el fallo de `T-50`.
 *
 * Y hay una razón más fuerte: la corrección del cliente **gasta ronda**, y desde
 * `8.4` una ronda de más exige firma y decisión de facturación. El cliente no
 * puede firmar un cargo contra sí mismo. Si su respuesta moviera la pieza sola,
 * o se le cobraría sin que nadie lo autorizara, o habría que dejarle una puerta
 * por la que las rondas no se cuentan. Ninguna de las dos.
 *
 * ### `DEC-152` — El silencio no hace nada
 *
 * El enlace caduca, la pieza se queda donde estaba y sale en la bandeja como
 * «el cliente no contestó». Nadie aprueba ni rechaza en nombre de nadie.
 *
 * **Y por eso esto NO necesita un comando de caducidad**, al revés que las
 * invitaciones de `7.6`. Allí hacía falta porque una invitación sin contestar
 * dejaba dinero comprometido y una plaza de cupo ocupada: había un estado del
 * mundo que corregir. Aquí `expires_at < NOW()` basta para rechazar el enlace, y
 * no hay nada más que cerrar.
 *
 * ### `DEC-153` — Una petición de cambios sin rondas queda pendiente
 *
 * Se registra y **no se convierte en ronda** hasta que alguien del equipo decida
 * si se le cobra o la absorbemos. Al cliente se le confirma que su petición
 * llegó, que es verdad, sin prometerle que será gratis.
 *
 * ### Lo que el cliente ve: sólo su pieza
 *
 * `BR-SEC-001` es 🔴. Ni el importe del creador, ni el presupuesto, ni el margen,
 * ni las demás piezas de la campaña. Y `tg_apl_version_aprobada` impone la otra
 * mitad de `BR-CONTENT-002`: al cliente sólo se le enseña la versión que ya pasó
 * la aprobación interna, y **esa** versión, no cualquiera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_links', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            // Las DOS, para poder cerrar la clave ajena COMPUESTA de 8.2 y 8.6:
            // una simple diria que la version existe; esta dice que es DE LA
            // PIEZA que se manda a aprobar.
            $table->unsignedBigInteger('deliverable_id');
            $table->unsignedBigInteger('deliverable_version_id');
            // Se guarda la HUELLA, nunca el token. Misma pieza que 5.9 y 7.6.
            $table->char('token_hash', 64);
            // A quien se le mando. No es una clave ajena a `contacts` a
            // proposito: lo que importa es a que direccion salio ESTE enlace, y
            // el contacto puede cambiar de correo despues.
            $table->string('sent_to', 255);
            $table->unsignedBigInteger('sent_by_user_id')->nullable();
            $table->dateTime('sent_at', 3);
            $table->dateTime('expires_at', 3);
            $table->dateTime('opened_at', 3)->nullable();
            $table->dateTime('responded_at', 3)->nullable();
            $table->string('response', 20)->nullable();
            $table->string('comments', 2000)->nullable();
            $table->binary('responded_ip')->nullable();
            // La revision que TRANSCRIBIO esta respuesta, cuando alguien del
            // equipo la cierre. Hasta entonces NULL: es lo que hace visible que
            // hay una respuesta del cliente esperando a que alguien decida.
            $table->unsignedBigInteger('content_review_id')->nullable();
            $table->dateTime('revoked_at', 3)->nullable();
            $table->string('revoked_reason', 40)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_apl_uuid');
            $table->unique('token_hash', 'uq_apl_token');
            $table->index(['deliverable_id', 'sent_at'], 'ix_apl_deliverable');
            $table->index(['expires_at', 'responded_at'], 'ix_apl_expires');
            $table->index('sent_by_user_id', 'ix_apl_remitente');
            $table->index('content_review_id', 'ix_apl_revision');
            $table->index('deliverable_version_id', 'ix_apl_version');
        });

        // `responded_ip` como VARBINARY(16), igual que en el resto del esquema:
        // `binary()` de Laravel da BLOB y aqui cabe una IPv6 empaquetada y nada
        // mas. Se cambia despues de crear la tabla porque el Blueprint no lo
        // expresa.
        DB::statement('ALTER TABLE `approval_links` MODIFY `responded_ip` VARBINARY(16) NULL');

        // --- Decimoseptima columna puerta: UN enlace vivo por pieza ----------
        //
        // Dos enlaces vivos sobre el mismo entregable son dos respuestas
        // posibles y contradictorias del mismo cliente, y ninguna forma de saber
        // cual vale. Mismo mecanismo que `uq_inv_viva` en 7.6, y por lo mismo.
        DB::statement('ALTER TABLE `approval_links` ADD COLUMN `viva_gate` TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN `responded_at` IS NULL AND `revoked_at` IS NULL '
            .'THEN 1 ELSE NULL END) STORED');
        DB::statement('ALTER TABLE `approval_links` ADD UNIQUE KEY `uq_apl_viva` '
            .'(`viva_gate`, `deliverable_id`)');

        DB::statement('ALTER TABLE `approval_links` ADD CONSTRAINT `fk_apl_version` '
            .'FOREIGN KEY (`deliverable_version_id`, `deliverable_id`) '
            .'REFERENCES `deliverable_versions` (`id`, `deliverable_id`) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE `approval_links` ADD CONSTRAINT `fk_apl_remitente` '
            .'FOREIGN KEY (`sent_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE `approval_links` ADD CONSTRAINT `fk_apl_revision` '
            .'FOREIGN KEY (`content_review_id`) REFERENCES `content_reviews` (`id`) ON DELETE RESTRICT');

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
        foreach (['tg_apl_version_aprobada', 'tg_apl_respuesta_inmutable', 'tg_apl_no_delete'] as $d) {
            DB::statement("DROP TRIGGER IF EXISTS `{$d}`");
        }

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('approval_links');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['approval_links', 'ck_apl_response',
                "response IS NULL OR response IN ('approved','changes_requested')",
                ['response'],
                'Respuesta de aprobacion no valida.'],

            // Las dos mitades. Contestada implica respuesta, y respuesta implica
            // contestada: una fila con una sin la otra no dice nada util, y de
            // esto cuelga si el cliente dio su conformidad.
            ['approval_links', 'ck_apl_respondida',
                '(responded_at IS NULL AND response IS NULL) '
                .'OR (responded_at IS NOT NULL AND response IS NOT NULL)',
                ['responded_at', 'response'],
                'Una respuesta del cliente dice cuando llego y que dijo, o no existe.'],

            // Pedir cambios exige decir CUALES, igual que `ck_cvw_comments` en
            // 8.3. Un «cambiadlo» sin texto le llega al creador como «hazlo otra
            // vez» y garantiza una vuelta mas --que es justo lo que se cuenta--.
            ['approval_links', 'ck_apl_cambios',
                "response <> 'changes_requested' "
                ."OR CHAR_LENGTH(TRIM(COALESCE(comments,''))) >= 10",
                ['response', 'comments'],
                'Para pedir cambios hay que decir cuales.'],

            ['approval_links', 'ck_apl_plazo',
                'expires_at > sent_at',
                ['expires_at', 'sent_at'],
                'Un enlace que caduca antes de mandarse no sirve para nada.'],

            // No se transcribe una respuesta que no existe.
            ['approval_links', 'ck_apl_transcrita',
                'content_review_id IS NULL OR responded_at IS NOT NULL',
                ['content_review_id', 'responded_at'],
                'No se puede transcribir una respuesta que el cliente no dio.'],

            ['approval_links', 'ck_apl_revocada',
                "revoked_at IS NULL OR CHAR_LENGTH(TRIM(COALESCE(revoked_reason,''))) >= 3",
                ['revoked_at', 'revoked_reason'],
                'Anular un enlace de aprobacion exige decir por que.'],

            // Contestada Y anulada a la vez no significa nada: o el cliente
            // contesto, o el enlace se cerro sin que contestara.
            ['approval_links', 'ck_apl_una_salida',
                'responded_at IS NULL OR revoked_at IS NULL',
                ['responded_at', 'revoked_at'],
                'Un enlace o lo contesta el cliente o se anula. Las dos cosas no.'],

            ['approval_links', 'ck_apl_token',
                'CHAR_LENGTH(token_hash) = 64',
                ['token_hash'],
                'La huella del token mide 64 caracteres.'],
        ];
    }

    /** @return list<string> */
    private static function disparadores(): array
    {
        // Los tres caben en 128 caracteres. Lo vigila `tools/verificar-mensajes.py`.
        $sinAprobar = 'Al cliente solo se le manda lo aprobado, y la version aprobada: apruebe la pieza antes de pedirle su visto bueno.';

        $yaContesto = 'El cliente ya contesto y eso no se reescribe. Si hay que volver a preguntarle, mandele otro enlace.';

        $noBorrar = 'approval_links no admite borrado: es la conformidad del cliente, y de ella depende que se publique.';

        return [
            // CROSS-TABLE: mira `deliverables`, asi que un CHECK no sirve.
            //
            // La otra mitad de `BR-CONTENT-002`. Al cliente no le llega nada sin
            // aprobacion interna --eso ya estaba-- y ademas le llega **la
            // version aprobada**, no otra cualquiera de la pieza. El puntero de
            // 8.2 es lo que hace que esa frase se pueda comprobar.
            <<<SQL
                CREATE TRIGGER `tg_apl_version_aprobada` BEFORE INSERT ON `approval_links`
                FOR EACH ROW
                BEGIN
                  IF NOT EXISTS (SELECT 1 FROM `deliverables` d
                                  WHERE d.`id` = NEW.`deliverable_id`
                                    AND d.`status` = 'approved'
                                    AND d.`approved_version_id` = NEW.`deliverable_version_id`)
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$sinAprobar}';
                  END IF;
                END
                SQL,

            // La fila SI se actualiza --`opened_at`, la respuesta, la
            // transcripcion-- asi que no es append-only entera. Lo que no se
            // toca es la RESPUESTA una vez dada: es la conformidad del cliente,
            // con su hora y su IP, y de ella cuelga que la pieza se publique.
            <<<SQL
                CREATE TRIGGER `tg_apl_respuesta_inmutable` BEFORE UPDATE ON `approval_links`
                FOR EACH ROW
                BEGIN
                  IF OLD.`responded_at` IS NOT NULL
                     AND NOT ((NEW.`responded_at` <=> OLD.`responded_at`)
                              AND (NEW.`response`     <=> OLD.`response`)
                              AND (NEW.`comments`     <=> OLD.`comments`)
                              AND (NEW.`responded_ip` <=> OLD.`responded_ip`))
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$yaContesto}';
                  END IF;
                END
                SQL,

            // 3.12 / T-16: la fila es evidencia y de ella depende dinero.
            <<<SQL
                CREATE TRIGGER `tg_apl_no_delete` BEFORE DELETE ON `approval_links`
                FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$noBorrar}';
                END
                SQL,
        ];
    }
};
