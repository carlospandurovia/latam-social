<?php

declare(strict_types=1);

namespace App\Modules\Finance\Emision;

use Closure;
use RuntimeException;

/**
 * Qué enviador sirve para cada país (9.9e). Mismo registro que `Armadores`.
 */
final class Enviadores
{
    /** @var array<string, Closure(): EnviadorDeComprobante> */
    private static array $registro = [];

    /** @param Closure(): EnviadorDeComprobante $fabrica */
    public static function registrar(string $paisIso, Closure $fabrica): void
    {
        self::$registro[strtoupper($paisIso)] = $fabrica;
    }

    public static function hay(string $paisIso): bool
    {
        return isset(self::$registro[strtoupper($paisIso)]);
    }

    /** @return list<string> */
    public static function paises(): array
    {
        $paises = array_keys(self::$registro);
        sort($paises);

        return $paises;
    }

    public static function para(string $paisIso): EnviadorDeComprobante
    {
        $pais = strtoupper($paisIso);

        if (!isset(self::$registro[$pais])) {
            throw new RuntimeException(sprintf(
                'No hay forma de entregar un comprobante electronico en %s. Hoy se sabe entregar en: %s.',
                $pais,
                self::$registro === [] ? 'ningun pais' : implode(', ', self::paises()),
            ));
        }

        return (self::$registro[$pais])();
    }

    /** Para las pruebas: dejar el registro como estaba. */
    public static function olvidar(): void
    {
        self::$registro = [];
    }
}
