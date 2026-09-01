<?php

declare(strict_types=1);

namespace App\Shared\Config;

use Closure;

/**
 * El registro de pestañas de Integraciones (9.17g).
 *
 * ### Por qué hace falta un registro y no una llamada directa
 *
 * La pestaña del correo la alimenta `Communication`, y la pantalla vive en
 * `Core`. **`Core` no puede depender de `Communication`** —`deptrac.yaml` dice
 * `Core: [Framework, Shared]`— y con razón: el módulo de más abajo no puede
 * conocer a los de arriba, o el grafo deja de ser acíclico y todo se puede
 * llamar entre sí.
 *
 * Así que se invierte: **cada módulo registra su pestaña**, y la pantalla pinta
 * lo que haya registrado. Es exactamente el mismo patrón que `Preparacion` usa
 * para las áreas del panel de configuración desde `9.17b`, y por el mismo
 * motivo — allí también era `Core` pintando avisos de `Creator` y de
 * `Communication`.
 *
 * De paso resuelve la crítica que abrió `9.17f` en su forma general: *«cada
 * proveedor de integración tiene diferentes parámetros»*. Una integración nueva
 * con parámetros propios es **una llamada a `registrar()` desde su módulo**, no
 * un `if` más dentro de un controlador de Core.
 */
final class Pestanas
{
    /**
     * @var array<string, array{titulo: string, orden: int, datos: Closure(): array<string, mixed>, avisos: Closure(): list<Aviso>}>
     */
    private static array $pestanas = [];

    /**
     * @param int $orden Más bajo, más a la izquierda.
     * @param Closure(): array<string, mixed> $datos Lo que necesita su plantilla.
     * @param Closure(): list<Aviso> $avisos Lo que falta ahí dentro.
     */
    public static function registrar(
        string $clave,
        string $titulo,
        Closure $datos,
        Closure $avisos,
        int $orden = 50,
    ): void {
        self::$pestanas[$clave] = [
            'titulo' => $titulo,
            'orden' => $orden,
            'datos' => $datos,
            'avisos' => $avisos,
        ];
    }

    /** @return array<string, string> clave => título, en orden */
    public static function rotulos(): array
    {
        $ordenadas = self::ordenadas();

        return array_map(static fn (array $p): string => $p['titulo'], $ordenadas);
    }

    /** La primera, que es la que se abre sin pedir ninguna. */
    public static function primera(): string
    {
        return (string) array_key_first(self::ordenadas());
    }

    public static function existe(string $clave): bool
    {
        return isset(self::$pestanas[$clave]);
    }

    /** @return array<string, mixed> */
    public static function datosDe(string $clave): array
    {
        return self::existe($clave) ? (self::$pestanas[$clave]['datos'])() : [];
    }

    /** @return list<Aviso> */
    public static function avisosDe(string $clave): array
    {
        return self::existe($clave) ? (self::$pestanas[$clave]['avisos'])() : [];
    }

    /**
     * Cuántas cosas rojas tiene cada pestaña, para la chapa del rótulo.
     *
     * @return array<string, int>
     */
    public static function pendientes(): array
    {
        $cuenta = [];

        foreach (array_keys(self::ordenadas()) as $clave) {
            $cuenta[$clave] = count(array_filter(
                self::avisosDe($clave),
                static fn (Aviso $a): bool => $a->nivel === Aviso::ROJO,
            ));
        }

        return $cuenta;
    }

    /** Para las pruebas: dejar el registro como estaba. */
    public static function olvidar(): void
    {
        self::$pestanas = [];
    }

    /** @return array<string, array{titulo: string, orden: int, datos: Closure, avisos: Closure}> */
    private static function ordenadas(): array
    {
        $copia = self::$pestanas;
        uasort($copia, static fn (array $a, array $b): int => $a['orden'] <=> $b['orden']);

        return $copia;
    }
}
