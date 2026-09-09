<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El arreglo de `T-120`, para las bases donde la anterior ya corrió.
 *
 * ### Qué pasó
 *
 * `2026_09_07_000900` creaba `base_currency_code` como `NOT NULL DEFAULT 'PEN'`
 * y sembraba la fila con esa moneda. En una base **con datos** —la de
 * desarrollo, donde `currencies` ya estaba sembrada— eso funcionó. En una base
 * **limpia** —la de las pruebas, que se migra antes de sembrar nada— la foránea
 * apuntaba a un catálogo vacío y la migración moría con un `1452`, tumbando
 * `RefreshDatabase` y con él la batería entera.
 *
 * La 000900 ya está corregida para quien migre desde cero. Esta existe para la
 * otra mitad: las bases donde la vieja ya se aplicó y no va a volver a correr.
 * Editar una migración aplicada no cambia lo que dejó escrito, y ésa es la
 * lección que este archivo hace permanente.
 *
 * ### Y de paso deja las dos bases iguales
 *
 * La moneda vuelve a `NULL` **sólo donde nadie la confirmó**, que es lo que dice
 * la verdad: la fila existe, nadie ha elegido todavía, y el valor de partida lo
 * pone `Consolidacion::PARTIDA`. Una elección real —`confirmed_at` con fecha—
 * no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('consolidation_settings')) {
            return;
        }

        Schema::table('consolidation_settings', function (Blueprint $table): void {
            $table->char('base_currency_code', 3)->nullable()->change();
        });

        // Sin `confirmed_at` nadie eligio: lo que habia era el valor de fabrica
        // disfrazado de decision. Se borra el disfraz, no el dato.
        DB::table('consolidation_settings')
            ->whereNull('confirmed_at')
            ->update(['base_currency_code' => null]);
    }

    /**
     * Vacío a propósito.
     *
     * Volver a poner `NOT NULL` exigiría inventarse una moneda para las filas
     * que legítimamente no la tienen, que es el defecto que esta migración vino
     * a quitar. Deshacer no puede reintroducir el fallo.
     */
    public function down(): void {}
};
