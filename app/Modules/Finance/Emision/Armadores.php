<?php

declare(strict_types=1);

namespace App\Modules\Finance\Emision;

use Closure;
use RuntimeException;

/**
 * Qué armador sirve para cada país (9.9d).
 *
 * ### Un registro, y no un `match` dentro del servicio
 *
 * Es el mismo patrón que `Pestanas` (`9.17g`) y `Preparacion` (`9.17b`), y por
 * el mismo motivo: **el país nuevo entra registrándose**, no editando un `if`
 * que vive en otro sitio. El día que haya que emitir en Colombia, eso es una
 * clase y una línea en el proveedor — no tocar el servicio que factura.
 *
 * ### Por qué el registro guarda una fábrica y no una instancia
 *
 * Armar un adaptador puede costar —Greenter monta Twig y un firmador— y la
 * mayoría de las peticiones no emiten nada. Se construye cuando se usa.
 *
 * ### Y por qué NO hay un armador «por defecto»
 *
 * Un país sin armador **no puede emitir electrónicamente**, y decirlo con esas
 * palabras es mucho más útil que armar un XML genérico que ninguna
 * administración va a aceptar. La factura en papel sigue existiendo; el
 * comprobante electrónico inventado, no.
 */
final class Armadores
{
    /** @var array<string, Closure(): ArmadorDeComprobante> */
    private static array $registro = [];

    /** @param Closure(): ArmadorDeComprobante $fabrica */
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

    public static function para(string $paisIso): ArmadorDeComprobante
    {
        $pais = strtoupper($paisIso);

        if (!isset(self::$registro[$pais])) {
            throw new RuntimeException(sprintf(
                'No hay forma de emitir un comprobante electronico en %s. '
                .'Hoy se sabe emitir en: %s.',
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
