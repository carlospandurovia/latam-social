<?php

declare(strict_types=1);

namespace App\Modules\Content\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los entregables empiezan a existir (8.1).
 *
 * ### Cuarta vez que una tabla de la Fase 2 estrena su primera fila
 *
 * `campaign_creators` antes de 7.4, `domain_events` antes de 4.13, `invitations`
 * antes de 7.6, y ahora `deliverables` y `deliverable_versions`. Las cuatro veces
 * el diseño aguantó; lo que faltaba era lo que sólo se ve al usarlo.
 *
 * ### `hashtags` y `mentions` en el brief
 *
 * `7.2` los dejó fuera **a propósito**: *«sin mercados (7.3) un requisito no se
 * puede partir por país»*. Los mercados llegaron en 7.3, así que ya se puede
 * decir *«en Perú #ACMEVerano, en México #ACMEVerano_MX»* — y esa es justo la
 * clase de dato que se pide por país.
 *
 * Se guardan como texto separado por espacios y **no** como tabla aparte. Una
 * tabla `requirement_hashtags` daría orden, unicidad y nada más: nadie va a
 * consultar «campañas que usaron #verano» desde aquí, y si algún día hace falta,
 * sale de `deliverable_versions.caption`, que es donde de verdad se usaron.
 *
 * ### Lo que un entregable NO tiene todavía
 *
 * No hay puntero a la versión vigente —eso es `8.2`— ni comentarios de revisión
 * —`8.3`—. `uq_dv_number` ya impide dos versiones con el mismo número, que es lo
 * único que hace falta para que el histórico sea append-only de verdad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_requirements', function (Blueprint $table): void {
            // Separados por espacios: «#ACMEVerano #Publicidad». La `#` y la `@`
            // se guardan porque es como las escribe y las lee una persona, y
            // porque quitarlas obligaria a ponerlas al comparar.
            $table->string('hashtags', 255)->nullable()->after('notes');
            $table->string('mentions', 255)->nullable()->after('hashtags');
        });

        Schema::table('deliverable_versions', function (Blueprint $table): void {
            // Quien la mando. `deliverables` cuelga de una participacion, asi
            // que el creador se deduce; pero una version la puede subir el
            // equipo en su nombre --pasa-- y entonces «.quien mando esto?» tiene
            // que responderlo la fila.
            $table->unsignedBigInteger('submitted_by_user_id')->nullable()->after('submitted_at');
            $table->binary('submitted_ip', 16)->nullable()->after('submitted_by_user_id');
            $table->dateTime('created_at', 3)->nullable();

            $table->index('submitted_by_user_id', 'ix_dv_autor');
            $table->foreign('submitted_by_user_id', 'fk_dv_autor')
                ->references('id')->on('users')->restrictOnDelete();
        });

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
        DB::statement('DROP TRIGGER IF EXISTS `tg_dv_participacion_viva`');
        DB::statement('DROP TRIGGER IF EXISTS `tg_del_participacion_aceptada`');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::table('deliverable_versions', function (Blueprint $table): void {
            $table->dropForeign('fk_dv_autor');
            $table->dropIndex('ix_dv_autor');
            $table->dropColumn(['submitted_by_user_id', 'submitted_ip', 'created_at']);
        });

        Schema::table('campaign_requirements', function (Blueprint $table): void {
            $table->dropColumn(['hashtags', 'mentions']);
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Un enlace de entrega va por HTTPS. No es celo: un `http://` en un
            // correo o en una pantalla es una invitacion a que alguien lo
            // intercepte, y ademas los sitios donde se sube contenido --Drive,
            // WeTransfer, los borradores de las plataformas-- son todos https.
            //
            // `javascript:` y `data:` quedan fuera por el mismo filtro, y esos
            // si son un problema de verdad: la URL se pinta en una pantalla
            // interna donde alguien la va a pulsar.
            ['deliverable_versions', 'ck_dv_url_https',
                "external_url IS NULL OR external_url LIKE 'https://%'",
                ['external_url'],
                'El enlace de entrega tiene que empezar por https://'],

            // Entregado exige CUANDO. `ck_del_approved` ya exigia que aprobado
            // implicara entregado; faltaba la mitad de abajo.
            ['deliverables', 'ck_del_submitted',
                "status NOT IN ('submitted','in_review','changes_requested','approved','published','verified')"
                .' OR submitted_at IS NOT NULL',
                ['status', 'submitted_at'],
                'Un entregable enviado tiene que decir cuando se envio.'],

            // La fecha limite no puede ser anterior a que exista el entregable.
            // Un plazo en el pasado nace vencido y no es un plazo: es un error
            // de calculo que nadie mira hasta que la lista sale toda en rojo.
            ['deliverables', 'ck_del_due_futuro', 'due_on >= DATE(created_at) OR created_at IS NULL',
                ['due_on', 'created_at'],
                'La fecha limite de un entregable no puede ser anterior al dia en que se creo.'],
        ];
    }

    /** @return list<string> */
    private static function disparadores(): array
    {
        // Cabe en 128 caracteres A PROPOSITO. `MESSAGE_TEXT` es VARCHAR(128) y
        // MySQL/Percona no truncan: sueltan `1648 Data too long for condition
        // item` en vez del `45000` que el mensaje queria dar. MariaDB si lo deja
        // pasar, asi que esto solo se ve en produccion. Gate permanente:
        // `tools/verificar-mensajes.py`, que lo mira contra Percona en el CI.
        $sinAceptar = 'No se crean entregables de una participacion sin aceptar: '
            .'lo que hay que entregar sale del compromiso, y no lo hay.';

        $muerta = 'Esa participacion ya no esta viva: no se le pueden anadir entregas. '
            .'Si el creador vuelve, es una participacion nueva.';

        return [
            // Los dos son CROSS-TABLE --miran `campaign_creators`-- asi que un
            // CHECK no sirve: solo ve su propia fila. Misma razon que
            // `tg_ccr_campana_cerrada` en 7.4.
            <<<SQL
                CREATE TRIGGER `tg_del_participacion_aceptada` BEFORE INSERT ON `deliverables`
                FOR EACH ROW
                BEGIN
                  IF NOT EXISTS (SELECT 1 FROM `campaign_creators`
                                  WHERE `id` = NEW.`campaign_creator_id`
                                    AND `accepted_at` IS NOT NULL)
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$sinAceptar}';
                  END IF;
                END
                SQL,
            <<<SQL
                CREATE TRIGGER `tg_dv_participacion_viva` BEFORE INSERT ON `deliverable_versions`
                FOR EACH ROW
                BEGIN
                  IF EXISTS (SELECT 1 FROM `deliverables` d
                               JOIN `campaign_creators` cc ON cc.`id` = d.`campaign_creator_id`
                              WHERE d.`id` = NEW.`deliverable_id`
                                AND cc.`status` IN ('declined','expired','cancelled'))
                  THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$muerta}';
                  END IF;
                END
                SQL,
        ];
    }
};
