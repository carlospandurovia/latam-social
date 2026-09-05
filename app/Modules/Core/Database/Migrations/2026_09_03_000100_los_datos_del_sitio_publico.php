<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los datos que se pintan en la calle (L-2a).
 *
 * ### De dónde sale
 *
 * De una frase del negocio que no admite lectura a medias:
 *
 * > *«todo lo que me pidas debe ser configurable desde el admin»*
 *
 * La auditoría de la landing terminaba pidiéndole siete datos —el WhatsApp, el
 * correo público, las redes, la dirección—. La respuesta correcta no era que los
 * escribiera en el chat para que yo los pusiera en una plantilla: era **construir
 * el sitio donde los pone él**. Es `DEC-190` otra vez, y esta vez llegó como
 * corrección.
 *
 * ### Por qué una tabla propia y no más columnas en `platform_brands`
 *
 * `platform_brands` es **identidad**: cómo nos llamamos, de qué color somos, qué
 * letra usamos. Esto es otra cosa: **cómo nos contactan y qué se enseña en la
 * calle**. Meterlo ahí dejaría una tabla de treinta columnas donde conviven el
 * favicon y el teléfono, y a la tercera ampliación nadie sabría qué es identidad
 * y qué es marketing.
 *
 * Mismo reparto que `mail_settings` colgando de `integration_connections` en
 * `9.17g`: una tabla por pregunta.
 *
 * ### Las redes son FILAS, no columnas
 *
 * Seis columnas `instagram_url`, `tiktok_url`, `linkedin_url`… es la forma
 * obvia y es la equivocada: el día que exista una red nueva —o que el negocio
 * quiera quitar una— haría falta **una migración y un despliegue** para algo que
 * es puro contenido. `DEC-190` dice que el código pone la REGLA y la
 * configuración pone el VALOR; «qué redes tenemos» es valor.
 *
 * El código de red es texto libre a propósito. La plantilla dibuja el icono que
 * conozca y, si no lo conoce, uno genérico de enlace: una red nueva funciona el
 * mismo día, sin icono roto y sin despliegue.
 *
 * ### La sociedad operadora
 *
 * `operator_legal_entity_id` es el dato del que salen la razón social, el RUC y
 * el domicilio **de los textos legales** (`L-2c`). No se deduce de «la primera
 * sociedad» ni de «la que tenga más facturas»: se declara. Una política de
 * privacidad que nombra a la sociedad equivocada es un documento sin valor, y
 * adivinarlo es exactamente cómo se llega ahí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table): void {
            $table->id();
            // Una fila por marca. `unique` y no `primary` sobre la ajena para
            // que la tabla tenga id propio como todas las demas.
            $table->foreignId('platform_brand_id');

            // De donde salen razon social, RUC y domicilio en los textos
            // legales. NULL mientras nadie lo declare: no bloquea nada, y el
            // area de configuracion lo pide en rojo (`DEC-190`).
            $table->unsignedBigInteger('operator_legal_entity_id')->nullable();

            // --- WhatsApp -------------------------------------------------
            // En E.164 y no «como se escriba»: este numero se pega dentro de una
            // URL `https://wa.me/…`, y un espacio o un parentesis la rompen sin
            // dar ningun error --el enlace simplemente no abre nada--.
            $table->string('whatsapp_phone', 20)->nullable();
            // El mensaje con el que se abre la conversacion. Se configura porque
            // es COPY, y el copy no se despliega.
            $table->string('whatsapp_message', 300)->nullable();

            // --- Contacto publico ----------------------------------------
            // A proposito distintos de los de `platform_brands`: `support_email`
            // es a donde escribe QUIEN YA ES CLIENTE. Esto es lo que se pinta en
            // la calle, y puede --y suele-- ser otro buzon.
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 30)->nullable();
            // La direccion que se ENSEÑA. No tiene por que ser el domicilio
            // fiscal: el fiscal va en la factura y sale de `legal_entities`.
            $table->string('public_address', 255)->nullable();

            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique('platform_brand_id', 'uq_ss_marca');
            $table->index('operator_legal_entity_id', 'ix_ss_operadora');

            $table->foreign('platform_brand_id', 'fk_ss_brand')
                ->references('id')->on('platform_brands')->restrictOnDelete();
            $table->foreign('operator_legal_entity_id', 'fk_ss_operadora')
                ->references('id')->on('legal_entities')->restrictOnDelete();
        });

        Schema::create('social_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_brand_id');
            // Texto libre, en minusculas. `instagram`, `tiktok`, `threads`…
            $table->string('network', 30);
            // Como se llama en pantalla y en el `aria-label`. Sin esto, un lector
            // de pantalla lee la URL entera.
            $table->string('label', 60);
            $table->string('url', 255);
            $table->unsignedSmallInteger('sort_order')->default(100);
            // Guardada y apagada: la cuenta existe pero todavia no se ensena.
            $table->boolean('is_visible')->default(true);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            // La misma red dos veces son dos iconos iguales en el pie, y nadie
            // sabria cual es el bueno.
            $table->unique(['platform_brand_id', 'network'], 'uq_sl_red');
            $table->index(['platform_brand_id', 'is_visible', 'sort_order'], 'ix_sl_marca');

            $table->foreign('platform_brand_id', 'fk_sl_brand')
                ->references('id')->on('platform_brands')->restrictOnDelete();
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

        Schema::dropIfExists('social_links');
        Schema::dropIfExists('site_settings');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // E.164: un `+` y de 8 a 15 digitos. Sin espacios, sin guiones y sin
            // parentesis, porque esto viaja DENTRO de una URL.
            ['site_settings', 'ck_ss_whatsapp',
                "whatsapp_phone IS NULL OR whatsapp_phone REGEXP '^\\\\+[0-9]{8,15}$'",
                ['whatsapp_phone'],
                'El WhatsApp va en formato internacional, sin espacios: +51987654321.'],

            ['site_settings', 'ck_ss_correo',
                "contact_email IS NULL OR contact_email LIKE '%_@_%.__%'",
                ['contact_email'], 'Ese correo de contacto no tiene forma de correo.'],

            // Un texto de WhatsApp de dos letras no es un mensaje: es un campo a
            // medio rellenar que llega al telefono de un cliente.
            ['site_settings', 'ck_ss_mensaje',
                'whatsapp_message IS NULL OR CHAR_LENGTH(TRIM(whatsapp_message)) >= 10',
                ['whatsapp_message'], 'El mensaje de WhatsApp tiene que decir algo: al menos diez caracteres.'],

            // Un `http://` en el pie de la portada es la misma advertencia de
            // `9.17e` en otro sitio: enlace publico, cifrado o nada.
            ['social_links', 'ck_sl_url', "url LIKE 'https://%'",
                ['url'], 'El enlace de una red social tiene que ser https://.'],

            // Minusculas y sin espacios: de este codigo sale el nombre del icono.
            //
            // El `COLLATE utf8mb4_bin` NO sobra, y lo descubrio la suite de SQL
            // al primer intento: `REGEXP` compara con la colacion de la columna,
            // que es `utf8mb4_unicode_ci`, y una colacion `_ci` es INSENSIBLE A
            // MAYUSCULAS. Sin el, `'TikTok'` casaba contra `^[a-z0-9_-]+$` y la
            // regla decia una cosa y hacia otra. Ninguna prueba de PHP lo habria
            // visto: el defecto estaba en la base, no en el codigo.
            ['social_links', 'ck_sl_red', "network COLLATE utf8mb4_bin REGEXP '^[a-z0-9_-]{2,30}$'",
                ['network'], 'El codigo de la red va en minusculas, sin espacios: instagram, tiktok, linkedin.'],

            ['social_links', 'ck_sl_etiqueta', 'CHAR_LENGTH(TRIM(label)) >= 2',
                ['label'], 'La red necesita un nombre para pintarlo y para leerlo en voz alta.'],
        ];
    }
};
