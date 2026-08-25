<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Un choque de unicidad, leído por el nombre de su índice (`T-17`).
 *
 * ### El problema que resuelve
 *
 * Media docena de sitios de esta aplicación calculan un valor y luego lo
 * insertan contra una única: el slug de una marca, el puesto de contacto
 * principal, la huella de una cuenta bancaria. Entre el cálculo y la inserción
 * cabe otra petición, y entonces la base contesta con un `1062` que el operador
 * ve en crudo.
 *
 * Hay dos respuestas posibles y son opuestas:
 *
 * - **Absorber.** El valor lo calculó el sistema, no la persona. Si «acme-2» ya
 *   está cogido, se recalcula y se vuelve a intentar. Nadie tiene por qué
 *   enterarse.
 * - **Contar.** El valor lo escribió la persona. Un RUC repetido no se arregla
 *   recalculándolo: hay que decírselo.
 *
 * Lo peligroso es confundirlas, y una captura de `QueryException` a secas las
 * confunde todas: absorbería el RUC repetido, el correo duplicado y el
 * `uq_cb_name` que sí es un error del operador. Por eso aquí **nunca** se
 * absorbe un choque genérico: hay que nombrar el índice.
 *
 * ### Por qué el nombre del índice se extrae y no se compara entero
 *
 * El mismo choque se cuenta distinto según el motor. Comprobado, no supuesto:
 *
 * | Motor | Mensaje |
 * |---|---|
 * | MySQL 8.0.46 | `Duplicate entry 'acme' for key 't17.uq_cb_slug'` |
 * | MariaDB 10.11 | `Duplicate entry 'acme' for key 'uq_cb_slug'` |
 *
 * MySQL 8 antepone la tabla; MariaDB y MySQL 5.7 —o sea **Percona 5.7, que es
 * producción**— no. Un `str_contains($mensaje, "'uq_cb_slug'")` funcionaría en
 * producción y fallaría en el CI, que es la peor combinación posible: verde
 * donde se prueba, roto donde se cobra. Se corta por el último punto, que da lo
 * mismo en los dos formatos.
 *
 * El valor duplicado se lee de la PRIMERA comilla y el índice de la segunda, así
 * que un valor con puntos —`'acme.co'`— no confunde nada.
 */
final class Choque
{
    /** Cuántos intentos como mucho. Ver `reintentar()` para el porqué de 3. */
    public const INTENTOS = 3;

    /**
     * El nombre del índice que provocó el choque, sin la tabla.
     *
     * `null` si esto no es un choque de unicidad, o si el motor no dijo qué
     * índice fue. Devolver `null` y no una cadena vacía es deliberado: quien
     * pregunta tiene que poder distinguir «no fue un choque» de «fue un choque
     * de un índice que no sé nombrar», y las dos respuestas llevan al mismo
     * sitio —no absorber— pero por motivos distintos.
     */
    public static function indice(Throwable $e): ?string
    {
        if (!self::esChoque($e)) {
            return null;
        }

        // La ULTIMA aparición, no la primera: si el valor duplicado contuviera
        // literalmente «for key '», la primera sería parte del dato.
        $mensaje = $e->getMessage();
        $pos = strrpos($mensaje, "for key '");

        if ($pos === false) {
            return null;
        }

        $resto = substr($mensaje, $pos + strlen("for key '"));
        $fin = strpos($resto, "'");

        if ($fin === false) {
            return null;
        }

        $indice = substr($resto, 0, $fin);

        // `t17.uq_cb_slug` (MySQL 8) y `uq_cb_slug` (MariaDB, 5.7) tienen que
        // dar lo mismo. Con backticks tambien: algunos motores los ponen.
        $punto = strrpos($indice, '.');

        if ($punto !== false) {
            $indice = substr($indice, $punto + 1);
        }

        return trim($indice, '`') ?: null;
    }

    /** ¿Es un choque contra ESTE índice y no contra otro? */
    public static function esDe(Throwable $e, string $indice): bool
    {
        return self::indice($e) === $indice;
    }

    /**
     * Ejecuta la acción y, si choca contra ESE índice, la repite.
     *
     * La acción tiene que recalcular por dentro lo que chocó: si devuelve el
     * mismo valor, el segundo intento choca igual y sólo se ha perdido tiempo.
     * Por eso recibe el número de intento, para que quien la escribe no pueda
     * ignorar que se la puede llamar más de una vez.
     *
     * ### Por qué tres y no «hasta que entre»
     *
     * Un bucle sin tope convierte un índice mal entendido en una petición que no
     * termina nunca. Tres cubre el caso real —dos operadores dando de alta a la
     * vez— y deja que un choque persistente salga a la superficie como lo que
     * es: un error, no una espera.
     *
     * ### Y por qué esto no rompe la transacción de fuera
     *
     * En InnoDB un `1062` deshace **la sentencia**, no la transacción: la
     * conexión sigue viva y el `INSERT` se puede repetir dentro de la misma
     * transacción. Comprobado contra el motor —ver la prueba
     * `test_reintentar_dentro_de_una_transaccion_no_la_mata`—, porque de esto
     * depende que el alta de cliente no pierda el cliente al recalcular el slug.
     *
     * @template T
     *
     * @param callable(int): T $accion
     * @return T
     */
    public static function reintentar(string $indice, callable $accion, int $intentos = self::INTENTOS)
    {
        $intentos = max(1, $intentos);

        for ($n = 1; ; $n++) {
            try {
                return $accion($n);
            } catch (Throwable $e) {
                // El ultimo intento no se absorbe: si sigue chocando, que se
                // vea. Y un choque de OTRO indice no se absorbe nunca —puede
                // ser el error del operador que alguien tiene que leer—.
                if ($n >= $intentos || !self::esDe($e, $indice)) {
                    throw $e;
                }
            }
        }
    }

    // ------------------------------------------------------------------ apoyo

    private static function esChoque(Throwable $e): bool
    {
        if ($e instanceof UniqueConstraintViolationException) {
            return true;
        }

        // Laravel traduce el 1062 a `UniqueConstraintViolationException` desde
        // la 10, pero no en todos los caminos —una excepcion re-lanzada, o una
        // conexion de otro driver, llegan como `QueryException`—. El SQLSTATE
        // es la senal que no depende de eso.
        return str_contains($e->getMessage(), 'Integrity constraint violation: 1062')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
