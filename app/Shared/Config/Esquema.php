<?php

declare(strict_types=1);

namespace App\Shared\Config;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * ¿Está la base al día con el código que la usa? (9.17j, `T-84`)
 *
 * ### De dónde sale
 *
 * De una mañana entera. Se desplegó el código de `9.17g` sin correr
 * `php artisan migrate`, y guardar una cuenta de correo devolvió esto a la cara
 * del usuario:
 *
 * ```
 * SQLSTATE[45000]: <<Unknown error>>: 1644 Esa conexion no sabe a donde llamar…
 * ```
 *
 * El mensaje era **correcto** y **completamente inútil**: lo daba la regla de
 * `9.17e`, que la migración de `9.17g` venía a sustituir. Las dos versiones del
 * disparador se llaman igual y dicen lo mismo, así que para saber cuál estaba
 * instalada hubo que ir a comparar las CONDICIONES de las dos. Nadie que use el
 * sistema puede hacer eso, y nadie debería tener que hacerlo.
 *
 * **El defecto no era el mensaje. Era que el sistema no sabía que le faltaba
 * migrar**, teniendo delante las dos únicas listas que hacen falta para saberlo:
 * los archivos en disco y la tabla `migrations`.
 *
 * ### Las dos direcciones
 *
 * - **Faltan por aplicar** — el código va por delante de la base. Es el caso de
 *   aquella mañana, y el que rompe cosas: el código llama a tablas y columnas
 *   que todavía no existen.
 * - **Aplicadas que ya no están en el código** — la base va por delante. Pasa
 *   al volver atrás a una rama anterior, y avisa de que lo que se está mirando
 *   no es lo que hay desplegado.
 *
 * Las dos se preguntan con la misma comparación, así que se contestan las dos.
 *
 * ### Por qué no se cachea
 *
 * Un aviso de «falta migrar» que llega tarde es peor que no tenerlo: se corre
 * `migrate`, se recarga, sigue en rojo, y se deja de creer al aviso. Son un
 * `SELECT` a una tabla de cien filas y un `glob` por carpeta de módulo — el
 * precio de acertar siempre es más barato que el de acertar casi siempre.
 */
final class Esquema
{
    /**
     * Lo que hay en disco y no está aplicado.
     *
     * @return list<string>
     */
    public static function pendientes(): array
    {
        return self::comparar()['pendientes'];
    }

    /**
     * Lo aplicado que ya no está en disco.
     *
     * @return list<string>
     */
    public static function desconocidas(): array
    {
        return self::comparar()['desconocidas'];
    }

    /**
     * El aviso, o `null` si la base está al día.
     *
     * Rojo y no ámbar: con el esquema atrasado, cualquier pantalla puede
     * devolver un error de SQL en crudo, y eso no es un ajuste pendiente — es el
     * sistema a medio desplegar.
     */
    public static function aviso(): ?Aviso
    {
        ['pendientes' => $pendientes, 'desconocidas' => $desconocidas] = self::comparar();

        if ($pendientes !== []) {
            return Aviso::rojo(sprintf(
                'La base de datos está por detrás del código: %s. '
                .'Hasta que se apliquen, cualquier pantalla puede devolver un error de SQL en crudo. '
                .'Se arregla con «php artisan migrate» en el servidor.',
                self::enumerar($pendientes, 'sin aplicar'),
            ));
        }

        if ($desconocidas !== []) {
            return Aviso::ambar(sprintf(
                'La base de datos tiene %s. Suele significar que se ha vuelto a una versión '
                .'anterior: lo desplegado no es lo que hay en la base.',
                self::enumerar($desconocidas, 'aplicadas que ya no están en el código'),
            ));
        }

        return null;
    }

    /**
     * Las dos listas, de una sola pasada.
     *
     * Se pregunta al **migrador de Laravel** y no se busca por carpetas a mano:
     * cada módulo registra su ruta de migraciones en su proveedor, así que la
     * lista buena la tiene él. Buscarlas por mi cuenta significaría acordarse de
     * cada módulo nuevo, que es justo la clase de olvido que produce un
     * verificador que dice «todo bien» mirando la mitad.
     *
     * @return array{pendientes: list<string>, desconocidas: list<string>}
     */
    private static function comparar(): array
    {
        $vacio = ['pendientes' => [], 'desconocidas' => []];

        try {
            /** @var Migrator $migrador */
            $migrador = app('migrator');

            if (!$migrador->repositoryExists()) {
                // Base sin instalar. No es «falta migrar»: es que aqui todavia
                // no hay nada, y quien la instala ya lo sabe.
                return $vacio;
            }

            $enDisco = array_keys($migrador->getMigrationFiles(
                array_merge([database_path('migrations')], $migrador->paths()),
            ));

            $aplicadas = $migrador->getRepository()->getRan();
        } catch (Throwable) {
            // Sin base no hay nada que comparar, y esto lo llama una PLANTILLA:
            // que una pantalla de error reviente pintando el aviso de que algo
            // va mal seria cambiar un problema por otro peor.
            return $vacio;
        }

        return [
            'pendientes' => array_values(array_diff($enDisco, $aplicadas)),
            'desconocidas' => array_values(array_diff($aplicadas, $enDisco)),
        ];
    }

    /**
     * «3 migraciones sin aplicar (la primera: …)».
     *
     * Se nombra **la primera y no la última**: es la que va a fallar antes, y la
     * que sitúa desde cuándo viene el retraso.
     *
     * @param list<string> $cuales
     */
    private static function enumerar(array $cuales, string $cola): string
    {
        $cuantas = count($cuales);
        sort($cuales);

        // «migracion» y «migraciones»: el plural PIERDE la tilde, asi que no
        // se puede armar pegandole «es» al singular. Salio de mirar la pantalla
        // --decia «2 migraciónes»--, no de ninguna prueba: ninguna comprueba la
        // ortografia de un aviso, y sin embargo es lo primero que se lee.
        return sprintf(
            '%d %s %s (la primera: %s)',
            $cuantas,
            $cuantas === 1 ? 'migración' : 'migraciones',
            $cola,
            $cuales[0],
        );
    }
}
