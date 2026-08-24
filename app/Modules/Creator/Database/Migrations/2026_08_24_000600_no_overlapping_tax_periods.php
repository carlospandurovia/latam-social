<?php

declare(strict_types=1);

use App\Shared\Database\Periodo;
use Illuminate\Database\Migrations\Migration;

/**
 * El histórico fiscal del creador deja de tener dos respuestas (`T-12`).
 *
 * `uq_ctp_current` garantiza que hay **un solo perfil vigente** por creador y
 * país, y eso está bien. Lo que no garantiza es que el histórico sea coherente:
 *
 *     ¿cuál es el régimen de HOY?        → uno solo, garantizado
 *     ¿cuál era el 1 de mayo?            → podían ser dos
 *
 * Es exactamente el defecto que `H-16` cerró en tarifas, un día por perfil:
 * `PerfilFiscalController` cierra el perfil anterior poniéndole
 * `valid_to = valid_from` del nuevo, y `valid_to` es **inclusivo** en todo el
 * esquema. El día del relevo los dos están vigentes.
 *
 * En un historial de precios eso se paga explicando una factura. En un historial
 * fiscal se paga en una declaración: la retención que se aplicó ese día sale de
 * un régimen que, según la base, podía ser cualquiera de los dos.
 *
 * **Qué cuenta como «ocupar periodo».** `approved` y `superseded`, no solo
 * `approved`. Y esto no es un detalle: la primera versión de esta migración
 * filtraba solo por `approved` y **no habría cazado el defecto que viene a
 * arreglar**.
 *
 * El motivo es que `PerfilFiscalController` marca el perfil anterior como
 * `superseded` **en la misma transacción** en la que aprueba el nuevo. Con el
 * filtro estrecho nunca hay dos `approved` a la vez, la regla no se dispara
 * jamás, y el solape de un día entra igual que antes. Se vio al abrir el
 * controlador para arreglarlo, no al escribir la regla.
 *
 * Y es lo correcto además de lo que funciona: `superseded` quiere decir
 * **reemplazado**, no anulado. Ese perfil sí estuvo vigente durante su ventana,
 * y de él salió la retención que se practicó esos meses. La pantalla lo enseña
 * en el histórico precisamente por eso.
 *
 * `pending` y `rejected` no ocupan: nunca estuvieron vigentes, y si estorbaran,
 * un error de captura bloquearía el histórico del creador para siempre. Al
 * revés sí: aprobar más tarde uno que se solapa se rechaza, porque en ese
 * momento pasa a ocupar. El `UPDATE` lo cubre igual que el `INSERT`.
 *
 * La base impone que no haya solape; **cerrar el anterior el día antes es cosa
 * del controlador**, y va en la segunda mitad de esta iteración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Periodo::exigirSinSolapePrevio(
            tabla: 'creator_tax_profiles',
            serie: ['creator_id', 'country_id'],
            donde: "status IN ('approved', 'superseded')",
            queSignifica: 'Cada uno significa que para alguna fecha hay DOS regimenes fiscales '
                .'aplicables, y de ahi sale la retencion que se le practico al creador.',
            comoSeArregla: 'Cierre el anterior el dia ANTES de que empiece el siguiente '
                .'--`valid_to` es inclusivo-- o marque como `superseded` el que no aplico. '
                .'Cual de los dos valia ese dia es una respuesta contable, no la decide esta migracion.',
        );

        Periodo::sinSolape(
            tabla: 'creator_tax_profiles',
            nombre: 'ctp_sin_solape',
            serie: ['creator_id', 'country_id'],
            mensaje: 'Ya hay un perfil fiscal aprobado para ese pais en esas fechas: cierre el anterior el dia antes.',
            donde: "status IN ('approved', 'superseded')",
            columnasDonde: ['status'],
        );
    }

    public function down(): void
    {
        Periodo::quitar('creator_tax_profiles', 'ctp_sin_solape');
    }
};
