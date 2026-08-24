<?php

declare(strict_types=1);

use App\Shared\Database\Periodo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los cuatro disparadores de 3.9, generados en vez de tecleados (`T-14`).
 *
 * `2026_08_22_000495` los escribió a mano porque `App\Shared\Database\Periodo`
 * todavía no existía: nació en 3.10, al descubrir que el mismo defecto estaba
 * abierto en otras cinco tablas. Ahora hay dos formas de imponer la misma regla
 * en el mismo repositorio, y la de 3.9 son doce líneas de SQL repetidas cuatro
 * veces con las columnas cambiadas.
 *
 * Eso no es solo feo. Significa que un arreglo futuro hay que aplicarlo en dos
 * sitios, y el segundo es el que se olvida. Los nombres de los disparadores son
 * los mismos —`Periodo` los deriva igual—, así que esto es literalmente
 * cambiar el generador sin cambiar el resultado.
 *
 * **Lo que sí cambia, y conviene saberlo:**
 *
 * - Las columnas de serie se comparan con `<=>` en vez de `=`. Hoy da igual
 *   porque las cinco son `NOT NULL`; si alguna dejara de serlo, `=` haría que
 *   dos filas con `NULL` no se vieran entre sí y el solape pasaría por ahí.
 * - El `INSERT` y el `UPDATE` comparten mensaje. Antes eran dos textos
 *   distintos; el de `UPDATE` decía «el cambio dejaría dos tarifas solapadas»,
 *   que era mejor. El de ahora está redactado para valer en los dos casos. Estos
 *   mensajes son el último recurso —los controladores cierran el periodo
 *   anterior ellos mismos, así que un usuario no debería verlos nunca—, no una
 *   pantalla.
 *
 * Verificable sin PHPUnit: `tools/pruebas/3.9-tarifas.sh` son 23 aserciones
 * sobre exactamente estos cuatro disparadores.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Los de 3.9 se crearon con `DB::unprepared` a pelo, no con `Periodo`,
        // así que no hay nada registrado que `quitar()` pueda leer: se borran
        // por nombre y punto.
        foreach (self::VIEJOS as $nombre) {
            DB::statement("DROP TRIGGER IF EXISTS `{$nombre}`");
        }

        Periodo::sinSolape(
            tabla: 'creator_rates',
            nombre: 'crate_sin_solape',
            serie: ['creator_id', 'content_format_id', 'currency_code'],
            mensaje: 'Ese periodo se solapa con otra tarifa del mismo formato y moneda: cierre la anterior el dia antes.',
        );

        Periodo::sinSolape(
            tabla: 'creator_availability',
            nombre: 'cav_sin_solape',
            serie: ['creator_id'],
            mensaje: 'Ese periodo se solapa con otra disponibilidad declarada: cierre la anterior el dia antes.',
        );
    }

    public function down(): void
    {
        // Se dejan quitados. Volver a los escritos a mano sería reintroducir la
        // duplicación que esta migración vino a eliminar, y `000495` los vuelve
        // a crear si se deshace hasta allí.
        Periodo::quitar('creator_rates', 'crate_sin_solape');
        Periodo::quitar('creator_availability', 'cav_sin_solape');
    }

    private const VIEJOS = [
        'tg_crate_sin_solape_ins',
        'tg_crate_sin_solape_upd',
        'tg_cav_sin_solape_ins',
        'tg_cav_sin_solape_upd',
    ];
};
