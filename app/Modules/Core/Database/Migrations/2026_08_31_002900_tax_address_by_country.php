<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El domicilio fiscal, con la forma que exige cada país (9.17c).
 *
 * ### Qué falta y para qué
 *
 * El comprobante electrónico peruano lleva, en el emisor, **el ubigeo**: el
 * código de seis dígitos del INEI que identifica departamento, provincia y
 * distrito. `legal_entities` tenía dirección, ciudad y región, y **no tenía ni
 * el distrito ni el código**. Sin eso no se emite una factura en Perú.
 *
 * Además lleva el **código de establecimiento anexo** —«0000» para el domicilio
 * fiscal, otro para cada local declarado ante SUNAT—, que tampoco estaba.
 *
 * ### Y por qué no se llama `ubigeo`
 *
 * Porque «ubigeo» es peruano y esto es una plataforma para seis países
 * (`DEC-190`). Colombia usa el código DANE, de cinco dígitos; México no tiene
 * equivalente y usa el código postal. Una columna llamada `ubigeo` con un CHECK
 * de seis dígitos sería la regla de un país escrita en el código de todos, que
 * es exactamente lo que este proyecto dejó de hacer en `9.16`.
 *
 * Así que la columna se llama `tax_location_code` y **la forma la declara el
 * país**: `countries.tax_location_label` dice cómo se llama en ese país —«Ubigeo»,
 * «Código DANE»— y `countries.tax_location_pattern` dice qué forma tiene. El
 * formulario pide «Ubigeo» a una sociedad peruana y «Código DANE» a una
 * colombiana **sin una sola línea de código por país**.
 *
 * El código aporta la regla —*valida contra el patrón del país*—; el valor
 * —cuál es ese patrón— sale de la configuración. Es `DEC-190` aplicado a un
 * sitio donde la tentación de quemar «Perú» era enorme.
 *
 * ### La regla la impone el motor, y es cruzada
 *
 * `tg_le_localidad` lee el patrón de `countries` y lo aplica a la fila de
 * `legal_entities`. Es cruzada, así que no puede ser un CHECK: va en un
 * disparador, como el resto de las reglas entre tablas del modelo.
 *
 * ### Nada de esto bloquea
 *
 * `requires_tax_location` dice si el país lo exige de verdad. **No impide
 * guardar la sociedad**: hace que el panel de configuración lo enseñe en rojo
 * (`9.17b`). Una sociedad a medias se guarda y se completa; lo que no puede
 * pasar es que nadie sepa que está a medias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->string('tax_location_label', 40)->nullable()->after('timezone');
            $table->string('tax_location_pattern', 80)->nullable()->after('tax_location_label');
            $table->boolean('requires_tax_location')->default(false)->after('tax_location_pattern');
        });

        Schema::table('legal_entities', function (Blueprint $table): void {
            $table->string('district', 100)->nullable()->after('city');
            $table->string('tax_location_code', 12)->nullable()->after('postal_code');
            // «0000» es el domicilio fiscal en SUNAT y el valor con el que sale
            // cualquier sociedad que no haya declarado locales anexos. Va con
            // valor por defecto y no nulo: un comprobante SIEMPRE lleva uno, y
            // dejarlo nulo obligaria a decidirlo al emitir, que es tarde.
            $table->string('establishment_code', 10)->default('0000')->after('tax_location_code');
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // La forma del codigo la declara el PAIS, y comprobarla es leer otra
        // tabla: eso no cabe en un CHECK. El patron se lee de `countries` y se
        // aplica aqui.
        //
        // Si el pais no declara patron, no se comprueba nada: un pais sin
        // configurar no puede impedir dar de alta una sociedad (`DEC-190`).
        DB::statement('DROP TRIGGER IF EXISTS `tg_le_localidad_ins`');
        DB::statement('DROP TRIGGER IF EXISTS `tg_le_localidad_upd`');

        foreach (['ins' => 'INSERT', 'upd' => 'UPDATE'] as $sufijo => $momento) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER `tg_le_localidad_{$sufijo}`
                BEFORE {$momento} ON `legal_entities`
                FOR EACH ROW
                BEGIN
                    -- CON COLACION EXPLICITA. Sin ella la variable toma la del
                    -- servidor --`utf8mb4_general_ci`-- y la columna tiene la de la
                    -- tabla --`utf8mb4_unicode_ci`--, y el `REGEXP` entre las dos da
                    -- «Illegal mix of collations»: un 1267 en CADA alta de sociedad
                    -- que traiga codigo. La suite lo encontro; la aplicacion no,
                    -- porque hasta hoy el campo siempre venia nulo y la rama no corria.
                    DECLARE v_patron VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

                    IF NEW.`tax_location_code` IS NOT NULL THEN
                        SELECT `tax_location_pattern` INTO v_patron
                          FROM `countries` WHERE `id` = NEW.`country_id`;

                        IF v_patron IS NOT NULL AND NEW.`tax_location_code` NOT REGEXP v_patron THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'El codigo de localidad no tiene la forma que exige ese pais.';
                        END IF;
                    END IF;
                END
                SQL);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_le_localidad_ins`');
        DB::statement('DROP TRIGGER IF EXISTS `tg_le_localidad_upd`');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::table('legal_entities', function (Blueprint $table): void {
            $table->dropColumn(['district', 'tax_location_code', 'establishment_code']);
        });

        Schema::table('countries', function (Blueprint $table): void {
            $table->dropColumn(['tax_location_label', 'tax_location_pattern', 'requires_tax_location']);
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Media configuracion es peor que ninguna: un patron sin etiqueta
            // deja al formulario pidiendo «codigo de localidad» sin decir cual,
            // y a quien lo rellena adivinando. Si se declara la forma, se
            // declara el nombre.
            ['countries', 'ck_countries_localidad',
                'tax_location_pattern IS NULL OR tax_location_label IS NOT NULL',
                ['tax_location_pattern', 'tax_location_label'],
                'Si el pais declara la forma del codigo, tiene que decir como se llama.'],

            // Y exigirlo sin decir que forma tiene es pedir algo que no se puede
            // comprobar: el aviso saldria en rojo para siempre.
            ['countries', 'ck_countries_localidad_exigida',
                'requires_tax_location = 0 OR tax_location_pattern IS NOT NULL',
                ['requires_tax_location', 'tax_location_pattern'],
                'Un pais que exige codigo de localidad tiene que decir que forma tiene.'],

            // La forma general, la que vale en todos los paises. La de CADA pais
            // la impone `tg_le_localidad_*` leyendo el patron de `countries`.
            ['legal_entities', 'ck_le_localidad',
                "tax_location_code IS NULL OR tax_location_code REGEXP '^[0-9A-Za-z]{2,12}$'",
                ['tax_location_code'], 'El codigo de localidad son letras y numeros, de 2 a 12.'],

            ['legal_entities', 'ck_le_establecimiento',
                "establishment_code REGEXP '^[0-9A-Za-z]{1,10}$'",
                ['establishment_code'], 'El codigo de establecimiento son letras y numeros.'],

            // Un distrito en blanco no es «sin distrito»: es un comprobante con
            // el campo vacio. Sin distrito se deja NULL, que si significa eso.
            ['legal_entities', 'ck_le_distrito', "district IS NULL OR TRIM(district) <> ''",
                ['district'], 'El distrito o se pone o se deja vacio, pero no en blanco.'],
        ];
    }
};
