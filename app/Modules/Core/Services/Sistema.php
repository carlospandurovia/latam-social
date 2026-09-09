<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Config\Aviso;
use App\Shared\Config\Instalacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que esta instalación es por dentro (`D-1`).
 *
 * ### De dónde sale
 *
 * De vaciar el panel. Hasta hoy la portada del backoffice enseñaba el motor de
 * base de datos, si aplica `CHECK`, si soporta CTE, cuántas tablas hay en el
 * esquema y qué sociedad factura en cada país. Nada de eso es una pregunta
 * operativa: quien abre el panel por la mañana quiere saber qué hacer, no con
 * qué versión de MySQL se está hablando. Se muda entero aquí.
 *
 * ### `DEC-307`: el mecanismo se LEE, no se supone
 *
 * El panel decía «Aplica los CHECK de forma nativa: no, se usan TRIGGER», y lo
 * decía **también en MySQL 8**, donde es falso. El motivo es honesto y estaba
 * escrito: `Restriccion::motorAplicaCheck()` sondea creando y borrando una
 * tabla, y eso no puede pasar en una petición web —hace DDL, hace commit
 * implícito y obligaría al usuario de la aplicación a tener `CREATE` y `DROP`,
 * que es justo lo que `DEC-085` le quita—. Así que fuera de consola devuelve el
 * caso conservador **sin mirar**.
 *
 * Devolver el caso conservador para *decidir qué instalar* es correcto. Pintarlo
 * en una pantalla que se titula «lo que el servidor hace de verdad» es otra cosa:
 * es una suposición disfrazada de medición, que es la familia de `DEC-300`.
 *
 * La fuente buena existía desde la primera migración: `schema_constraints`
 * guarda, por cada regla, **con qué mecanismo se impuso realmente** en el
 * momento de instalarla. Eso no es una sonda, es un registro. Lo que no se puede
 * medir sin DDL se lee de ahí; lo que sí —CTE, funciones de ventana, juego de
 * caracteres, modo estricto— se mide con un `SELECT`, que no toca nada.
 */
final class Sistema
{
    /**
     * Lo que esta máquina es.
     *
     * @return array{nombre: string, clave: string, es_produccion: bool, barrera_abierta: bool}
     */
    public static function entorno(): array
    {
        return [
            'nombre' => Instalacion::nombre(),
            'clave' => Instalacion::entorno(),
            'es_produccion' => Instalacion::esProduccion(),
            'barrera_abierta' => Instalacion::anulacionAbierta(),
        ];
    }

    /**
     * La aplicación y la máquina que la corre.
     *
     * @return array<string, string>
     */
    public static function aplicacion(): array
    {
        return [
            'PHP' => PHP_VERSION,
            'Laravel' => app()->version(),
            'Servidor web' => (string) (request()->server('SERVER_SOFTWARE') ?? 'desconocido'),
            'Dirección pública' => (string) config('app.url'),
            'Zona horaria de almacenamiento' => (string) config('app.timezone'),
            'Idioma' => (string) config('app.locale').' (respaldo: '.config('app.fallback_locale').')',
        ];
    }

    /**
     * El motor, medido con `SELECT` y nada más.
     *
     * Ninguna de estas consultas escribe ni cambia el esquema, así que se pueden
     * correr con el usuario de la aplicación —el que no tiene `DROP`— sin
     * excepciones y sin abrir un agujero.
     *
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function motor(): array
    {
        $charset = (string) DB::selectOne('SELECT @@character_set_database AS v')->v;
        $modo = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS v')->v;
        $estricto = str_contains($modo, 'STRICT_TRANS_TABLES') || str_contains($modo, 'STRICT_ALL_TABLES');

        return [
            'Versión del servidor' => [true, (string) DB::selectOne('SELECT VERSION() AS v')->v],
            'Base de datos' => [true, (string) DB::connection()->getDatabaseName()],
            'Juego de caracteres' => [str_starts_with($charset, 'utf8mb4'), $charset],
            'Cotejamiento' => [true, (string) DB::selectOne('SELECT @@collation_database AS v')->v],
            'Modo estricto en esta sesión' => [$estricto, $estricto ? 'sí' : 'NO'],
            'Soporta CTE (WITH)' => self::soporta('WITH x AS (SELECT 1 AS a) SELECT a FROM x'),
            'Soporta funciones de ventana' => self::soporta('SELECT ROW_NUMBER() OVER (ORDER BY 1) AS r'),
        ];
    }

    /**
     * Cómo están impuestas las reglas, según el registro y no según una sonda.
     *
     * @return array{total: int, por_mecanismo: array<string, int>, tablas: int, disparadores: int}
     */
    public static function reglas(): array
    {
        $base = (string) DB::connection()->getDatabaseName();

        $por = [];
        if (DB::getSchemaBuilder()->hasTable('schema_constraints')) {
            foreach (DB::table('schema_constraints')
                ->select('mechanism', DB::raw('COUNT(*) AS n'))
                ->groupBy('mechanism')
                ->get() as $fila) {
                $por[(string) $fila->mechanism] = (int) $fila->n;
            }
        }

        return [
            'total' => array_sum($por),
            'por_mecanismo' => $por,
            'tablas' => (int) DB::selectOne(
                'SELECT COUNT(*) AS n FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
                [$base, 'BASE TABLE'],
            )->n,
            'disparadores' => (int) DB::selectOne(
                'SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?',
                [$base],
            )->n,
        ];
    }

    /**
     * Qué sociedad factura en cada país. Vigente, sin empates posibles.
     *
     * @return Collection<int, \stdClass>
     */
    public static function cobertura(): Collection
    {
        if (!DB::getSchemaBuilder()->hasTable('legal_entity_countries')) {
            return collect();
        }

        return DB::table('legal_entity_countries as lec')
            ->join('countries as c', 'c.id', '=', 'lec.country_id')
            ->join('legal_entities as le', 'le.id', '=', 'lec.legal_entity_id')
            ->whereNull('lec.valid_to')
            ->orderBy('c.name')
            ->get(['c.name as pais', 'le.code as sociedad', 'lec.coverage_basis as motivo']);
    }

    /**
     * Sólo lo que está MAL. Nada de ámbares permanentes.
     *
     * Que este motor no aplique `CHECK` **no es un aviso**: es una limitación
     * asumida, compensada y verificada (`DEC-042`), y ponerla en ámbar para
     * siempre haría exactamente lo que `DEC-282` prohíbe —esconder los ámbares
     * que sí hay que mirar detrás de uno que nunca se va a apagar—.
     *
     * @return list<Aviso>
     */
    public static function avisos(): array
    {
        $avisos = [];
        $motor = self::motor();

        if (!$motor['Juego de caracteres'][0]) {
            $avisos[] = Aviso::rojo(
                'La base no está en utf8mb4 ('.$motor['Juego de caracteres'][1].'): '
                .'los emoji y algunos caracteres no caben y se guardarán mutilados.',
            );
        }

        if (!$motor['Modo estricto en esta sesión'][0]) {
            $avisos[] = Aviso::rojo(
                'Sin modo estricto, un INSERT que omite una columna obligatoria mete 0 o vacío '
                .'en vez de fallar, y media docena de restricciones dejan de significar lo que parecen.',
            );
        }

        $reglas = self::reglas();
        if ($reglas['total'] === 0) {
            $avisos[] = Aviso::ambar(
                'No hay ninguna restricción registrada. O falta migrar, o algo se instaló sin anotarse.',
            );
        }

        if (self::cobertura()->isEmpty()) {
            $avisos[] = Aviso::ambar(
                'Ningún país tiene sociedad que lo facture: no se podrá emitir ningún comprobante.',
            );
        }

        return $avisos;
    }

    /** @return array{0: bool, 1: string} */
    private static function soporta(string $sql): array
    {
        try {
            DB::select($sql);

            return [true, 'sí'];
        } catch (\Throwable) {
            return [false, 'no'];
        }
    }
}
