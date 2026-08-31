<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los plazos para volver a aceptar unos términos nuevos (9.19).
 *
 * ### De dónde sale
 *
 * `Q-46`, con tus palabras: *«Les llega un correo y tienen 15 días para
 * aceptarlos […] si no lo aceptaron ingresa a una pantalla donde les exige
 * aprobar para continuar; en caso contrario podrán ver todo en sólo lectura por
 * 30 días. Todo configurable desde el admin.»*
 *
 * `9.16` ya distingue el cambio **de fondo** —todos vuelven a aceptar— del
 * **menor**. Lo que faltaba era **qué pasa mientras no aceptan**, y eso son dos
 * plazos.
 *
 * ### Van en la VERSIÓN, no en una tabla de ajustes
 *
 * Porque el plazo es parte de lo que se le comunicó a la gente. «Tienes 15 días»
 * dicho en enero no puede convertirse en «tenías 10» porque en marzo alguien
 * cambió un ajuste global. Cada versión lleva los suyos, se eligen al
 * publicarla, y a partir de ese momento **son inmutables** como el resto del
 * documento: `tg_terms_inmutable` se rehace para incluirlos.
 *
 * El valor por defecto de la columna —15 y 30— es lo configurable: el formulario
 * de publicación lo trae puesto y quien publica lo cambia si quiere. Eso es
 * exactamente lo que pedía `DEC-190`: la regla en el código, el valor fuera.
 *
 * ### `readonly_days = 0` es una opción, y significa algo
 *
 * Significa «sin periodo de sólo lectura»: pasado el plazo, a aceptar. Un número
 * muy grande significa lo contrario —nunca se bloquea del todo—. Las dos son
 * decisiones legítimas y por eso el rango es abierto por arriba: quien opera
 * elige la dureza, no el código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms_versions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('acceptance_days')->default(15)->after('change_type');
            $table->unsignedSmallInteger('readonly_days')->default(30)->after('acceptance_days');
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // Los plazos entran en la inmutabilidad de 9.16. «Tienes 15 dias» dicho
        // en enero no puede convertirse en «tenias 10» en marzo: es parte de lo
        // que se le comunico a la gente, no un ajuste.
        DB::statement('DROP TRIGGER IF EXISTS `tg_terms_inmutable`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_terms_inmutable`
            BEFORE UPDATE ON `terms_versions`
            FOR EACH ROW
            BEGIN
                IF OLD.`published_at` IS NOT NULL THEN
                    IF NOT (NEW.`body` <=> OLD.`body`)
                       OR NOT (NEW.`content_sha256` <=> OLD.`content_sha256`)
                       OR NOT (NEW.`code` <=> OLD.`code`)
                       OR NOT (NEW.`version` <=> OLD.`version`)
                       OR NOT (NEW.`audience` <=> OLD.`audience`)
                       OR NOT (NEW.`effective_from` <=> OLD.`effective_from`)
                       OR NOT (NEW.`change_type` <=> OLD.`change_type`)
                       OR NOT (NEW.`acceptance_days` <=> OLD.`acceptance_days`)
                       OR NOT (NEW.`readonly_days` <=> OLD.`readonly_days`) THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Una version publicada no se reescribe: cree la siguiente.';
                    END IF;

                    IF NEW.`published_at` IS NULL THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Una version publicada no vuelve a ser borrador.';
                    END IF;
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_terms_inmutable`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_terms_inmutable`
            BEFORE UPDATE ON `terms_versions`
            FOR EACH ROW
            BEGIN
                IF OLD.`published_at` IS NOT NULL THEN
                    IF NOT (NEW.`body` <=> OLD.`body`)
                       OR NOT (NEW.`content_sha256` <=> OLD.`content_sha256`)
                       OR NOT (NEW.`code` <=> OLD.`code`)
                       OR NOT (NEW.`version` <=> OLD.`version`)
                       OR NOT (NEW.`audience` <=> OLD.`audience`)
                       OR NOT (NEW.`effective_from` <=> OLD.`effective_from`)
                       OR NOT (NEW.`change_type` <=> OLD.`change_type`) THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Una version publicada no se reescribe: cree la siguiente.';
                    END IF;

                    IF NEW.`published_at` IS NULL THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Una version publicada no vuelve a ser borrador.';
                    END IF;
                END IF;
            END
            SQL);

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::table('terms_versions', function (Blueprint $table): void {
            $table->dropColumn(['acceptance_days', 'readonly_days']);
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Cero dias para aceptar es publicar y bloquear en el mismo
            // instante: nadie ha podido leer nada. Un dia es poco, pero es una
            // decision; cero es un error de tecleo.
            ['terms_versions', 'ck_terms_plazo', 'acceptance_days >= 1',
                ['acceptance_days'], 'El plazo para aceptar es de un dia como minimo.'],

            // `readonly_days = 0` SI vale: significa «sin periodo de solo
            // lectura», que es una eleccion legitima. Solo se impide lo absurdo.
            ['terms_versions', 'ck_terms_lectura', 'readonly_days >= 0',
                ['readonly_days'], 'Los dias de solo lectura no pueden ser negativos.'],
        ];
    }
};
