<?php

declare(strict_types=1);

use App\Shared\Database\Periodo;
use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los términos dejan de ser un comando y pasan a ser una pantalla (9.16).
 *
 * ### Por qué cambia el criterio de 3.5
 *
 * `PublicarTerminosCommand` nació con la idea de que publicar términos ocurre
 * dos o tres veces en la vida del producto y lo hace quien tiene el documento
 * legal delante. Y con una consecuencia que en la práctica no vale: **sin texto
 * publicado no se activa ningún creador**, y el texto sólo podía entrar por
 * consola. Eso convertía una configuración en un bloqueo, y una configuración
 * no se bloquea: se rellena con un valor de partida y se cambia desde el admin.
 *
 * El comando **se queda** —sirve para automatizar y para el despliegue— pero ya
 * no es la única puerta.
 *
 * ### Borrador y publicada no son lo mismo
 *
 * Un borrador se edita todo lo que haga falta. Una versión **publicada se
 * congela**: hay aceptaciones que apuntan a ella con su huella, y cambiarle el
 * texto por debajo dejaría a esas firmas apuntando a algo que ya no dice lo que
 * decía. Editar una publicada crea la siguiente.
 *
 * La puerta `current_gate` se rehace por eso: antes «vigente» era
 * `effective_to IS NULL`, y con borradores eso incluía a los borradores. Ahora
 * es `effective_to IS NULL AND published_at IS NOT NULL`.
 *
 * ### El cambio menor, y por qué lo elige una persona
 *
 * Publicar una versión nueva deja a **todos** los creadores sin cumplir el
 * requisito hasta que acepten. Para una coma o un teléfono corregido eso es
 * absurdo, y en la práctica hace que nadie corrija nada nunca.
 *
 * Así que al publicar se declara si el cambio es **de fondo** —todos vuelven a
 * aceptar— o **menor** —la aceptación anterior sigue valiendo—. Lo decide una
 * persona y **queda escrito**: nadie puede deducir después que aquello fue
 * menor, y si alguien discute, la fila dice quién lo declaró.
 *
 * ### El estado de revisión legal es un DATO, no un bloqueo
 *
 * `review_status` dice si ese texto lo ha mirado un abogado. Nace en
 * `sin_revisar` y la pantalla lo enseña en rojo. No impide nada: informa. Quien
 * opera decide cuándo lo atiende, que es lo que se le pide a una configuración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms_versions', function (Blueprint $table): void {
            $table->dateTime('published_at', 3)->nullable()->after('effective_to');
            $table->string('review_status', 20)->default('sin_revisar')->after('published_by_user_id');
            $table->string('review_note', 255)->nullable()->after('review_status');
            $table->string('change_type', 10)->nullable()->after('review_note');
            $table->unsignedBigInteger('supersedes_version_id')->nullable()->after('change_type');

            $table->index('supersedes_version_id', 'ix_terms_supersedes');
            $table->foreign('supersedes_version_id', 'fk_terms_supersedes')
                ->references('id')->on('terms_versions')->restrictOnDelete();
        });

        // Lo que ya existiera estaba publicado: el comando de 3.5 no sabia
        // guardar borradores.
        DB::table('terms_versions')->whereNull('published_at')
            ->update(['published_at' => DB::raw('COALESCE(created_at, NOW(3))')]);

        // La puerta de «vigente» se rehace: un borrador tiene `effective_to`
        // nulo igual que la vigente, asi que con la definicion vieja los dos
        // chocaban en `uq_terms_versions_current` y no se podia ni guardar un
        // borrador mientras hubiera algo publicado.
        DB::statement('ALTER TABLE `terms_versions` DROP INDEX `uq_terms_versions_current`');
        DB::statement('ALTER TABLE `terms_versions` DROP COLUMN `current_gate`');
        DB::statement(
            'ALTER TABLE `terms_versions` ADD COLUMN `current_gate` TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN `effective_to` IS NULL '
            .'AND `published_at` IS NOT NULL THEN 1 ELSE NULL END) STORED');
        DB::statement('ALTER TABLE `terms_versions` ADD UNIQUE KEY `uq_terms_versions_current` '
            .'(`current_gate`, `code`)');

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // La regla de no solape (3.13) tiene que dejar fuera a los BORRADORES.
        //
        // `tver_sin_solape` prohibe dos periodos abiertos del mismo documento, y
        // un borrador tambien tiene fechas: al crear el segundo borrador la base
        // lo rechazaba diciendo «cierre la anterior el dia antes», que para algo
        // que ni siquiera esta publicado no tiene sentido. **Lo caso la prueba
        // de publicar, no yo**: el borrador ni se podia guardar.
        //
        // Se rehace con el filtro que faltaba. La regla sigue valiendo para todo
        // lo publicado, que es de lo que hablaba.
        Periodo::quitar('terms_versions', 'tver_sin_solape');
        Periodo::sinSolape(
            tabla: 'terms_versions',
            nombre: 'tver_sin_solape',
            serie: ['code'],
            mensaje: 'Ya hay una version de esos terminos vigente en esas fechas: cierre la anterior el dia antes.',
            donde: 'published_at IS NOT NULL',
            columnasDonde: ['published_at'],
            desde: 'effective_from',
            hasta: 'effective_to',
        );

        // Una version publicada no se reescribe. Hay aceptaciones que apuntan a
        // ella con su huella; cambiarle el texto por debajo dejaria esas firmas
        // apuntando a algo que ya no dice lo que decia.
        //
        // Lo que SI se puede tocar en una publicada: cerrarla (`effective_to`) y
        // su estado de revision legal, que es informacion sobre el texto y no el
        // texto.
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
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_terms_inmutable`');

        Periodo::quitar('terms_versions', 'tver_sin_solape');
        Periodo::sinSolape(
            tabla: 'terms_versions',
            nombre: 'tver_sin_solape',
            serie: ['code'],
            mensaje: 'Ya hay una version de esos terminos vigente en esas fechas: cierre la anterior el dia antes.',
            desde: 'effective_from',
            hasta: 'effective_to',
        );

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        DB::statement('ALTER TABLE `terms_versions` DROP INDEX `uq_terms_versions_current`');
        DB::statement('ALTER TABLE `terms_versions` DROP COLUMN `current_gate`');
        DB::statement(
            'ALTER TABLE `terms_versions` ADD COLUMN `current_gate` TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN `effective_to` IS NULL THEN 1 ELSE NULL END) STORED');
        DB::statement('ALTER TABLE `terms_versions` ADD UNIQUE KEY `uq_terms_versions_current` '
            .'(`current_gate`, `code`)');

        Schema::table('terms_versions', function (Blueprint $table): void {
            $table->dropForeign('fk_terms_supersedes');
            $table->dropIndex('ix_terms_supersedes');
            $table->dropColumn(['published_at', 'review_status', 'review_note',
                'change_type', 'supersedes_version_id']);
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['terms_versions', 'ck_terms_review',
                "review_status IN ('sin_revisar','en_revision','revisado')",
                ['review_status'],
                'Estado de revision legal no valido.'],
            // NULL en la primera version: no reemplaza a ninguna.
            ['terms_versions', 'ck_terms_change_type',
                "change_type IS NULL OR change_type IN ('fondo','menor')",
                ['change_type'],
                'El cambio es de fondo o menor, no hay una tercera cosa.'],
            // Publicar es un acto con responsable.
            ['terms_versions', 'ck_terms_publicada',
                'published_at IS NULL OR published_by_user_id IS NOT NULL',
                ['published_at', 'published_by_user_id'],
                'Publicar unos terminos exige decir quien los publico.'],
            // Un borrador no puede estar cerrado: nunca estuvo abierto.
            ['terms_versions', 'ck_terms_borrador_abierto',
                'published_at IS NOT NULL OR effective_to IS NULL',
                ['published_at', 'effective_to'],
                'Un borrador no se cierra: nunca llego a estar vigente.'],
        ];
    }
};
