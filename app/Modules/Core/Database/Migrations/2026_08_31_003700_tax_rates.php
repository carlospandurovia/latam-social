<?php

declare(strict_types=1);

use App\Shared\Database\Periodo;
use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El impuesto es un dato, no un 18 escrito en el código (9.9a).
 *
 * ### Qué falta hoy
 *
 * `invoices` existe desde la Fase 2 con `tax_amount`, con `tax_regime` y hasta
 * con la aritmética comprobada por la base (`ck_invoice_math`). Lo que **no
 * existe en ninguna parte del sistema es la TASA**: nadie sabe que el IGV es
 * 18 %, así que nadie puede calcular ese importe. Es el primer prerrequisito de
 * emitir una factura, y por eso va antes que la emisión.
 *
 * ### Por qué una tabla con vigencia y no una constante
 *
 * Dos motivos, y el segundo es el que decide:
 *
 * 1. **`DEC-190`.** Un 18 en el código es una regla de un país escrita en el
 *    código de todos, igual que la boleta en `9.12` o el ubigeo en `9.17c`.
 * 2. **Las tasas cambian, y las facturas de antes no.** El IGV peruano ha sido
 *    16, 17 y 19 % en distintos momentos. Sin vigencia, subir la tasa hoy
 *    reescribiría el impuesto de una factura de hace dos años la próxima vez que
 *    alguien la recalculara — y eso es exactamente la clase de cosa que
 *    encuentra una fiscalización.
 *
 * Por eso es un **periodo** en el sentido de `App\Shared\Database\Periodo`, con
 * la misma regla de no solape que las tarifas, la cobertura y los términos: para
 * una fecha dada hay **una sola** respuesta a «¿cuánto era el IGV?».
 *
 * ### Lo que NO se siembra
 *
 * Sólo el IGV de Perú, que es donde se factura. Sembrar el IVA colombiano o el
 * mexicano sería inventar el dato de un país en el que todavía no se emite nada
 * — el mismo criterio que `Q-64` con los tipos de cambio: declarar una tasa que
 * nadie ha confirmado es peor que no tener ninguna, porque se usa sin mirarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id');
            // `IGV` en Peru, `IVA` en Colombia, `VAT` en Espana. Es el nombre
            // corto que sale impreso en el comprobante.
            $table->string('code', 20);
            $table->string('name', 80);
            // 18.0000. Cuatro decimales porque hay paises con tasas que no son
            // enteras --y porque redondear la TASA es redondear cada factura--.
            $table->decimal('rate', 7, 4);
            // Que codigo le pone la administracion tributaria. En SUNAT el IGV
            // es `1000` en el catalogo 05; viaja en el XML y por eso vive aqui.
            $table->string('official_code', 10)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->index(['country_id', 'code', 'valid_from'], 'ix_tax_pais');

            $table->foreign('country_id', 'fk_tax_country')
                ->references('id')->on('countries')->restrictOnDelete();
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // Para una fecha dada, UNA sola respuesta a «.cuanto era el IGV?».
        //
        // Generado por la clase de siempre, no escrito a mano: la migracion y el
        // esquema de referencia salen del mismo sitio y no pueden divergir. Es
        // la novena vez que se usa esta regla y la primera que no hizo falta
        // descubrir el defecto para ponerla.
        Periodo::sinSolape(
            tabla: 'tax_rates',
            nombre: 'tax_sin_solape',
            serie: ['country_id', 'code'],
            mensaje: 'Ya hay una tasa de ese impuesto en esas fechas: cierre la anterior el dia antes.',
            desde: 'valid_from',
            hasta: 'valid_to',
        );

        // Una tasa cerrada explica el impuesto de las facturas de entonces.
        // Borrarla deja esas facturas sin poder explicar su propio importe.
        DB::statement('DROP TRIGGER IF EXISTS `tg_tax_no_delete`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_tax_no_delete`
            BEFORE DELETE ON `tax_rates`
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'Una tasa no se borra: cierrela, que explica el impuesto de lo ya emitido.';
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_tax_no_delete`');
        Periodo::quitar('tax_rates', 'tax_sin_solape');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::dropIfExists('tax_rates');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Una tasa del 100 % no es una tasa, y una negativa tampoco. El
            // limite superior es 100 y no 18: quien pone la tasa sabe de
            // impuestos mas que este `CHECK`.
            ['tax_rates', 'ck_tax_rate', 'rate >= 0 AND rate < 100',
                ['rate'], 'La tasa va entre 0 y 100.'],
            // Mayusculas y sin espacios, como sale impreso. La segunda mitad no
            // es adorno: con la colacion de la tabla el REGEXP no distingue
            // mayusculas --lo que costo descubrir en `9.12`--.
            ['tax_rates', 'ck_tax_code',
                "code REGEXP '^[A-Z][A-Z0-9_]{1,19}$' AND code COLLATE utf8mb4_bin = UPPER(code)",
                ['code'], 'El codigo del impuesto va en mayusculas, sin espacios.'],
            ['tax_rates', 'ck_tax_dates', 'valid_to IS NULL OR valid_to >= valid_from',
                ['valid_to', 'valid_from'], 'La vigencia no puede terminar antes de empezar.'],
            ['tax_rates', 'ck_tax_nombre', 'CHAR_LENGTH(TRIM(name)) >= 3',
                ['name'], 'El impuesto necesita un nombre que se pueda leer.'],
        ];
    }
};
