<?php

declare(strict_types=1);

use App\Shared\Database\Periodo;
use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las páginas del sitio público (L-2b).
 *
 * ### Lo que NO se construye aquí, y por qué importa decirlo
 *
 * `terms_versions` existe desde `9.16` y ya es un sistema de documentos legales
 * completo: versión, huella `SHA-256`, inmutabilidad al publicar, fecha de
 * vigencia, estado de revisión por un abogado y tipo de cambio —de fondo o
 * menor—. Es **el contrato que un creador acepta con un clic registrado**, y
 * `terms_acceptances` apunta a una versión concreta.
 *
 * **Eso no se duplica.** Si «términos y condiciones» viviera también aquí,
 * habría dos verdades sobre lo mismo y ningún modo de saber cuál rige. La página
 * pública de los términos del creador **enseñará** la versión vigente de
 * `terms_versions`, no una copia.
 *
 * Lo que sí falta y es esto: las **páginas públicas del sitio** —la política de
 * privacidad, el aviso legal, «sobre nosotros», las cookies—. Nadie las acepta
 * con un clic; se publican y se leen.
 *
 * ### Y sin embargo llevan versión
 *
 * Porque de una política de privacidad hay que poder contestar **«¿cuál estaba
 * vigente el día que esta persona nos dio sus datos?»**, y esa pregunta la hace
 * quien no se puede contestar con «la de ahora». Es la misma forma de
 * `terms_versions` sin la mitad de la aceptación.
 *
 * Una página cuya historia no le importa a nadie simplemente no publica una
 * segunda versión, y la maquinaria no estorba.
 *
 * ### El cuerpo es Markdown, no HTML
 *
 * Un documento legal necesita títulos, listas y énfasis, así que texto plano no
 * vale. Y HTML crudo editable desde el panel es **XSS almacenado en la página
 * más pública del sitio**: quien edite —o quien le robe la sesión a quien
 * edite— escribiría `<script>` en `latamsocial.com`.
 *
 * Markdown se convierte con `league/commonmark` —que ya está instalado— con
 * `html_input: escape`: el HTML que venga dentro **se enseña como texto, no se
 * ejecuta**. Y de propina el texto legal queda legible en la base y comparable
 * entre versiones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('platform_brand_id');
            // La URL. `politica-de-privacidad`, no `p/17`: una politica de
            // privacidad se enlaza desde correos, contratos y formularios, y un
            // identificador numerico ahi es un enlace que no dice nada.
            $table->string('slug', 60);
            $table->string('title', 160);
            $table->string('meta_title', 70)->nullable();
            $table->string('meta_description', 180)->nullable();
            // En el pie de la portada, y en que orden.
            $table->boolean('show_in_footer')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            // Las que el sistema necesita --privacidad, terminos-- no se borran.
            // Su texto se edita entero; lo que no se puede es dejar el pie de la
            // portada sin politica de privacidad por un clic.
            $table->boolean('is_system')->default(false);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_cp_uuid');
            $table->unique(['platform_brand_id', 'slug'], 'uq_cp_slug');
            $table->index(['platform_brand_id', 'show_in_footer', 'sort_order'], 'ix_cp_pie');

            $table->foreign('platform_brand_id', 'fk_cp_brand')
                ->references('id')->on('platform_brands')->restrictOnDelete();
        });

        Schema::create('content_page_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('content_page_id');
            $table->string('version', 20);
            // Markdown, no HTML. El motivo esta arriba y no es de estilo.
            $table->longText('body_markdown');
            // La huella de lo publicado. Si alguien edita por debajo de los
            // disparadores, deja de cuadrar y se nota. Misma idea que
            // `terms_versions.content_sha256` en `9.16`.
            $table->char('content_sha256', 64);
            $table->date('effective_from');
            // NULL = es la vigente. Publicar la siguiente cierra esta.
            $table->date('effective_to')->nullable();
            // NULL = borrador. Un borrador se edita libremente; una publicada se
            // congela, porque es el texto que alguien pudo haber leido.
            $table->dateTime('published_at', 3)->nullable();
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            // §56: un supuesto legal se identifica EXPLICITAMENTE para revision
            // juridica. El sistema arranca con un texto de partida que dice de
            // si mismo que no lo ha revisado ningun abogado. Es un DATO, no una
            // puerta: no bloquea publicar (`DEC-190`), lo dice.
            $table->string('review_status', 20)->default('sin_revisar');
            $table->string('review_note', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('uuid', 'uq_cpv_uuid');
            $table->unique(['content_page_id', 'version'], 'uq_cpv_version');
            $table->index(['content_page_id', 'effective_from'], 'ix_cpv_pagina');
            $table->index('published_by_user_id', 'ix_cpv_publicador');

            $table->foreign('content_page_id', 'fk_cpv_page')
                ->references('id')->on('content_pages')->restrictOnDelete();
            $table->foreign('published_by_user_id', 'fk_cpv_publicador')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // Columna puerta 36: UNA vigente por pagina.
        //
        // Dos versiones publicadas y abiertas a la vez es un empate, y un empate
        // aqui significa que la pregunta «.que politica de privacidad rige?» no
        // tiene respuesta. Un indice unico y no un `COUNT(*)` en el codigo: ese
        // recuento sin bloqueo deja pasar las dos si se publican a la vez.
        //
        // Dos sentencias y no una con dos `ADD`, como las 35 puertas anteriores:
        // `tools/recolectar-esquema.php` reconoce `ALTER TABLE x ADD UNIQUE KEY`
        // al principio de la sentencia, y una combinada le pasa desapercibida.
        // Lo dijo `verificar-migraciones.py` al primer intento.
        DB::statement(
            'ALTER TABLE `content_page_versions` ADD COLUMN `vigente_gate` BIGINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN `published_at` IS NOT NULL '
            .'AND `effective_to` IS NULL THEN `content_page_id` ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE `content_page_versions` ADD UNIQUE KEY `uq_cpv_vigente` (`vigente_gate`)',
        );

        // Una version PUBLICADA no se reescribe. Es el texto que alguien pudo
        // haber leido el dia que nos dio sus datos; corregirlo por debajo seria
        // cambiar la respuesta a una pregunta ya hecha. Se publica la siguiente.
        DB::unprepared(
            'CREATE TRIGGER `tg_cpv_inmutable`
             BEFORE UPDATE ON `content_page_versions`
             FOR EACH ROW
             BEGIN
                 IF OLD.`published_at` IS NOT NULL THEN
                     IF NOT (NEW.`body_markdown` <=> OLD.`body_markdown`)
                        OR NOT (NEW.`content_sha256` <=> OLD.`content_sha256`)
                        OR NOT (NEW.`version` <=> OLD.`version`)
                        OR NOT (NEW.`effective_from` <=> OLD.`effective_from`) THEN
                         SIGNAL SQLSTATE \'45000\'
                           SET MESSAGE_TEXT = \'Una version publicada no se reescribe: publique la siguiente.\';
                     END IF;
                 END IF;
             END',
        );

        // El historico no se solapa.
        //
        // `uq_cpv_vigente` garantiza una sola versión ABIERTA. Lo que no
        // garantiza es que el historico tenga una sola respuesta para una fecha
        // PASADA: v1.0 del 1 de enero al 30 de junio y v1.1 desde el 1 de marzo
        // son dos versiones tapando marzo.
        //
        // Y aqui esa pregunta es justo la que importa: **«.que politica de
        // privacidad regia el dia que esta persona nos dio sus datos?»**. Dos
        // respuestas es no tener ninguna.
        //
        // Solo entre versiones PUBLICADAS: un borrador no rige nada, y su
        // `effective_from` es provisional hasta que alguien lo publique.
        Periodo::sinSolape(
            tabla: 'content_page_versions',
            nombre: 'cpv_sin_solape',
            serie: ['content_page_id'],
            mensaje: 'Ya hay una version de esa pagina vigente en esas fechas.',
            donde: 'published_at IS NOT NULL',
            columnasDonde: ['published_at'],
            desde: 'effective_from',
            hasta: 'effective_to',
        );

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `tg_cpv_inmutable`');

        Periodo::quitar('content_page_versions', 'cpv_sin_solape');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('content_page_versions');
        Schema::dropIfExists('content_pages');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Minusculas, digitos y guiones. `COLLATE utf8mb4_bin` porque
            // `REGEXP` compara con la colacion de la columna y la de este
            // proyecto es `_ci` --insensible a mayusculas--: sin el, `Politica`
            // pasaria y la URL saldria con mayuscula. Lo aprendimos en `L-2a`.
            ['content_pages', 'ck_cp_slug',
                "slug COLLATE utf8mb4_bin REGEXP '^[a-z0-9]([a-z0-9-]{0,58}[a-z0-9])?$'",
                ['slug'], 'La direccion de la pagina va en minusculas, con guiones y sin acentos.'],

            ['content_pages', 'ck_cp_titulo', 'CHAR_LENGTH(TRIM(title)) >= 3',
                ['title'], 'La pagina necesita un titulo.'],

            // Un documento legal vacio no es un documento legal.
            ['content_page_versions', 'ck_cpv_cuerpo',
                'CHAR_LENGTH(TRIM(body_markdown)) >= 20',
                ['body_markdown'], 'El texto de la pagina no puede estar practicamente vacio.'],

            ['content_page_versions', 'ck_cpv_huella', 'CHAR_LENGTH(content_sha256) = 64',
                ['content_sha256'], 'La huella del texto tiene 64 caracteres.'],

            ['content_page_versions', 'ck_cpv_fechas',
                'effective_to IS NULL OR effective_to >= effective_from',
                ['effective_to'], 'Una version no puede cerrarse antes de empezar.'],

            // Publicar es un acto con responsable. Misma regla que `9.16`.
            ['content_page_versions', 'ck_cpv_publicada',
                'published_at IS NULL OR published_by_user_id IS NOT NULL',
                ['published_at'], 'Publicar una pagina es un acto con responsable: falta quien.'],

            // Un borrador no se cierra: nunca llego a estar vigente.
            ['content_page_versions', 'ck_cpv_borrador_abierto',
                'published_at IS NOT NULL OR effective_to IS NULL',
                ['effective_to'], 'Un borrador no se cierra: nunca estuvo vigente.'],

            ['content_page_versions', 'ck_cpv_revision',
                "review_status IN ('sin_revisar','en_revision','revisado')",
                ['review_status'], 'La revision juridica es: sin_revisar, en_revision o revisado.'],
        ];
    }
};
