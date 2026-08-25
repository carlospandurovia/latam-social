<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cero es un ingreso válido, pero hay que declararlo (7.2).
 *
 * ### El mismo cero, dos significados
 *
 * `revenue_amount` nace con `DEFAULT 0`, así que hoy una campaña sin precio y
 * una campaña **regalada** son indistinguibles. Y no son lo mismo:
 *
 * - **Canje, prueba o cortesía a un cliente.** Alguien decidió que esta campaña
 *   no se cobra. Es una decisión de negocio, y legítima.
 * - **Nadie puso el precio.** El formulario se guardó con el valor por omisión.
 *
 * Ante un margen descuadrado, la diferencia entre las dos es la diferencia
 * entre «esto salió como se planeó» y «esto se nos escapó». Un valor por
 * omisión que parece una respuesta, otra vez (`DEC-048`, y `DEC-068` en las
 * tarifas del creador, donde el mismo cero planteó el mismo problema).
 *
 * ### Por qué es un `CHECK` y no una validación de pantalla
 *
 * Porque la pregunta que hay que poder responder dentro de un año es *«¿esta
 * campaña de cero se regaló o se nos olvidó cobrarla?»*, y esa respuesta tiene
 * que estar en la fila. Una regla que sólo vive en el formulario se la salta
 * cualquier importación, cualquier `UPDATE` de mantenimiento y la próxima
 * pantalla que alguien escriba.
 *
 * `is_gratis` en `creator_rates` resolvió esto mismo y se llama igual a
 * propósito: es la misma pregunta sobre el otro lado del dinero.
 *
 * ### Y sólo se exige FUERA de borrador
 *
 * La primera versión de esta migración no lo hacía, y habría rechazado cualquier
 * campaña recién empezada: `revenue_amount` nace en 0 y `is_gratis` en 0, así
 * que el formulario vacío violaba la regla antes de que nadie pudiera teclear el
 * precio.
 *
 * Es la misma forma que `ck_camp_billing_entity` y `ck_camp_confirmed`: *o
 * estás todavía escribiendo la campaña, o el dato existe*. Un borrador tiene
 * derecho a estar a medias; lo que no puede es salir de ahí así.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->boolean('is_gratis')->default(false)->after('revenue_amount');
        });

        // `ck_camp_revenue` ya exigía `revenue_amount >= 0` y se queda: esta
        // regla es la otra mitad, no su sustituta.
        Restriccion::comprobacion(
            tabla: 'campaigns',
            nombre: 'ck_camp_revenue_declarado',
            expresion: "status IN ('draft', 'pending_approval', 'cancelled') "
                .'OR (is_gratis = 1 AND revenue_amount = 0) '
                .'OR (is_gratis = 0 AND revenue_amount > 0)',
            columnas: ['status', 'is_gratis', 'revenue_amount'],
            mensaje: 'Una campana que sale de borrador cobra mas de cero, o es cero y esta declarada como gratuita.',
        );
    }

    public function down(): void
    {
        Restriccion::quitar('campaigns', 'ck_camp_revenue_declarado');

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('is_gratis');
        });
    }
};
