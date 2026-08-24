<?php

declare(strict_types=1);

use App\Shared\Database\Periodo;
use Illuminate\Database\Migrations\Migration;

/**
 * Una sola sociedad cubre un país en una fecha (iteración 3.10).
 *
 * De las tres tablas que arregla esta iteración, ésta es la más cara si falla.
 *
 * `uq_lec_country` ya impedía que hubiera **dos vigentes a la vez**, y el
 * comentario del esquema explica por qué: «sin esto el resolver tendría empate,
 * y 2.2 ya decidió que los empates se rechazan al guardar, no al facturar».
 * Pero el resolver no elige solo por país: elige por país **y por fecha**. Dos
 * filas cerradas con periodos solapados producen exactamente el empate que esa
 * clave existía para evitar, solo que para una fecha pasada — y una factura
 * emitida por la sociedad equivocada no se corrige con un `UPDATE`.
 *
 * Sin filtro de estado: la tabla no tiene `status`, toda fila ocupa periodo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Periodo::exigirSinSolapePrevio(
            tabla: 'legal_entity_countries',
            serie: ['country_id'],
            queSignifica: 'Cada uno significa que para alguna fecha hay DOS sociedades cubriendo '
                .'el mismo pais, y el resolver de facturacion elige por pais y fecha: ese empate '
                .'es una factura emitida por la sociedad equivocada.',
            comoSeArregla: 'Cierre la cobertura anterior el dia ANTES de que empiece la '
                .'siguiente: `valid_to` es inclusivo.',
        );

        Periodo::sinSolape(
            tabla: 'legal_entity_countries',
            nombre: 'lec_sin_solape',
            serie: ['country_id'],
            mensaje: 'Ya hay una sociedad cubriendo ese pais en esas fechas: cierre la anterior el dia antes.',
        );
    }

    public function down(): void
    {
        Periodo::quitar('legal_entity_countries', 'lec_sin_solape');
    }
};
