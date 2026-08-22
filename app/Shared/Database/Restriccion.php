<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Compilador de restricciones de integridad.
 *
 * El problema (DEC-042): en MySQL 5.7 la clausula CHECK se analiza y se IGNORA.
 * No hay error, la tabla se crea, y la restriccion no existe. Un esquema lleno
 * de CHECK puede no estar aplicando ninguno.
 *
 * La solucion: se declara la regla UNA vez y esta clase decide como imponerla
 * en el motor que haya delante:
 *
 *   - Motor que aplica CHECK (MySQL 8.0.16+, MariaDB 10.2+) -> CHECK nativo.
 *   - Motor que no (MySQL/Percona 5.7)                      -> TRIGGER equivalente.
 *
 * El mismo codigo de migracion produce el mismo comportamiento en los dos, y el
 * dia que se migre a MySQL 8 no hay que reescribir nada.
 *
 * Uso en una migracion:
 *
 *     Restriccion::comprobacion(
 *         tabla:     'exchange_rates',
 *         nombre:    'ck_exchange_rates_positive',
 *         expresion: 'rate > 0',
 *         columnas:  ['rate'],
 *         mensaje:   'El tipo de cambio debe ser mayor que cero.',
 *     );
 */
final class Restriccion
{
    public const MECANISMO_CHECK = 'check';

    public const MECANISMO_TRIGGER = 'trigger';

    /** Cache por proceso de la sonda del motor. */
    private static ?bool $motorAplicaCheck = null;

    /** Para las pruebas: fuerza un mecanismo concreto. */
    private static ?string $mecanismoForzado = null;

    // ------------------------------------------------------------------ API

    /**
     * Declara una comprobacion y la impone con el mecanismo que soporte el motor.
     *
     * @param  list<string>  $columnas  Las columnas que aparecen en la expresion.
     *                                  Hacen falta para reescribirla como NEW.<col>
     *                                  al generar el trigger. Explicitas a proposito:
     *                                  adivinarlas del texto seria fragil.
     */
    public static function comprobacion(
        string $tabla,
        string $nombre,
        string $expresion,
        array $columnas,
        string $mensaje,
    ): void {
        self::validarNombre($nombre);

        $mecanismo = self::mecanismo();

        if ($mecanismo === self::MECANISMO_CHECK) {
            DB::statement(self::sqlCheck($tabla, $nombre, $expresion));
        } else {
            foreach (['INSERT', 'UPDATE'] as $evento) {
                DB::unprepared(self::sqlTrigger($tabla, $nombre, $expresion, $columnas, $mensaje, $evento));
            }
        }

        self::registrar($tabla, $nombre, $expresion, $columnas, $mensaje, $mecanismo);
    }

    /** Retira una comprobacion, sea cual sea el mecanismo con el que se creo. */
    public static function quitar(string $tabla, string $nombre): void
    {
        self::validarNombre($nombre);

        // Se intentan los dos: la base puede haberse creado con otro motor.
        try {
            DB::statement("ALTER TABLE `{$tabla}` DROP CONSTRAINT `{$nombre}`");
        } catch (\Throwable) {
            // No existia como CHECK.
        }
        foreach (['ins', 'upd'] as $sufijo) {
            DB::statement('DROP TRIGGER IF EXISTS `'.self::nombreTrigger($nombre, $sufijo).'`');
        }

        if (self::hayRegistro()) {
            DB::table('schema_constraints')->where('constraint_name', $nombre)->delete();
        }
    }

    // ------------------------------------------------- Generacion de SQL (pura)

    public static function sqlCheck(string $tabla, string $nombre, string $expresion): string
    {
        return "ALTER TABLE `{$tabla}` ADD CONSTRAINT `{$nombre}` CHECK ({$expresion})";
    }

    /**
     * Genera el TRIGGER equivalente a un CHECK.
     *
     * Detalles que importan:
     *  - SIGNAL SQLSTATE '45000' es la forma estandar de abortar desde un trigger.
     *  - `IF NOT (expr) THEN` y no `IF (NOT expr)`: con NULL, `NOT NULL` es NULL,
     *    que es falso, asi que no aborta. Es el mismo comportamiento que un CHECK
     *    real, que solo falla cuando la expresion es FALSE, nunca cuando es NULL.
     *  - El mensaje se trunca a 128 caracteres: es el limite de MYSQL_ERRNO/MESSAGE_TEXT.
     *
     * @param  list<string>  $columnas
     */
    public static function sqlTrigger(
        string $tabla,
        string $nombre,
        string $expresion,
        array $columnas,
        string $mensaje,
        string $evento,
    ): string {
        $evento = strtoupper($evento);
        if (! in_array($evento, ['INSERT', 'UPDATE'], true)) {
            throw new InvalidArgumentException("Evento no soportado: {$evento}");
        }

        $sufijo = $evento === 'INSERT' ? 'ins' : 'upd';
        $nombreTrigger = self::nombreTrigger($nombre, $sufijo);
        $expresionNew = self::reescribirConNew($expresion, $columnas);
        $texto = self::escaparTexto(mb_substr($mensaje, 0, 128));

        return <<<SQL
            CREATE TRIGGER `{$nombreTrigger}`
            BEFORE {$evento} ON `{$tabla}`
            FOR EACH ROW
            BEGIN
                IF NOT ({$expresionNew}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$texto}';
                END IF;
            END
            SQL;
    }

    /**
     * Reescribe `rate > 0` como `NEW.`rate` > 0`.
     *
     * Solo sustituye las columnas declaradas y solo como palabra completa, para
     * no tocar identificadores que las contengan ni literales de texto que
     * casualmente coincidan. Se ordenan de mas larga a mas corta para que
     * `status_code` no se rompa al sustituir antes `status`.
     *
     * @param  list<string>  $columnas
     */
    public static function reescribirConNew(string $expresion, array $columnas): string
    {
        if ($columnas === []) {
            throw new InvalidArgumentException(
                'Hay que declarar las columnas de la expresion: sin ellas no se puede generar el trigger.'
            );
        }

        usort($columnas, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $partes = self::partirFueraDeLiterales($expresion);
        foreach ($partes as $i => $parte) {
            if ($parte['literal']) {
                continue;
            }
            $texto = $parte['texto'];
            foreach ($columnas as $columna) {
                $texto = preg_replace(
                    '/(?<![`\w.])'.preg_quote($columna, '/').'(?![`\w])/',
                    'NEW.`'.$columna.'`',
                    $texto
                ) ?? $texto;
            }
            $partes[$i]['texto'] = $texto;
        }

        return implode('', array_column($partes, 'texto'));
    }

    // ------------------------------------------------------------- Internos

    /**
     * Parte la expresion separando lo que esta dentro de comillas simples.
     *
     * Sin esto, una expresion como `status IN ('active','status')` acabaria con
     * el literal 'status' convertido en NEW.`status`, generando SQL invalido.
     *
     * @return list<array{texto: string, literal: bool}>
     */
    private static function partirFueraDeLiterales(string $expresion): array
    {
        $partes = [];
        $actual = '';
        $dentro = false;
        $longitud = strlen($expresion);

        for ($i = 0; $i < $longitud; $i++) {
            $caracter = $expresion[$i];

            if ($caracter === "'") {
                // '' dentro de un literal es una comilla escapada, no un cierre.
                if ($dentro && $i + 1 < $longitud && $expresion[$i + 1] === "'") {
                    $actual .= "''";
                    $i++;

                    continue;
                }
                $actual .= $caracter;
                if ($dentro) {
                    $partes[] = ['texto' => $actual, 'literal' => true];
                    $actual = '';
                    $dentro = false;
                } else {
                    if ($actual !== "'") {
                        $partes[] = ['texto' => substr($actual, 0, -1), 'literal' => false];
                        $actual = "'";
                    }
                    $dentro = true;
                }

                continue;
            }
            $actual .= $caracter;
        }

        if ($actual !== '') {
            $partes[] = ['texto' => $actual, 'literal' => $dentro];
        }

        return $partes;
    }

    private static function nombreTrigger(string $nombre, string $sufijo): string
    {
        // Los nombres de trigger de MySQL admiten 64 caracteres.
        $base = mb_substr($nombre, 0, 64 - strlen($sufijo) - 4);

        return "tg_{$base}_{$sufijo}";
    }

    private static function escaparTexto(string $texto): string
    {
        return str_replace(["\\", "'"], ["\\\\", "''"], $texto);
    }

    private static function validarNombre(string $nombre): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,50}$/', $nombre) !== 1) {
            throw new InvalidArgumentException(
                "Nombre de restriccion invalido: '{$nombre}'. Minusculas, digitos y guion bajo, hasta 51 caracteres."
            );
        }
    }

    // --------------------------------------------------------- Sonda del motor

    public static function mecanismo(): string
    {
        if (self::$mecanismoForzado !== null) {
            return self::$mecanismoForzado;
        }

        return self::motorAplicaCheck() ? self::MECANISMO_CHECK : self::MECANISMO_TRIGGER;
    }

    /** Solo para pruebas: obliga a un mecanismo. Pasar null restaura la deteccion. */
    public static function forzarMecanismo(?string $mecanismo): void
    {
        self::$mecanismoForzado = $mecanismo;
        self::$motorAplicaCheck = null;
    }

    /**
     * No se fia del numero de version: lo comprueba. Crea una tabla con un CHECK,
     * intenta violarlo y observa si el motor lo permitio.
     */
    public static function motorAplicaCheck(): bool
    {
        if (self::$motorAplicaCheck !== null) {
            return self::$motorAplicaCheck;
        }

        try {
            DB::statement('DROP TABLE IF EXISTS zz_sonda_restriccion');
            DB::statement('CREATE TABLE zz_sonda_restriccion (n INT, CONSTRAINT ck_sonda CHECK (n > 0))');
            try {
                DB::statement('INSERT INTO zz_sonda_restriccion (n) VALUES (-1)');
                self::$motorAplicaCheck = false;
            } catch (\Throwable) {
                self::$motorAplicaCheck = true;
            } finally {
                DB::statement('DROP TABLE IF EXISTS zz_sonda_restriccion');
            }
        } catch (\Throwable) {
            // Sin permiso para crear tablas: se asume el caso conservador, que
            // es el que impone la regla de verdad.
            self::$motorAplicaCheck = false;
        }

        return self::$motorAplicaCheck;
    }

    // ------------------------------------------------------------- Registro

    /** @param list<string> $columnas */
    private static function registrar(
        string $tabla,
        string $nombre,
        string $expresion,
        array $columnas,
        string $mensaje,
        string $mecanismo,
    ): void {
        if (! self::hayRegistro()) {
            return;
        }

        DB::table('schema_constraints')->updateOrInsert(
            ['constraint_name' => $nombre],
            [
                'table_name' => $tabla,
                'expression' => $expresion,
                'columns_involved' => implode(',', $columnas),
                'message' => $mensaje,
                'mechanism' => $mecanismo,
                'created_at' => now(),
            ],
        );
    }

    private static function hayRegistro(): bool
    {
        return DB::getSchemaBuilder()->hasTable('schema_constraints');
    }
}
