<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La portada deja de ser una plantilla con tres huecos (L-3).
 *
 * ### El problema que resuelve, dicho sin adornos
 *
 * Hasta hoy un bloque tenía un `kind` —`feature`, `step`, `faq`— y la plantilla
 * decidía todo lo demás: en qué orden salían las tres franjas, qué encabezado
 * llevaba cada una y si llevaba alguno. «Cómo funciona» y «Preguntas» estaban
 * **escritos en el `.blade.php`**. Eso es `DEC-190` roto en el sitio más visible
 * del producto: lo primero que ve alguien que no es cliente todavía.
 *
 * Y no es sólo un texto. El orden de las franjas era código, así que en
 * `/creadores` las preguntas salían **después** del formulario —o sea, la
 * sección que quita objeciones detrás del punto de conversión— y para arreglarlo
 * había que desplegar.
 *
 * ### La forma nueva
 *
 * `página → secciones → bloques`. Una **sección** es una franja de la página:
 * tiene su ancla, su encabezado, su orden, su visibilidad y su forma de pintar
 * (`layout`). Un **bloque** es un elemento dentro de ella.
 *
 * El `kind` del bloque **desaparece**, y esto es lo importante: si la sección
 * dice cómo se pinta y el bloque también, son dos fuentes para la misma verdad y
 * un día se contradicen. La forma la decide la sección, y un bloque es un
 * bloque.
 *
 * Igual desaparece `landing_blocks.landing_page_id`: la página se sabe subiendo
 * por la sección. Dejarlo era guardar dos veces el mismo hecho, que es la manera
 * exacta en que una fila acaba colgando de una página y de una sección de otra.
 *
 * ### Lo que la migración NO inventa
 *
 * El relleno de datos crea las secciones que hoy existen **de hecho** y les pone
 * los encabezados que hoy están escritos en la plantilla. Ni una palabra nueva:
 * esta migración *muda* texto de un `.blade.php` a la base, no escribe copy. El
 * copy nuevo es de la L-4, y lo escribe quien administra.
 *
 * ### Lo que crece en el bloque
 *
 * `icon`, `image_file_id`, `cta_label` y `cta_url`. La auditoría lo dejó dicho:
 * sin esto el esquema **no puede expresar** una portada con fotografía y con un
 * botón por sección, y la alternativa sería volver a escribirlo en la plantilla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landing_page_id');
            // El ancla. Va en la URL --`/#como-funciona`-- asi que es minusculas
            // y guiones, y lo comprueba la base: un ancla con una mayuscula o un
            // espacio es un enlace del menu que no lleva a ninguna parte, y eso
            // no da ningun error, simplemente no pasa nada al pulsarlo.
            $table->string('code', 40);
            // Como se pinta. Es un enum de CODIGO y no un catalogo (`DEC-026`):
            // cada valor tiene su parcial, asi que uno inventado desde el panel
            // seria una fila valida que ninguna plantilla sabe dibujar.
            $table->string('layout', 20)->default('cards');
            // El sobretitulo, el encabezado y la bajada de la franja. Los tres
            // opcionales: la franja de ventajas de hoy no lleva ninguno.
            $table->string('eyebrow', 60)->nullable();
            $table->string('title', 120)->nullable();
            $table->string('subtitle', 320)->nullable();
            // El CTA intermedio (`C-6`): quien se convence en el minuto uno no
            // tiene que seguir bajando hasta el final para poder escribir.
            $table->string('cta_label', 60)->nullable();
            $table->string('cta_url', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('is_visible')->default(true);
            // Si sale en el menu de la cabecera. Aparte de `is_visible` porque
            // son dos decisiones distintas: una franja puede estar publicada y
            // no merecer un sitio en un menu que tiene cuatro huecos.
            $table->boolean('show_in_nav')->default(false);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['landing_page_id', 'code'], 'uq_ls_code');
            $table->index(['landing_page_id', 'is_visible', 'sort_order'], 'ix_ls_pagina');

            $table->foreign('landing_page_id', 'fk_ls_page')
                ->references('id')->on('landing_pages')->restrictOnDelete();
        });

        Schema::table('landing_blocks', function (Blueprint $table): void {
            $table->unsignedBigInteger('landing_section_id')->nullable()->after('id');
            // El icono, por NOMBRE y no por archivo: es un SVG en linea de un
            // catalogo cerrado. Un nombre desconocido no rompe nada --se pinta
            // el generico-- que es la misma regla que las redes del pie.
            $table->string('icon', 40)->nullable()->after('body');
            $table->unsignedBigInteger('image_file_id')->nullable()->after('icon');
            $table->string('cta_label', 60)->nullable()->after('image_file_id');
            $table->string('cta_url', 255)->nullable()->after('cta_label');
        });

        self::rellenar();

        // Ahora que todo bloque tiene seccion, se exige. Si algo quedara sin
        // rellenar, esto REVIENTA la migracion --y es lo que se quiere--: un
        // bloque huerfano es texto que no se pinta en ninguna parte y que nadie
        // va a echar de menos hasta que falte en la calle.
        DB::statement('ALTER TABLE `landing_blocks` '
            .'MODIFY COLUMN `landing_section_id` BIGINT UNSIGNED NOT NULL');

        Restriccion::quitar('landing_blocks', 'ck_lb_kind');

        Schema::table('landing_blocks', function (Blueprint $table): void {
            $table->dropForeign('fk_lb_page');
            $table->dropIndex('ix_lb_pagina');
            $table->dropColumn('landing_page_id');
            $table->dropColumn('kind');

            $table->index(['landing_section_id', 'is_visible', 'sort_order'], 'ix_lb_seccion');

            $table->foreign('landing_section_id', 'fk_lb_section')
                ->references('id')->on('landing_sections')->restrictOnDelete();
            $table->foreign('image_file_id', 'fk_lb_image')
                ->references('id')->on('files')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::table('landing_blocks', function (Blueprint $table): void {
            $table->dropForeign('fk_lb_section');
            $table->dropForeign('fk_lb_image');
            $table->dropIndex('ix_lb_seccion');
            $table->unsignedBigInteger('landing_page_id')->nullable();
            $table->string('kind', 20)->default('feature');
            $table->dropColumn(['landing_section_id', 'icon', 'image_file_id', 'cta_label', 'cta_url']);
        });

        // Se devuelve TODO lo que se quito, no solo las columnas: la foranea, el
        // indice y la comprobacion de `kind`. Sin esto, un `migrate:rollback`
        // seguido de un `migrate` revienta al intentar quitar una foranea que ya
        // no existe --y el sintoma seria un error de MySQL sobre un indice, no
        // sobre esta migracion--.
        //
        // Lo que NO se devuelve son los DATOS: la pagina de cada bloque y su
        // `kind` se pierden al deshacer. Se dice aqui en vez de fingir que un
        // `down()` es un viaje en el tiempo.
        Schema::table('landing_blocks', function (Blueprint $table): void {
            $table->index(['landing_page_id', 'is_visible', 'sort_order'], 'ix_lb_pagina');
            $table->foreign('landing_page_id', 'fk_lb_page')
                ->references('id')->on('landing_pages')->restrictOnDelete();
        });

        Restriccion::comprobacion(
            tabla: 'landing_blocks', nombre: 'ck_lb_kind',
            expresion: "kind IN ('feature','step','faq')",
            columnas: ['kind'], mensaje: 'Tipo de bloque no valido.',
        );

        Schema::dropIfExists('landing_sections');
    }

    // ------------------------------------------------------------- el relleno

    /**
     * Las secciones que hoy existen de hecho, con el texto que hoy está en el
     * Blade.
     *
     * Va en SQL y no recorriendo filas en PHP a propósito: son tres sentencias
     * que el motor resuelve enteras, sin cargar nada en memoria y sin depender
     * de que el ORM esté vivo —esta migración también la lee el recolector de
     * esquema, que no levanta Laravel—.
     */
    private static function rellenar(): void
    {
        foreach (self::mudanza() as [$kind, $code, $layout, $titulo, $orden, $enMenu]) {
            DB::statement(sprintf(
                'INSERT INTO `landing_sections` '
                .'(`landing_page_id`,`code`,`layout`,`title`,`sort_order`,`is_visible`,'
                .'`show_in_nav`,`created_at`,`updated_at`) '
                .'SELECT DISTINCT `landing_page_id`, ?, ?, %s, ?, 1, ?, NOW(3), NOW(3) '
                .'FROM `landing_blocks` WHERE `kind` = ?',
                $titulo === null ? 'NULL' : '?',
            ), $titulo === null
                ? [$code, $layout, $orden, $enMenu, $kind]
                : [$code, $layout, $titulo, $orden, $enMenu, $kind]);

            DB::statement(
                'UPDATE `landing_blocks` `lb` '
                .'JOIN `landing_sections` `ls` ON `ls`.`landing_page_id` = `lb`.`landing_page_id` '
                .'AND `ls`.`code` = ? '
                .'SET `lb`.`landing_section_id` = `ls`.`id` WHERE `lb`.`kind` = ?',
                [$code, $kind],
            );
        }
    }

    /** @return list<array{0:string,1:string,2:string,3:?string,4:int,5:int}> */
    private static function mudanza(): array
    {
        return [
            // Las ventajas no llevaban encabezado en la plantilla, asi que aqui
            // tampoco: inventarle uno seria escribir copy en una migracion.
            ['feature', 'ventajas', 'cards', null, 10, 0],
            ['step', 'como-funciona', 'steps', 'Cómo funciona', 20, 1],
            ['faq', 'preguntas', 'faq', 'Preguntas', 30, 1],
        ];
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // `COLLATE utf8mb4_bin` y no a secas. Se aprendio en `L-2a`: el
            // cotejo por defecto es CASE-INSENSITIVE, asi que `^[a-z0-9-]+$`
            // acepta alegremente `Como-Funciona`. Lo caza la prueba SQL, nunca
            // una prueba de PHP.
            ['landing_sections', 'ck_ls_code',
                "code COLLATE utf8mb4_bin REGEXP '^[a-z0-9][a-z0-9-]*$'",
                ['code'], 'El ancla de la seccion va en minusculas, sin espacios ni acentos.'],
            ['landing_sections', 'ck_ls_layout',
                "layout IN ('cards','steps','faq','claim','plain')",
                ['layout'], 'Esa forma de pintar la seccion no existe.'],
            // Una entrada de menu sin nombre es un enlace en blanco.
            ['landing_sections', 'ck_ls_menu',
                'show_in_nav = 0 OR (title IS NOT NULL AND CHAR_LENGTH(TRIM(title)) >= 2)',
                ['show_in_nav', 'title'], 'Para salir en el menu, la seccion necesita encabezado.'],
            // Vacio = el formulario de la propia pagina, como en `landing_pages`.
            ['landing_sections', 'ck_ls_url',
                "cta_url IS NULL OR cta_url LIKE 'https://%' OR cta_url LIKE '/%' OR cta_url LIKE '#%'",
                ['cta_url'], 'El enlace va con https, es una ruta propia o es un ancla.'],
            ['landing_sections', 'ck_ls_cta',
                'cta_url IS NULL OR (cta_label IS NOT NULL AND CHAR_LENGTH(TRIM(cta_label)) >= 2)',
                ['cta_url', 'cta_label'], 'Un boton sin texto no se puede pulsar: ponle rotulo.'],
            ['landing_blocks', 'ck_lb_icono',
                "icon IS NULL OR icon COLLATE utf8mb4_bin REGEXP '^[a-z0-9][a-z0-9-]*$'",
                ['icon'], 'El nombre del icono va en minusculas y sin espacios.'],
            ['landing_blocks', 'ck_lb_url',
                "cta_url IS NULL OR cta_url LIKE 'https://%' OR cta_url LIKE '/%' OR cta_url LIKE '#%'",
                ['cta_url'], 'El enlace va con https, es una ruta propia o es un ancla.'],
            ['landing_blocks', 'ck_lb_cta',
                'cta_url IS NULL OR (cta_label IS NOT NULL AND CHAR_LENGTH(TRIM(cta_label)) >= 2)',
                ['cta_url', 'cta_label'], 'Un boton sin texto no se puede pulsar: ponle rotulo.'],
        ];
    }
};
