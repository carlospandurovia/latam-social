<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuál de los impuestos de un país va en una factura de venta (9.9b).
 *
 * ### El «IGV» que quedó escrito en el código
 *
 * `9.9a` dejó las tasas en una tabla, con vigencia y por país. Pero para
 * calcular una factura hay que preguntar por un código, y ese código estaba a
 * punto de escribirse en el servicio de facturación: `Impuestos::IGV`. Sería
 * `DEC-190` roto en el sitio más caro del sistema — «IGV» es peruano; en
 * Colombia es «IVA», en España «VAT», y en México el IVA convive con el IEPS.
 *
 * ### El código pone la regla; el valor, la configuración
 *
 * La regla es: **una factura de venta lleva el impuesto general de venta del país
 * que emite**. Cuál es ese impuesto no lo decide el código: lo dice
 * `countries.sales_tax_code`, que apunta al `code` de una fila de `tax_rates`.
 *
 * No hay foránea a `tax_rates` a propósito: la tasa cambia de fila cada vez que
 * cambia el porcentaje, y una foránea ataría el país a la fila de 2026 en vez de
 * a **el impuesto**. Lo que se guarda es el nombre corto del tributo, que es lo
 * que no cambia.
 *
 * ### Y no bloquea
 *
 * `NULL` es un estado normal: es el de los cinco países donde todavía no se
 * emite nada. Lo que hace es salir en el panel — rojo si allí hay una sociedad
 * activa, porque el día que se emita la factura saldría sin impuesto sin que
 * nadie lo hubiera decidido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->string('sales_tax_code', 20)->nullable()->after('requires_tax_location');
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

        Schema::table('countries', function (Blueprint $table): void {
            $table->dropColumn('sales_tax_code');
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // La misma forma que `ck_tax_code`, y por el mismo motivo: este
            // valor se compara contra `tax_rates.code`, y dos escrituras del
            // mismo tributo --«IGV» e «igv»-- serian dos impuestos distintos
            // para la consulta y uno solo para quien lo teclea.
            ['countries', 'ck_countries_sales_tax',
                "sales_tax_code IS NULL OR (sales_tax_code REGEXP '^[A-Z][A-Z0-9_]{1,19}$'"
                .' AND sales_tax_code COLLATE utf8mb4_bin = UPPER(sales_tax_code))',
                ['sales_tax_code'],
                'El codigo del impuesto de venta va en mayusculas, sin espacios.'],
        ];
    }
};
