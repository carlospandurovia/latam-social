<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La puerta de la calle (9.21b).
 *
 * ### De dónde sale
 *
 * Hasta hoy `/` llevaba al panel: la única puerta del sistema era el acceso al
 * back-office. No había forma de enseñarle esto a nadie que no tuviera ya una
 * cuenta. `9.21a` mudó la trastienda a `/backoffice` para dejar la calle libre;
 * esto la ocupa.
 *
 * La decisión de a quién le habla la portada es del negocio y está tomada: **`/`
 * es de las marcas** —el lado que paga— y **`/creadores`** es la puerta de los
 * creadores, que es el enlace que se comparte en redes. Las dos se enlazan entre
 * sí y `/entrar` queda para quien ya tiene cuenta.
 *
 * ### Por qué el contenido es una TABLA y no una plantilla
 *
 * Porque esto es white label (`DEC-190`). Un titular escrito en un `.blade.php`
 * es «LATAM Social» escrito en tres plantillas otra vez —lo que `9.17` tuvo que
 * arreglar— pero peor: aquí lo ve quien todavía no es cliente.
 *
 * ### Y por qué cuelga de la marca
 *
 * `landing_pages` lleva `platform_brand_id`. En `9.17d` se decidió NO construir
 * la tabla de asignaciones de tres ejes porque resolvía un problema inexistente;
 * esto es distinto y conviene decir por qué: **es una sola columna sobre el eje
 * en el que el producto está explícitamente construido**. La marca de plataforma
 * ya existe, ya se resuelve (`Marca::actual()`) y el día que haya una segunda
 * instalación su portada no puede ser la misma. Una columna hoy o una migración
 * de datos mañana.
 *
 * ### Lo que NO se construye aquí
 *
 * El formulario de contacto de las **marcas** —que crea un prospecto— es `9.21c`.
 * El de creadores sí está, porque no necesita tabla nueva:
 * `creator_applications` existe desde la Fase 2 **con `source` por defecto
 * `'landing'`** y hasta hoy no había ninguna landing que escribiera en ella. Es
 * el mismo caso que `document_series` en `9.12`: la mesa puesta y sin puerta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_brand_id');
            // `marcas` o `creadores`. Es un enum de CODIGO y no un catalogo, por
            // el criterio de `DEC-026`: el codigo SE RAMIFICA por pagina --cada
            // una tiene su formulario y su publico-- asi que una tercera creada
            // desde el panel seria una fila perfectamente valida que ninguna
            // ruta sabe servir.
            $table->string('code', 20);
            $table->string('headline', 160);
            $table->string('subheadline', 320)->nullable();
            $table->string('cta_label', 60);
            // NULL = el formulario de la propia pagina. Con valor, se manda a
            // otro sitio --un calendario, un WhatsApp-- sin tocar codigo.
            $table->string('cta_url', 255)->nullable();
            $table->unsignedBigInteger('hero_image_file_id')->nullable();
            // Lo que sale en Google y al compartir el enlace. Sin esto la
            // portada se comparte con el titulo del navegador, que no vende.
            $table->string('meta_title', 70)->nullable();
            $table->string('meta_description', 180)->nullable();
            // Apagarla no da un 404: manda al acceso. Nada bloquea (`DEC-190`).
            $table->boolean('is_published')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['platform_brand_id', 'code'], 'uq_lp_code');

            $table->foreign('platform_brand_id', 'fk_lp_brand')
                ->references('id')->on('platform_brands')->restrictOnDelete();
            $table->foreign('hero_image_file_id', 'fk_lp_hero')
                ->references('id')->on('files')->restrictOnDelete();
        });

        // Los bloques de debajo del titular. Una tabla y no seis columnas
        // porque el numero de bloques es del contenido, no del programa: hoy
        // son tres, manana cinco, y eso no puede ser un despliegue.
        Schema::create('landing_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landing_page_id');
            // Como se pinta: `feature` una ventaja, `step` un paso del proceso,
            // `faq` una pregunta. Tres formas, no treinta: cada una es codigo.
            $table->string('kind', 20)->default('feature');
            $table->string('heading', 120);
            $table->string('body', 600)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(100);
            // Guardar un bloque sin ensenarlo: se prepara el texto de la
            // campana que viene y se enciende el dia que toca.
            $table->boolean('is_visible')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index(['landing_page_id', 'is_visible', 'sort_order'], 'ix_lb_pagina');

            $table->foreign('landing_page_id', 'fk_lb_page')
                ->references('id')->on('landing_pages')->restrictOnDelete();
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

        Schema::dropIfExists('landing_blocks');
        Schema::dropIfExists('landing_pages');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['landing_pages', 'ck_lp_code', "code IN ('marcas','creadores')",
                ['code'], 'Solo hay dos portadas: marcas y creadores.'],
            // Una portada publicada sin titular es una pagina en blanco de cara
            // a la calle, y eso es peor que no tenerla.
            ['landing_pages', 'ck_lp_titular', 'CHAR_LENGTH(TRIM(headline)) >= 10',
                ['headline'], 'El titular tiene que decir algo: al menos diez caracteres.'],
            ['landing_pages', 'ck_lp_boton', 'CHAR_LENGTH(TRIM(cta_label)) >= 2',
                ['cta_label'], 'El boton necesita un texto.'],
            // `https` o una ruta propia. Un `http://` en la portada es un aviso
            // del navegador delante de un cliente que todavia no lo es.
            ['landing_pages', 'ck_lp_url',
                "cta_url IS NULL OR cta_url LIKE 'https://%' OR cta_url LIKE '/%'",
                ['cta_url'], 'El enlace del boton va con https o es una ruta propia.'],
            ['landing_blocks', 'ck_lb_kind', "kind IN ('feature','step','faq')",
                ['kind'], 'Tipo de bloque no valido.'],
            ['landing_blocks', 'ck_lb_heading', 'CHAR_LENGTH(TRIM(heading)) >= 3',
                ['heading'], 'Un bloque sin titulo no se puede leer.'],
        ];
    }
};
