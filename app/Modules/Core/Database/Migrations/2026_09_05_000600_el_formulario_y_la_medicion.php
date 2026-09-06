<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El país por defecto y la medición, las dos configurables (L-5).
 *
 * ### `C-2`: el país por defecto era Chile
 *
 * Y nadie lo eligió: la lista sale ordenada por nombre y Chile es el primero por
 * orden alfabético. Un negocio que arranca en Perú **etiqueta mal sus propios
 * leads, en silencio, desde el primer día** —y el país de un lead no es un
 * adorno: decide el mercado, la moneda y qué comprobante se emite—.
 *
 * La tentación era escribir «Perú» en el código. Eso es `DEC-190` roto: el
 * sistema es white label y el segundo operador puede estar en Colombia. Así que
 * es un ajuste, con una regla de reserva que **no es una constante**: cuando no
 * se ha elegido ninguno, el país por defecto es **el de la sociedad operadora**,
 * que es un dato que ya existe y que ya está bien.
 *
 * ### §21: la medición, sin proveedor atado
 *
 * Los `data-evento` están puestos en el HTML desde la `L-3`. Lo que faltaba es
 * por dónde sale la medición, y **eso no puede ser un `<script>` escrito en una
 * plantilla**: cambiar de proveedor obligaría a desplegar, y el identificador de
 * la propiedad es de la empresa, no del programa.
 *
 * `analytics_provider` es un enum de **código** —cada uno tiene su fragmento, y
 * uno inventado desde el panel sería una fila válida que ninguna plantilla sabe
 * dibujar (`DEC-026`)— y `analytics_id` va comprobado contra letras, números,
 * guiones y puntos: **ese valor entra dentro de un `<script>`**, y ahí una
 * comilla es una inyección, no una errata.
 *
 * ### Lo que NO decide esta migración
 *
 * Si la medición se emite o no en esta máquina. Eso lo decide `Instalacion`
 * (`9.22a`): una copia del volcado de producción en un servidor de pruebas
 * traería dentro el identificador bueno y mandaría visitas falsas a la propiedad
 * de verdad. Es exactamente el agujero que `9.22b` cerró para el correo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_country_id')->nullable()->after('operator_legal_entity_id');
            $table->string('analytics_provider', 20)->nullable()->after('public_address');
            $table->string('analytics_id', 40)->nullable()->after('analytics_provider');

            $table->foreign('default_country_id', 'fk_ss_pais')
                ->references('id')->on('countries')->restrictOnDelete();
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

        Schema::table('site_settings', function (Blueprint $table): void {
            $table->dropForeign('fk_ss_pais');
            $table->dropColumn(['default_country_id', 'analytics_provider', 'analytics_id']);
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // Cada proveedor tiene su fragmento en `parciales/analitica`. Uno
            // inventado desde el panel seria una fila valida que nada sabe
            // dibujar, que es el criterio de `DEC-026`.
            ['site_settings', 'ck_ss_medidor',
                "analytics_provider IS NULL OR analytics_provider IN ('ga4','gtm','meta','plausible')",
                ['analytics_provider'], 'Ese medidor de visitas no existe.'],
            // ESTE VALOR ENTRA DENTRO DE UN <script>. Una comilla aqui no es una
            // errata: es una inyeccion en todas las paginas publicas. Se
            // comprueba en la base ademas de en el formulario porque una fila
            // puede entrar por otro camino --una importacion, una consola-- y
            // entonces el formulario no ha mirado nada.
            ['site_settings', 'ck_ss_medidor_id',
                "analytics_id IS NULL OR analytics_id COLLATE utf8mb4_bin REGEXP '^[A-Za-z0-9._-]+$'",
                ['analytics_id'], 'El identificador de medicion solo admite letras, numeros, punto y guion.'],
            // Un proveedor sin identificador no mide nada, y un identificador
            // sin proveedor no lo lee nadie: los dos casos son configuracion a
            // medias que PARECE configuracion completa.
            ['site_settings', 'ck_ss_medidor_par',
                '(analytics_provider IS NULL) = (analytics_id IS NULL)',
                ['analytics_provider', 'analytics_id'],
                'El medidor necesita proveedor e identificador: uno solo no mide nada.'],
        ];
    }
};
