<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El degradado de marca, de verdad (L-1).
 *
 * ### El hallazgo
 *
 * Existe un sistema de diseño aprobado —`design/tokens.css` y
 * `docs/14-BRAND-AND-DESIGN-SYSTEM.md`— con una regla escrita:
 *
 * > *El naranja y el magenta de marca existen SOLO dentro del degradado. En
 * > interfaz, el único color de marca plano es el morado.*
 *
 * Y el degradado canónico es `#FF7447 → #D73382 → #6635D8` **a 45°**, naranja
 * abajo-izquierda, morado arriba-derecha.
 *
 * **Lo que estaba publicado no era eso.** La instalación arrancaba con
 * `#7C3AED` y `#22D3EE`, que son **violeta 600 y cian 400 de Tailwind**: el
 * degradado por defecto del framework. Ninguno de los dos es un color de LATAM
 * Social. Cualquiera que haya visto el manual lo nota en dos segundos.
 *
 * ### Por qué faltaba una columna, y sólo una
 *
 * El degradado de marca tiene **tres paradas** y el esquema sólo guardaba dos
 * colores. Pintarlo con dos se salta el magenta, que es el tercio central.
 *
 * La tercera no hace falta inventarla: **el degradado termina en el morado, que
 * es el mismo color plano de la marca**. Así que el reparto queda:
 *
 * | Columna | Papel |
 * |---|---|
 * | `gradient_from` (nueva) | El naranja. Sólo vive en el degradado |
 * | `secondary_color` | El magenta. La parada de en medio |
 * | `primary_color` | El morado. Final del degradado **y** color plano de la interfaz |
 *
 * Una columna nueva en vez de tres, y los nombres siguen diciendo la verdad.
 *
 * ### El ángulo también es un dato
 *
 * Estaba escrito `135deg` en la plantilla, y el canónico es `45deg`. Un ángulo
 * en un `.blade.php` es lo mismo que un titular en un `.blade.php` (`DEC-190`),
 * sólo que además contradice al manual de marca.
 *
 * ### Y la corrección NO pisa lo que alguien haya decidido
 *
 * Los valores sólo se corrigen **si siguen siendo exactamente el par de
 * fábrica** `#7C3AED` / `#22D3EE`. Quien haya puesto sus colores —esto es white
 * label— se los queda. Es `sembrarSiFalta` de `T-77` aplicado a una corrección:
 * arreglar un valor de fábrica no puede deshacer el trabajo de nadie.
 */
return new class extends Migration
{
    /** El par de fabrica que hay que corregir, y solo ese. */
    private const DE_FABRICA = ['primary' => '#7C3AED', 'secondary' => '#22D3EE'];

    /** Lo aprobado en `docs/14`. */
    private const APROBADO = [
        'gradient_from' => '#FF7447',
        'secondary_color' => '#D73382',
        'primary_color' => '#6635D8',
        'sidebar_color' => '#070A2B',
        'gradient_angle' => 45,
        'font_family' => 'Plus Jakarta Sans',
        'display_font_family' => 'Sora',
    ];

    public function up(): void
    {
        Schema::table('platform_brands', function (Blueprint $table): void {
            // La primera parada del degradado. NULL = degradado de dos colores,
            // que es lo que habia hasta hoy y sigue siendo legitimo para una
            // marca blanca que no tenga tres.
            $table->char('gradient_from', 7)->nullable()->after('secondary_color');
            // 45 es el canonico de `docs/14 §6`. Estaba escrito `135` en la
            // plantilla, que es un valor de marca en un archivo de codigo.
            $table->unsignedSmallInteger('gradient_angle')->default(45)->after('gradient_from');
            // `docs/14 §5`: la tipografia de TITULARES no es la de la interfaz.
            // Una sola familia no puede expresar eso, y las landings son
            // justamente donde se nota.
            $table->string('display_font_family', 80)->nullable()->after('font_family');
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        self::corregirLoDeFabrica();
    }

    public function down(): void
    {
        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::table('platform_brands', function (Blueprint $table): void {
            $table->dropColumn(['gradient_from', 'gradient_angle', 'display_font_family']);
        });
    }

    /**
     * Pone los colores aprobados **sólo donde siguen los de fábrica**.
     *
     * La condición es el par completo y exacto. Si alguien cambió uno de los
     * dos, ya decidió algo y esto no lo toca.
     */
    private static function corregirLoDeFabrica(): void
    {
        if (!Schema::hasTable('platform_brands')) {
            return;
        }

        // `date()` y no `now()`: el recolector de `tools/recolectar-esquema.php`
        // ejecuta los `up()` FUERA de Laravel, con `Schema` y `DB` de mentira, y
        // ahi el ayudante `now()` no existe. Se vio al correr
        // `verificar-migraciones.py`, no al migrar --que funcionaba--: una
        // herramienta del proyecto se caia por una linea que en produccion
        // habria pasado siempre.
        DB::table('platform_brands')
            ->where('primary_color', self::DE_FABRICA['primary'])
            ->where('secondary_color', self::DE_FABRICA['secondary'])
            ->update(self::APROBADO + ['updated_at' => date('Y-m-d H:i:s')]);
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['platform_brands', 'ck_pb_degradado',
                "gradient_from IS NULL OR gradient_from REGEXP '^#[0-9A-Fa-f]{6}$'",
                ['gradient_from'], 'El color del degradado va en #RRGGBB.'],

            // Un angulo es 0-359. 360 y 45 pintan lo mismo, pero un campo que
            // admite 3600 admite tambien que alguien tecleo el ano.
            ['platform_brands', 'ck_pb_angulo', 'gradient_angle < 360',
                ['gradient_angle'], 'El angulo del degradado va entre 0 y 359 grados.'],

            // La misma regla que `ck_pb_tipografia`, y por el mismo motivo: esto
            // se convierte en una URL y en una regla CSS, asi que una comilla o
            // un `;` escriben CSS ajeno en TODAS las pantallas. Es una
            // inyeccion, no una errata.
            ['platform_brands', 'ck_pb_tipografia_titulos',
                "display_font_family IS NULL OR display_font_family REGEXP '^[A-Za-z0-9 ]{2,80}$'",
                ['display_font_family'], 'La tipografia de titulares solo admite letras, numeros y espacios.'],
        ];
    }
};
