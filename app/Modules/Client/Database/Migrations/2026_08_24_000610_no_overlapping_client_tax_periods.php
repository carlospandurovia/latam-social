<?php

declare(strict_types=1);

use App\Shared\Database\Periodo;
use Illuminate\Database\Migrations\Migration;

/**
 * El mismo agujero que `T-12`, del lado del cliente (iteración 3.10).
 *
 * `uq_ctxp_current` garantiza un solo perfil fiscal **vigente** por cliente y
 * país. No garantiza que el histórico tenga una sola respuesta para una fecha
 * pasada, y de ese dato salen el `tax_id_number` y la razón social con los que
 * se emitió la factura.
 *
 * Aquí no hay filtro de estado: la tabla no tiene `status`, toda fila que
 * existe ocupa periodo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Periodo::exigirSinSolapePrevio(
            tabla: 'client_tax_profiles',
            serie: ['client_organization_id', 'country_id'],
            queSignifica: 'Cada uno significa que para alguna fecha hay DOS identidades fiscales '
                .'del mismo cliente en el mismo pais, y de ahi salen el RUC y la razon social '
                .'con los que se emitio la factura.',
            comoSeArregla: 'Cierre el anterior el dia ANTES de que empiece el siguiente: '
                .'`valid_to` es inclusivo, asi que ponerle el mismo dia los deja solapados.',
        );

        Periodo::sinSolape(
            tabla: 'client_tax_profiles',
            nombre: 'ctxp_sin_solape',
            serie: ['client_organization_id', 'country_id'],
            mensaje: 'Ya hay un perfil fiscal de ese cliente para ese pais en esas fechas.',
        );
    }

    public function down(): void
    {
        Periodo::quitar('client_tax_profiles', 'ctxp_sin_solape');
    }
};
