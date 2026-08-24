<?php

declare(strict_types=1);

use App\Shared\Database\Periodo;
use Illuminate\Database\Migrations\Migration;

/**
 * Los términos tampoco se solapan (iteración 3.13).
 *
 * Cuarta vez el mismo defecto —`H-16` en tarifas, `T-12` en el perfil fiscal
 * del creador, el del cliente y la cobertura por país en 3.10— y ésta es la
 * tabla que peor lo lleva: aquí está **el texto legal que el creador aceptó**.
 *
 * `PublicarTerminosCommand` cerraba la versión vigente con
 * `effective_to = effective_from` de la nueva, y `effective_to` es **inclusivo**
 * —lo dice `ck_terms_versions_dates`—. El día de cada publicación había dos
 * versiones vigentes. «¿Qué versión regía el 1 de mayo?» tenía dos respuestas,
 * que es justo la pregunta que se contesta el día que alguien discute qué
 * aceptó.
 *
 * **Por qué se escapó de 3.10**, que es la parte que conviene recordar: aquel
 * barrido buscó tablas con columnas `valid_from`, y éstas se llaman
 * `effective_from`. Un defecto de clase escondido detrás de un nombre. Desde
 * ahora `tools/verificar-periodos.py` busca por **forma** —cualquier par
 * `X_from`/`X_to` de tipo fecha— y exige que cada tabla así o tenga regla o
 * esté en la lista de exclusiones con su motivo escrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Periodo::exigirSinSolapePrevio(
            tabla: 'terms_versions',
            serie: ['code'],
            queSignifica: 'Cada uno significa que para alguna fecha hay DOS versiones de los '
                .'terminos vigentes a la vez, y de ahi sale que texto acepto el creador.',
            comoSeArregla: 'Cierre la anterior el dia ANTES de que empiece la siguiente: '
                .'`effective_to` es inclusivo.',
            desde: 'effective_from',
            hasta: 'effective_to',
        );

        Periodo::sinSolape(
            tabla: 'terms_versions',
            nombre: 'tver_sin_solape',
            serie: ['code'],
            mensaje: 'Ya hay una version de esos terminos vigente en esas fechas: cierre la anterior el dia antes.',
            desde: 'effective_from',
            hasta: 'effective_to',
        );
    }

    public function down(): void
    {
        Periodo::quitar('terms_versions', 'tver_sin_solape');
    }
};
