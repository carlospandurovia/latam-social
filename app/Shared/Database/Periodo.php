<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Compilador de la regla «dos periodos de la misma serie no se solapan».
 *
 * POR QUE HACE FALTA
 * ------------------
 * El esquema tiene SIETE tablas con `valid_from` / `valid_to`, y todas usan el
 * mismo truco para garantizar que solo hay una fila VIGENTE: una columna
 * generada (`current_gate`) que vale 1 cuando `valid_to IS NULL`, dentro de un
 * UNIQUE. Ese truco funciona y es bueno, pero solo responde a una pregunta:
 *
 *     ?cual es el perfil de HOY?          -> garantizado, uno solo
 *     ?cual era el perfil el 1 de mayo?   -> NO garantizado, puede haber dos
 *
 * Un historico con dos respuestas para una fecha no sirve para lo unico para lo
 * que existe. En tarifas eso costo `H-16`; en un historico fiscal se paga en una
 * declaracion, y en `legal_entity_countries` deja al resolver de facturacion
 * eligiendo entre dos sociedades para el mismo pais.
 *
 * POR QUE NO ES UNA `Restriccion`
 * -------------------------------
 * `Restriccion` elige entre CHECK y TRIGGER segun el motor. Aqui no hay eleccion
 * que hacer: la regla mira OTRAS FILAS de la tabla, y ningun motor admite una
 * subconsulta dentro de un CHECK --tampoco MySQL 8--. Siempre es un disparador,
 * en los dos motores. Se dice aqui para que nadie lo intente otra vez.
 *
 * USO
 * ---
 *     Periodo::sinSolape(
 *         tabla:   'creator_tax_profiles',
 *         nombre:  'ctp_sin_solape',
 *         serie:   ['creator_id', 'country_id'],
 *         donde:   "status = 'approved'",
 *         mensaje: 'Ya hay un perfil fiscal aprobado para ese pais en esas fechas.',
 *     );
 */
final class Periodo
{
    /** El infinito de una fecha abierta. Cuadra con el `DATE` de MySQL. */
    public const ABIERTO = '9999-12-31';

    /** Palabras de SQL que no son columnas y no hay que prefijar. */
    private const PALABRAS = ['AND', 'OR', 'NOT', 'IN', 'IS', 'NULL', 'TRUE', 'FALSE', 'LIKE', 'BETWEEN'];

    // ------------------------------------------------------------------ API

    /**
     * Impone que dos filas de la misma serie no cubran un mismo dia.
     *
     * @param list<string> $serie Las columnas que definen «la misma serie». Dos
     *                            filas solo compiten si coinciden en todas.
     * @param string|null $donde Filtro opcional: solo las filas que lo cumplen
     *                           ocupan periodo. Un perfil `rejected` nunca
     *                           estuvo vigente, asi que no debe estorbar a
     *                           ninguno posterior.
     * @param list<string> $columnasDonde Las columnas que aparecen en `$donde`.
     *                                    Explicitas por la misma razon que en
     *                                    `Restriccion`: adivinarlas del texto seria
     *                                    fragil.
     */
    public static function sinSolape(
        string $tabla,
        string $nombre,
        array $serie,
        string $mensaje,
        ?string $donde = null,
        array $columnasDonde = [],
        string $desde = 'valid_from',
        string $hasta = 'valid_to',
        string $clavePrimaria = 'id',
    ): void {
        self::validarNombre($nombre);

        if ($serie === []) {
            throw new InvalidArgumentException(
                "La serie de '{$nombre}' esta vacia: sin columnas de serie la regla "
                .'prohibiria dos periodos cualesquiera de la tabla entera.',
            );
        }

        if ($donde !== null && $columnasDonde === []) {
            throw new InvalidArgumentException(
                "'{$nombre}' declara un filtro pero no sus columnas. Sin ellas el filtro "
                .'no se puede reescribir como NEW.<col> y se aplicaria solo a la mitad.',
            );
        }

        foreach (['INSERT', 'UPDATE'] as $evento) {
            DB::unprepared(self::sql(
                $tabla, $nombre, $serie, $mensaje, $donde, $columnasDonde,
                $desde, $hasta, $clavePrimaria, $evento,
            ));
        }

        self::registrar($tabla, $nombre, $serie, $donde, $mensaje);
    }

    /**
     * Los solapes que YA existen en la tabla.
     *
     * Hace falta porque un disparador NO valida lo que ya esta dentro: se crea,
     * dice que si, y las filas que ya se pisaban siguen ahi exactamente igual.
     * La regla quedaria puesta y el historico seguiria mintiendo, que es el peor
     * de los dos mundos --nadie vuelve a mirar una tabla que tiene una
     * restriccion encima--.
     *
     * @param list<string> $serie
     * @return list<object>
     */
    public static function solapes(
        string $tabla,
        array $serie,
        ?string $donde = null,
        int $limite = 20,
        string $desde = 'valid_from',
        string $hasta = 'valid_to',
        string $clavePrimaria = 'id',
    ): array {
        $union = ["b.`{$clavePrimaria}` > a.`{$clavePrimaria}`"];

        foreach ($serie as $columna) {
            $union[] = "b.`{$columna}` <=> a.`{$columna}`";
        }

        $condiciones = [
            "a.`{$desde}` <= IFNULL(b.`{$hasta}`, '".self::ABIERTO."')",
            "b.`{$desde}` <= IFNULL(a.`{$hasta}`, '".self::ABIERTO."')",
        ];

        if ($donde !== null) {
            // El mismo filtro a los dos lados: solo compiten entre si las filas
            // que de verdad ocupan periodo.
            $condiciones[] = '('.self::prefijar($donde, 'a').')';
            $condiciones[] = '('.self::prefijar($donde, 'b').')';
        }

        $claves = implode(', ', array_map(static fn (string $c): string => "a.`{$c}`", $serie));
        $sqlUnion = implode("\n           AND ", $union);
        $sqlDonde = implode("\n           AND ", $condiciones);

        /** @var list<object> $filas */
        $filas = DB::select(<<<SQL
            SELECT {$claves},
                   a.`{$clavePrimaria}` AS id_a, a.`{$desde}` AS desde_a, a.`{$hasta}` AS hasta_a,
                   b.`{$clavePrimaria}` AS id_b, b.`{$desde}` AS desde_b, b.`{$hasta}` AS hasta_b
              FROM `{$tabla}` a
              JOIN `{$tabla}` b
                ON {$sqlUnion}
             WHERE {$sqlDonde}
             ORDER BY a.`{$desde}`
             LIMIT {$limite}
            SQL);

        return $filas;
    }

    /**
     * Se niega a imponer la regla si la tabla ya se contradice.
     *
     * Y no arregla nada: cual de los dos periodos valia el dia que se pisan es
     * una respuesta de negocio, no tecnica. Se para con los casos concretos
     * delante para que alguien la conteste.
     *
     * @param list<string> $serie
     */
    public static function exigirSinSolapePrevio(
        string $tabla,
        array $serie,
        string $queSignifica,
        string $comoSeArregla,
        ?string $donde = null,
        // `valid_from`/`valid_to` es lo comun, pero no es universal:
        // `terms_versions` usa `effective_*`. La primera version de este metodo
        // no admitia otros nombres --el mismo sesgo que dejo esa tabla fuera del
        // barrido de 3.10 durante tres iteraciones--.
        string $desde = 'valid_from',
        string $hasta = 'valid_to',
    ): void {
        $solapes = self::solapes($tabla, $serie, $donde, 20, $desde, $hasta);

        if ($solapes === []) {
            return;
        }

        $lineas = array_map(
            static function (object $s) use ($serie): string {
                $clave = implode(', ', array_map(
                    static fn (string $c): string => $c.'='.($s->{$c} ?? 'NULL'),
                    $serie,
                ));

                return sprintf(
                    '  %s: la fila %d (%s a %s) pisa a la %d (%s a %s)',
                    $clave,
                    $s->id_a, $s->desde_a, $s->hasta_a ?? 'abierto',
                    $s->id_b, $s->desde_b, $s->hasta_b ?? 'abierto',
                );
            },
            $solapes,
        );

        throw new RuntimeException(
            "`{$tabla}` ya tiene periodos solapados.\n"
            .implode("\n", $lineas)
            ."\n\n".$queSignifica."\n".$comoSeArregla,
        );
    }

    public static function quitar(string $tabla, string $nombre): void
    {
        self::validarNombre($nombre);

        foreach (['ins', 'upd'] as $sufijo) {
            DB::statement('DROP TRIGGER IF EXISTS `'.self::nombreTrigger($nombre, $sufijo).'`');
        }

        if (DB::getSchemaBuilder()->hasTable('schema_constraints')) {
            DB::table('schema_constraints')->where('constraint_name', $nombre)->delete();
        }
    }

    // ------------------------------------------------- Generacion de SQL (pura)

    /**
     * @param list<string> $serie
     * @param list<string> $columnasDonde
     */
    public static function sql(
        string $tabla,
        string $nombre,
        array $serie,
        string $mensaje,
        ?string $donde,
        array $columnasDonde,
        string $desde,
        string $hasta,
        string $clavePrimaria,
        string $evento,
    ): string {
        $evento = strtoupper($evento);
        if (!in_array($evento, ['INSERT', 'UPDATE'], true)) {
            throw new InvalidArgumentException("Evento no soportado: {$evento}");
        }

        $sufijo = $evento === 'INSERT' ? 'ins' : 'upd';
        $nombreTrigger = self::nombreTrigger($nombre, $sufijo);
        $texto = self::escaparTexto(mb_substr($mensaje, 0, 128));

        $condiciones = [];

        // La misma serie. `<=>` y no `=`: es la igualdad que trata NULL como un
        // valor mas. Con `=`, dos filas con la misma columna de serie a NULL no
        // se verian entre si --NULL = NULL es NULL, no cierto-- y el solape
        // pasaria justo por el hueco que la regla existe para tapar.
        foreach ($serie as $columna) {
            $condiciones[] = "`{$columna}` <=> NEW.`{$columna}`";
        }

        // Se cruzan los dos periodos. `valid_to` es INCLUSIVO en todo el
        // esquema, asi que dos periodos se tocan cuando cada uno empieza antes
        // de que el otro acabe. Una fecha abierta se compara como el infinito.
        $condiciones[] = "NEW.`{$desde}` <= IFNULL(`{$hasta}`, '".self::ABIERTO."')";
        $condiciones[] = "`{$desde}` <= IFNULL(NEW.`{$hasta}`, '".self::ABIERTO."')";

        if ($donde !== null) {
            $condiciones[] = '('.$donde.')';
        }

        // En UPDATE, la propia fila se solapa consigo misma: hay que excluirla o
        // ninguna fila se podria modificar jamas.
        if ($evento === 'UPDATE') {
            array_unshift($condiciones, "`{$clavePrimaria}` <> NEW.`{$clavePrimaria}`");
        }

        $donde_sql = implode("\n           AND ", $condiciones);

        // El filtro tambien tiene que valer para la fila que ENTRA. Si no, una
        // fila `rejected` --que no ocupa periodo-- seria rechazada por chocar
        // con una `approved`, cuando deberia poder guardarse sin estorbar.
        //
        // Va como una condicion mas del `IF`, no como una salida anticipada. La
        // primera version usaba `bloque: BEGIN ... LEAVE bloque`, que funciona
        // en MySQL 8 y en MariaDB pero que aqui no se puede probar contra
        // Percona 5.7, que es el motor de produccion. Escrito asi no hay
        // etiqueta, ni `LEAVE`, ni bloque con nombre: solo un `IF` con dos
        // condiciones, que es lo que entiende cualquier motor desde siempre.
        // Menos construcciones = menos superficie donde diverjan.
        $guarda = $donde === null
            ? ''
            : '('.Restriccion::reescribirConNew($donde, $columnasDonde).")\n       AND ";

        return <<<SQL
            CREATE TRIGGER `{$nombreTrigger}`
            BEFORE {$evento} ON `{$tabla}`
            FOR EACH ROW
            BEGIN
                IF {$guarda}EXISTS (
                    SELECT 1 FROM `{$tabla}`
                     WHERE {$donde_sql}
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$texto}';
                END IF;
            END
            SQL;
    }

    // ------------------------------------------------------------- Internos

    /**
     * `status = 'approved'` -> `` a.`status` = 'approved' ``.
     *
     * Hace falta porque `solapes()` une la tabla consigo misma: sin alias, un
     * filtro que nombre una columna es ambiguo y MySQL lo rechaza con `1052`.
     *
     * Solo toca lo que esta FUERA de comillas. Sin eso, un filtro como
     * `tipo IN ('a','status')` acabaria con el literal `'status'` convertido en
     * `` a.`status` `` y el SQL no compilaria --es el mismo cuidado que se toma
     * `Restriccion::reescribirConNew`, por la misma razon--.
     */
    private static function prefijar(string $expresion, string $alias): string
    {
        $salida = '';
        $dentro = false;
        $largo = strlen($expresion);

        for ($i = 0; $i < $largo; $i++) {
            $c = $expresion[$i];

            if ($c === "'") {
                $dentro = !$dentro;
                $salida .= $c;

                continue;
            }

            if ($dentro) {
                $salida .= $c;

                continue;
            }

            // Fuera de comillas: se junta la palabra entera y se decide.
            if (preg_match('/[A-Za-z_]/', $c) === 1) {
                $palabra = '';
                while ($i < $largo && preg_match('/[A-Za-z0-9_]/', $expresion[$i]) === 1) {
                    $palabra .= $expresion[$i];
                    $i++;
                }
                $i--;

                // Una palabra seguida de `(` es una funcion, no una columna.
                $resto = ltrim(substr($expresion, $i + 1));
                $esFuncion = $resto !== '' && $resto[0] === '(';

                $salida .= (in_array(strtoupper($palabra), self::PALABRAS, true) || $esFuncion)
                    ? $palabra
                    : $alias.'.`'.$palabra.'`';

                continue;
            }

            $salida .= $c;
        }

        return $salida;
    }

    private static function nombreTrigger(string $nombre, string $sufijo): string
    {
        $base = mb_substr($nombre, 0, 64 - strlen($sufijo) - 4);

        return "tg_{$base}_{$sufijo}";
    }

    private static function escaparTexto(string $texto): string
    {
        return str_replace(['\\', "'"], ['\\\\', "''"], $texto);
    }

    private static function validarNombre(string $nombre): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,50}$/', $nombre) !== 1) {
            throw new InvalidArgumentException(
                "Nombre de regla invalido: '{$nombre}'. Minusculas, digitos y guion bajo, hasta 51 caracteres.",
            );
        }
    }

    /** @param list<string> $serie */
    private static function registrar(
        string $tabla,
        string $nombre,
        array $serie,
        ?string $donde,
        string $mensaje,
    ): void {
        if (!DB::getSchemaBuilder()->hasTable('schema_constraints')) {
            return;
        }

        DB::table('schema_constraints')->updateOrInsert(
            ['constraint_name' => $nombre],
            [
                'table_name' => $tabla,
                'expression' => 'sin solape por ('.implode(', ', $serie).')'
                    .($donde === null ? '' : " donde {$donde}"),
                'columns_involved' => implode(',', $serie),
                'message' => $mensaje,
                'mechanism' => 'trigger',
                'created_at' => now(),
            ],
        );
    }
}
