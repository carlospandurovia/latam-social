<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Carbon\CarbonImmutable;

/**
 * Cómo se cierra un periodo, en un solo sitio de todo el proyecto.
 *
 * `Periodo` (al lado) declara la regla en el **esquema**: genera los
 * disparadores que impiden que dos filas de la misma serie se solapen. Esta
 * clase es la otra mitad: cómo la **aplicación** respeta esa regla al escribir.
 *
 * ### `valid_to` es INCLUSIVO
 *
 * Un periodo `2026-01-01 → 2026-05-31` incluye el 31 de mayo. Por tanto, cerrar
 * el anterior con el `valid_from` del siguiente los deja solapados **un día**, y
 * ese día la pregunta que el periodo existe para contestar —qué tarifa, qué
 * régimen, qué RUC, qué sociedad— tiene **dos respuestas**.
 *
 * ### Por qué esto es una clase y no tres líneas repetidas
 *
 * Porque ya se repitieron. Este error apareció en **seis** sitios distintos:
 *
 * | Dónde | Qué se decidía mal ese día |
 * |---|---|
 * | `creator_rates` (`H-16`) | con qué tarifa se le paga a un creador |
 * | `creator_tax_profiles` (`T-12`) | qué retención se le practica |
 * | `PerfilFiscalTest` | — (la prueba confirmaba el error) |
 * | `3.6-fiscal.sh` | — (la suite también) |
 * | `PublicarTerminosCommand` | qué versión de los términos aceptó |
 * | `3.5-activacion.sh` | — |
 *
 * En 4.4 hizo falta otra vez, para `client_tax_profiles`, y se escribió un
 * séptimo `subDay()`. En 4.5 hacía falta un octavo, para
 * `legal_entity_countries`. Ahí se paró: un cálculo que seis veces se hizo mal
 * no puede vivir en cada sitio que lo necesita.
 */
final class Vigencia
{
    /**
     * La fecha con la que hay que cerrar el periodo que termina.
     *
     * Se le pasa **cuándo empieza el siguiente**, no cuándo acaba el anterior:
     * el error consiste justamente en confundir las dos cosas, así que el
     * parámetro se llama como lo que de verdad se sabe.
     */
    public static function cerrarElDiaAntesDe(string $empiezaElSiguiente): string
    {
        return CarbonImmutable::parse($empiezaElSiguiente)->subDay()->toDateString();
    }

    /**
     * El día siguiente al último día cubierto.
     *
     * La hermana de la anterior, y hace falta por lo mismo: `valid_to` es
     * inclusivo, así que el primer día DESCUBIERTO no es `valid_to`, es el
     * siguiente. Decirle a alguien «desde el 30 de junio no se puede facturar»
     * cuando el 30 sí se podía es el mismo error de un día, contado al revés.
     */
    public static function elDiaDespuesDe(string $ultimoDiaCubierto): string
    {
        return CarbonImmutable::parse($ultimoDiaCubierto)->addDay()->toDateString();
    }

    /**
     * ¿Puede el periodo que empieza en `$empieza` relevar a uno que empezó en
     * `$empezoElAnterior`?
     *
     * No, si empieza el mismo día o antes: cerrar el anterior «el día antes» le
     * pondría un `valid_to` anterior a su propio `valid_from`, que es lo que
     * prohíbe cada `ck_*_dates` del esquema. Y no se arregla recortando la
     * fecha —lo que ese caso significa es que el anterior **no estuvo vigente
     * nunca**, y eso no es cerrarlo—.
     *
     * Quien pregunta contesta con palabras; dejarlo llegar a la base da un
     * `45000` que el operador no puede interpretar.
     */
    public static function puedeRelevar(string $empieza, string $empezoElAnterior): bool
    {
        // Se comparan como FECHAS, no como cadenas.
        //
        // `'2026-2-1' > '2026-11-01'` es **cierto** en PHP: compara caracter a
        // caracter y el '2' gana al '1'. La regla `date` de Laravel acepta
        // `2026-2-1`, `Feb 1 2026` y `01/15/2026`, asi que la comparacion de
        // cadenas es correcta solo mientras a nadie se le ocurra escribir una
        // fecha sin ceros. Cuando ocurre, esta guarda —que existe precisamente
        // para que el operador no vea un `45000`— dice que si se puede relevar,
        // y el cierre calculado cae ANTES del `valid_from` del periodo que
        // cierra. Que es el `45000`.
        //
        // Los formularios mandan `<input type="date">`, o sea `Y-m-d`. Pero una
        // orden de consola, una importacion o una peticion a mano no.
        return self::fecha($empieza) > self::fecha($empezoElAnterior);
    }

    /**
     * Un `Y-m-d` canonico, venga como venga.
     *
     * Se usa en todas las comparaciones de esta clase. Quien tenga que comparar
     * fechas en otro sitio, que la use tambien: dos `string` de fecha no se
     * comparan con `>` aunque parezca que si.
     */
    public static function fecha(string $cualquierFecha): string
    {
        return CarbonImmutable::parse($cualquierFecha)->toDateString();
    }
}
