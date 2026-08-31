<?php

declare(strict_types=1);

namespace App\Shared\Files;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Quién puede mirar un archivo (9.15).
 *
 * ### El problema que resuelve
 *
 * `Almacen` guardaba desde la Fase 3 y **nadie servía**: no existía ni una ruta
 * que devolviera un archivo. La pantalla de conciliación aceptaba el comprobante
 * y la de gastos decía «con comprobante», y no había forma de abrirlo. Una
 * evidencia que nadie puede mirar no es una evidencia (`T-67`, salió en la
 * auditoría de `9.14b`).
 *
 * ### Por qué un registro y no un `switch`
 *
 * La regla de cada archivo depende de **la tabla que lo apunta**: el documento
 * de identidad cuelga de `creators`, la captura de `publication_evidence`, el
 * comprobante de `payouts`. Un `switch` central que las consultara todas
 * pondría a Shared —o a Core— a saber de `payouts`, y `deptrac` no lo vería
 * porque son consultas a tablas y no clases importadas: una frontera rota en
 * silencio, que es la peor clase de frontera rota.
 *
 * Así que **cada módulo declara la regla de sus archivos** en su
 * `ServiceProvider`, igual que registra sus escuchas. Finance sabe de `payouts`
 * y nadie más tiene que saberlo.
 *
 * ### Se niega por omisión
 *
 * Un propósito sin regla registrada **no se abre**. Un archivo nuevo cuyo autor
 * olvidó declarar quién puede verlo se queda cerrado, y no abierto a todos:
 * `ArchivosTest` comprueba que los seis propósitos que existen tienen la suya, y
 * el séptimo que nazca sin ella dará 403 en vez de enseñar el documento de
 * identidad de alguien.
 */
final class Vigilante
{
    /** @var array<string, Closure(object, int): bool> */
    private static array $reglas = [];

    /** @var list<string> */
    private static array $sensibles = [];

    /**
     * Declara quién puede ver los archivos de un propósito.
     *
     * `$sensible` decide si la apertura se anota en la bitácora. Se anotan la
     * identidad y los comprobantes bancarios y no las capturas: éstas se abren
     * decenas de veces al día y anotarlas todas convierte la bitácora en ruido
     * que nadie lee — y una bitácora que nadie lee no protege nada.
     *
     * @param Closure(object, int): bool $regla
     */
    public static function regla(string $proposito, Closure $regla, bool $sensible = false): void
    {
        self::$reglas[$proposito] = $regla;

        if ($sensible && !in_array($proposito, self::$sensibles, true)) {
            self::$sensibles[] = $proposito;
        }
    }

    public static function puedeVer(object $archivo, int $usuarioId): bool
    {
        $regla = self::$reglas[(string) $archivo->purpose] ?? null;

        if ($regla === null) {
            return false;
        }

        return $regla($archivo, $usuarioId);
    }

    public static function esSensible(string $proposito): bool
    {
        return in_array($proposito, self::$sensibles, true);
    }

    /** @return list<string> */
    public static function propositosConRegla(): array
    {
        $propositos = array_keys(self::$reglas);
        sort($propositos);

        /** @var list<string> $propositos */
        return $propositos;
    }

    /**
     * Los propósitos que la aplicación ha llegado a guardar de verdad.
     *
     * @return list<string>
     */
    public static function propositosGuardados(): array
    {
        return DB::table('files')->distinct()->orderBy('purpose')->pluck('purpose')->all();
    }

    /**
     * El archivo, o revienta.
     *
     * Se busca por `uuid` y no por `id`: un id correlativo en una URL invita a
     * probar el siguiente, y aunque el `Vigilante` lo pararía, la mejor puerta
     * es la que ni siquiera se puede enumerar.
     */
    public static function porUuid(string $uuid): object
    {
        $archivo = DB::table('files')->where('uuid', $uuid)
            ->first(['id', 'uuid', 'disk', 'path', 'original_name', 'mime_type',
                'purpose', 'purged_at']);

        if ($archivo === null) {
            throw new RuntimeException("No existe el archivo {$uuid}.");
        }

        return $archivo;
    }

    /** Sólo para las pruebas: olvida las reglas registradas. */
    public static function olvidar(): void
    {
        self::$reglas = [];
        self::$sensibles = [];
    }
}
