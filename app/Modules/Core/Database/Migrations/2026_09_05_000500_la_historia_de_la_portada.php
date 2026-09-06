<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El cierre de la portada deja de repetir el botón (L-4).
 *
 * ### Lo que arregla
 *
 * `C-3` de la auditoría: **la misma frase tres veces**. El botón del héroe, el
 * título de la sección de cierre y el botón de enviar decían los tres «Quiero
 * lanzar una campaña», porque los tres leían `cta_label`. Lee como una plantilla
 * rellenada, no como una página escrita.
 *
 * El formulario es **código** —tiene su validación, su campo trampa y su
 * `throttle`, y eso no puede ser un dato— pero **sus palabras no lo son**. Dos
 * columnas: el encabezado del cierre y la frase que lo acompaña.
 *
 * ### Y el titular de fábrica
 *
 * `9.21b` sembró «Campañas con creadores, de principio a fin y con todo a la
 * vista». Es honesto y se entiende, pero habla de **proceso**, «de principio a
 * fin» lo dice cualquier agencia, y sobre todo **no dice «muchas»** —el modelo
 * entero, decenas de microcreadores coordinados, quedaba fuera del titular—.
 *
 * Se corrige **sólo si sigue siendo el de fábrica**, con el mismo criterio que
 * `L-1` usó con los colores: quien ya escribió su propio titular no lo pierde.
 * Un `UPDATE` a secas sobre una portada que alguien redactó sería la peor clase
 * de migración: la que borra trabajo ajeno sin preguntar.
 */
return new class extends Migration
{
    /** El titular sembrado por `9.21b`. Se compara entero, no por su principio. */
    private const TITULAR_DE_FABRICA = 'Campañas con creadores, de principio a fin y con todo a la vista';

    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->string('form_heading', 120)->nullable()->after('cta_url');
            $table->string('form_intro', 320)->nullable()->after('form_heading');
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        self::corregirElTitularDeFabrica();
    }

    public function down(): void
    {
        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->dropColumn(['form_heading', 'form_intro']);
        });
    }

    /**
     * El titular nuevo, y sólo donde nadie lo ha tocado.
     *
     * Va en SQL con un `WHERE` sobre el valor exacto —y no leyendo la fila en
     * PHP— por lo mismo que el relleno de la `L-3`: esta migración también la
     * lee el recolector de esquema, que no levanta Laravel.
     */
    private static function corregirElTitularDeFabrica(): void
    {
        DB::statement(
            'UPDATE `landing_pages` SET `headline` = ?, `subheadline` = ?, '
            .'`form_heading` = ?, `form_intro` = ?, `updated_at` = ? '
            ."WHERE `code` = 'marcas' AND `headline` = ?",
            [
                'Muchas voces. Una sola campaña.',
                'Activamos decenas de creadores reales en una campaña coordinada: elegimos, '
                    .'producimos, publicamos y te entregamos cada publicación con su evidencia. '
                    .'Tú hablas con una sola persona.',
                'Hablemos de tu próxima campaña.',
                'Cuéntanos qué tienes en mente y te respondemos con cómo sería: cuántos creadores, '
                    .'en qué fechas y qué costaría.',
                date('Y-m-d H:i:s'),
                self::TITULAR_DE_FABRICA,
            ],
        );
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Un encabezado de dos letras sobre el formulario es un hueco con
            // aspecto de titulo. Vacio si se admite: entonces no se pinta.
            ['landing_pages', 'ck_lp_form',
                'form_heading IS NULL OR CHAR_LENGTH(TRIM(form_heading)) >= 3',
                ['form_heading'], 'El encabezado del formulario tiene que decir algo.'],
        ];
    }
};
