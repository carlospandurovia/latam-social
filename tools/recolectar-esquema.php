<?php
/**
 * Grabador de Blueprint.
 *
 * NO reimplementa Laravel: registra lo que cada migracion DECLARA (columnas,
 * tipos, nulabilidad, indices, claves foraneas) para poder contrastarlo con el
 * esquema SQL de referencia.
 *
 * Existe porque durante toda la Fase 2 se probo el SQL de referencia y se
 * verifico que las migraciones declaraban las mismas RESTRICCIONES, pero nunca
 * se comprobo que declararan las mismas COLUMNAS. Una columna que este en el
 * SQL y no en la migracion no la detecta ninguna prueba de restriccion: la
 * regla se aplica sobre una tabla que en la aplicacion real no tiene ese campo.
 *
 * Limite conocido y explicito: esto compara INTENCION declarada, no el DDL que
 * Laravel emite. Para lo segundo esta `php artisan esquema:contrastar`, que
 * corre contra la base ya migrada.
 */
declare(strict_types=1);

namespace App\Shared\Database { class Restriccion {
    public static function comprobacion(...$a): void {}
    public static function quitar(...$a): void {}
} }

// `Periodo` (3.10) tenia que estar aqui desde el primer dia de su existencia.
// Sin el doble, cualquier migracion que lo use muere con "Class not found" y
// las dos puertas que leen migraciones --`verificar-migraciones.py` y
// `verificar-ddl-crudo.py`-- se caen sin llegar a comprobar nada. Es el mismo
// hueco de grabador que dejo pasar `H-15`: la herramienta no sabia de algo que
// el codigo ya usaba.
//
// Se anota la DECLARACION, no el SQL. El SQL lo genera la clase de verdad y lo
// contrasta `tools/verificar-periodos.py`; reimplementarlo aqui seria repetir
// el error contra el que avisa `generar-triggers.py`: un doble que se equivoca
// igual que el original nunca encuentra nada.
namespace App\Shared\Database { class Periodo {
    public static function sinSolape(
        string $tabla, string $nombre, array $serie, string $mensaje,
        ?string $donde = null, array $columnasDonde = [],
        string $desde = 'valid_from', string $hasta = 'valid_to', string $clavePrimaria = 'id',
    ): void {
        \Recolector::$periodos[] = [
            'tabla' => $tabla, 'nombre' => $nombre, 'serie' => $serie,
            'mensaje' => $mensaje, 'donde' => $donde, 'columnasDonde' => $columnasDonde,
            'desde' => $desde, 'hasta' => $hasta, 'clavePrimaria' => $clavePrimaria,
        ];
    }
    public static function quitar(...$a): void {}
    public static function exigirSinSolapePrevio(...$a): void {}
    public static function solapes(...$a): array { return []; }
} }

namespace Illuminate\Database\Migrations { abstract class Migration {} }

namespace Illuminate\Support\Facades {
    class DB {
        public static function statement(string $s): void {
            \Recolector::$raw[] = $s;
            \Recolector::registrarDdlCrudo($s);
        }
        public static function unprepared(string $s): void {
            \Recolector::$raw[] = $s;
            \Recolector::registrarDdlCrudo($s);
        }
        // El doble del constructor de consultas. Encadena cualquier metodo
        // devolviendose a si mismo, PERO los que terminan una consulta
        // devuelven un escalar neutro. Antes devolvia `$this` siempre, y una
        // migracion que hiciera `->count()` para comprobar datos antes de
        // endurecer una columna reventaba aqui con "object could not be
        // converted to string" -- un error del GRABADOR que parecia un error de
        // la migracion.
        public static function table(string $t) { return new \ConsultaFalsa($t); }
        public static function selectOne(string $s) { return null; }
        // `DB::select()` devuelve una lista de filas. Una migracion que
        // comprueba el estado de los datos antes de endurecer --que es el
        // patron que se adopto en 000490-- la usa para lo que el constructor de
        // consultas no expresa bien, como un JOIN de una tabla consigo misma.
        public static function select(string $s, array $b = []): array { return []; }
        public static function scalar(string $s, array $b = []) { return null; }
        public static function raw(string $s): string { return $s; }
        public static function transaction(callable $cb) { return $cb(); }
    }
    class Schema {
        public static function create(string $tabla, callable $cb): void {
        \Recolector::$creadas[] = $tabla;
            \Recolector::$tablaActual = $tabla;
            \Recolector::$tablas[$tabla] ??= ['columnas'=>[], 'indices'=>[], 'unicos'=>[], 'fk'=>[]];
            $cb(new \Illuminate\Database\Schema\Blueprint($tabla));
        }
        public static function table(string $tabla, callable $cb): void {
            \Recolector::$tablaActual = $tabla;
            \Recolector::$tablas[$tabla] ??= ['columnas'=>[], 'indices'=>[], 'unicos'=>[], 'fk'=>[]];
            $cb(new \Illuminate\Database\Schema\Blueprint($tabla));
        }
        public static function dropIfExists(string $t): void {}
        public static function hasTable(string $t): bool { return true; }
        // Devolvia `true` siempre, y eso convertia en falso positivo cualquier
        // consulta protegida por un `hasColumn` -- justo el patron correcto que
        // se quiere premiar. El grabador SABE que columnas hay: que lo diga.
        public static function hasColumn(string $t, string $c): bool {
            if (isset(\Recolector::BASE_LARAVEL[$t]) && in_array($c, \Recolector::BASE_LARAVEL[$t], true)) {
                return true;
            }
            if (! isset(\Recolector::$tablas[$t]['columnas'])) {
                return true;   // tabla que no ha visto crear: no puede opinar
            }
            return isset(\Recolector::$tablas[$t]['columnas'][$c]);
        }
    }
}

namespace Illuminate\Database\Schema {

class Columna {
    public function __construct(public string $tabla, public string $nombre) {}
    public function nullable(bool $v = true): static {
        \Recolector::$tablas[$this->tabla]['columnas'][$this->nombre]['nullable'] = $v; return $this; }
    public function default($v): static {
        \Recolector::$tablas[$this->tabla]['columnas'][$this->nombre]['default'] = $v; return $this; }
    public function after(string $c): static { return $this; }
    public function unsigned(): static { return $this; }
    public function change(): static { return $this; }
    public function __call($n, $a): static { return $this; }
}

class ForaneaBuilder {
    private array $d;
    /**
     * `$columna` y `references()` aceptan ARRAY desde 7.3.
     *
     * Laravel los admite para declarar una foranea COMPUESTA --`foreign([a,b])
     * ->references([x,y])`-- y este doble solo aceptaba `string`. La migracion
     * 000720 lo reventaba con un TypeError, y el sintoma era «no pude leer las
     * migraciones»: una comprobacion entera caida por una firma de mas.
     *
     * Se normaliza a lista para que una foranea simple y una compuesta de una
     * sola columna se comparen igual.
     */
    public function __construct(public string $tabla, array|string $columna, ?string $nombre) {
        $this->d = ['columna'=>(array) $columna, 'nombre'=>$nombre, 'referencia'=>null, 'tabla_destino'=>null, 'onDelete'=>null];
    }
    public function references(array|string $c): static { $this->d['referencia'] = (array) $c; return $this->guardar(); }
    public function on(string $t): static { $this->d['tabla_destino'] = $t; return $this->guardar(); }
    public function restrictOnDelete(): static { $this->d['onDelete'] = 'RESTRICT'; return $this->guardar(); }
    public function cascadeOnDelete(): static { $this->d['onDelete'] = 'CASCADE'; return $this->guardar(); }
    public function nullOnDelete(): static { $this->d['onDelete'] = 'SET NULL'; return $this->guardar(); }
    public function __call($n, $a): static { return $this; }
    private function guardar(): static {
        $k = $this->d['nombre'] ?? ($this->tabla.'_'.implode('_', $this->d['columna']).'_foreign');
        \Recolector::$tablas[$this->tabla]['fk'][$k] = $this->d; return $this; }
}

class Blueprint {
    public function __construct(public string $tabla) {}

    private function col(string $nombre, string $tipo): Columna {
        \Recolector::$tablas[$this->tabla]['columnas'][$nombre] =
            ['tipo'=>$tipo, 'nullable'=>false, 'default'=>null];
        return new Columna($this->tabla, $nombre);
    }
    public function id(string $n = 'id'): Columna { return $this->col($n, 'BIGINT UNSIGNED'); }
    public function foreignId(string $n): Columna { return $this->col($n, 'BIGINT UNSIGNED'); }
    public function unsignedBigInteger(string $n): Columna { return $this->col($n, 'BIGINT UNSIGNED'); }
    public function unsignedSmallInteger(string $n): Columna { return $this->col($n, 'SMALLINT UNSIGNED'); }
    public function unsignedTinyInteger(string $n): Columna { return $this->col($n, 'TINYINT UNSIGNED'); }
    public function unsignedInteger(string $n): Columna { return $this->col($n, 'INT UNSIGNED'); }
    public function integer(string $n): Columna { return $this->col($n, 'INT'); }
    public function boolean(string $n): Columna { return $this->col($n, 'TINYINT(1)'); }
    public function char(string $n, int $l = 255): Columna { return $this->col($n, "CHAR($l)"); }
    public function string(string $n, int $l = 255): Columna { return $this->col($n, "VARCHAR($l)"); }
    public function text(string $n): Columna { return $this->col($n, 'TEXT'); }
    public function longText(string $n): Columna { return $this->col($n, 'LONGTEXT'); }
    public function binary(string $n, ?int $l = null, bool $fijo = false): Columna {
        // Laravel 11+ acepta longitud: binary($col, $len, $fijo) -> VARBINARY/BINARY.
        return $this->col($n, $l === null ? 'BLOB' : ($fijo ? "BINARY($l)" : "VARBINARY($l)")); }
    public function date(string $n): Columna { return $this->col($n, 'DATE'); }
    public function dateTime(string $n, int $p = 0): Columna { return $this->col($n, $p ? "DATETIME($p)" : 'DATETIME'); }
    public function timestamp(string $n, int $p = 0): Columna { return $this->col($n, $p ? "TIMESTAMP($p)" : 'TIMESTAMP'); }
    public function decimal(string $n, int $t = 8, int $p = 2): Columna { return $this->col($n, "DECIMAL($t,$p)"); }
    public function json(string $n): Columna { return $this->col($n, 'JSON'); }

    public function index($cols, ?string $nombre = null): void {
        \Recolector::$tablas[$this->tabla]['indices'][$nombre ?? '?'] = (array) $cols; }
    public function unique($cols, ?string $nombre = null): void {
        \Recolector::$tablas[$this->tabla]['unicos'][$nombre ?? '?'] = (array) $cols; }
    public function primary($cols, ?string $nombre = null): void {
        \Recolector::$tablas[$this->tabla]['indices']['PRIMARY'] = (array) $cols; }
    public function foreign(array|string $col, ?string $nombre = null): ForaneaBuilder {
        return new ForaneaBuilder($this->tabla, $col, $nombre); }
    // Sin estos, una migracion que sustituye una foranea por otra deja las DOS
    // grabadas y el esquema reconstruido dice algo que la base no dice.
    public function dropForeign($nombre): void {
        unset(\Recolector::$tablas[$this->tabla]['fk'][is_array($nombre) ? '?' : $nombre]); }
    public function dropUnique($nombre): void {
        unset(\Recolector::$tablas[$this->tabla]['unicos'][is_array($nombre) ? '?' : $nombre]); }
    public function dropIndex($nombre): void {
        unset(\Recolector::$tablas[$this->tabla]['indices'][is_array($nombre) ? '?' : $nombre]); }
    public function dropColumn($c): void {
        foreach ((array) $c as $x) unset(\Recolector::$tablas[$this->tabla]['columnas'][$x]); }
    public function __call($n, $a) { return new Columna($this->tabla, (string)($a[0] ?? '?')); }
}
}

namespace {

/**
 * Doble del constructor de consultas.
 *
 * Encadena cualquier metodo devolviendose a si mismo, PERO los que terminan una
 * consulta devuelven un escalar neutro. Antes devolvia `$this` siempre, y una
 * migracion que hiciera `->count()` reventaba aqui con "object could not be
 * converted to string" -- un error del GRABADOR que parecia de la migracion.
 *
 * Y ademas ANOTA QUE COLUMNAS CONSULTA (H-15).
 *
 * Por que. La migracion 000490 comprobaba el estado de los datos antes de
 * endurecer la tabla, y una de esas comprobaciones miraba `closed_at`... una
 * columna que anade la propia migracion doce lineas mas abajo. En una base
 * recien migrada eso es
 *
 *   SQLSTATE[42S22]: Unknown column 'closed_at' in 'where clause'
 *
 * y como pasaba en `setUp()`, fallaron las 18 pruebas a la vez. Ninguna de las
 * siete herramientas lo vio: el SQL de referencia no ejecuta migraciones, y
 * `verificar-ddl-crudo.py` solo ejecuta el SQL literal, no las llamadas al
 * constructor de consultas. Este era el ultimo hueco grande de la cadena.
 */
class ConsultaFalsa {
    private const ESCALARES = [
        'count' => 0, 'sum' => 0, 'exists' => false, 'doesntExist' => true,
        'value' => null, 'first' => null, 'max' => null, 'min' => null,
        'insertGetId' => 0, 'insert' => true, 'update' => 0, 'delete' => 0,
    ];

    /** Metodos cuyo primer argumento de texto es un NOMBRE DE COLUMNA. */
    private const POR_COLUMNA = [
        'where' => true, 'orWhere' => true, 'whereNot' => true,
        'whereNull' => true, 'orWhereNull' => true,
        'whereNotNull' => true, 'orWhereNotNull' => true,
        'whereIn' => true, 'orWhereIn' => true, 'whereNotIn' => true,
        'whereBetween' => true, 'whereDate' => true, 'whereColumn' => true,
        'orderBy' => true, 'orderByDesc' => true, 'groupBy' => true,
        'value' => true, 'pluck' => true, 'max' => true, 'min' => true, 'sum' => true,
    ];

    public function __construct(private string $tabla) {}

    public function __call($n, $a) {
        // SOLO el primer argumento. La primera version miraba todos, y
        // `whereIn('status', ['rejected','disabled'])` denunciaba dos columnas
        // llamadas `rejected` y `disabled` que son VALORES. Un verificador que
        // grita por nada ensena a ignorarlo, que es peor que no tenerlo.
        if (isset(self::POR_COLUMNA[$n]) && isset($a[0]) && is_string($a[0])) {
            \Recolector::comprobarColumna($this->tabla, $a[0], $n);
        }

        // `groupBy('a', 'b')` si acepta varias columnas.
        if ($n === 'groupBy') {
            foreach ($a as $arg) {
                if (is_string($arg)) {
                    \Recolector::comprobarColumna($this->tabla, $arg, $n);
                }
            }
        }

        // `where(fn ($q) => $q->whereNull('x'))`: si no se INVOCA la clausura,
        // las condiciones anidadas no se ven. Y era justamente ahi donde estaba
        // la columna inexistente de 000490.
        foreach ($a as $arg) {
            if ($arg instanceof \Closure) {
                $arg($this);
            }
        }

        // `update(['col' => valor])` y `first(['col', ...])` tambien nombran
        // columnas, pero en la primera posicion y como array.
        if (in_array($n, ['update', 'first', 'get', 'select'], true) && isset($a[0]) && is_array($a[0])) {
            foreach ($a[0] as $clave => $valor) {
                $nombre = is_string($clave) ? $clave : $valor;
                if (is_string($nombre)) {
                    \Recolector::comprobarColumna($this->tabla, $nombre, $n);
                }
            }
        }

        if ($n === 'get' || $n === 'pluck') {
            return new \ColeccionVacia();
        }

        return array_key_exists($n, self::ESCALARES) ? self::ESCALARES[$n] : $this;
    }
}

/** Doble de una Coleccion vacia: lo justo para que se pueda encadenar. */
class ColeccionVacia implements Countable, IteratorAggregate {
    public function count(): int { return 0; }
    public function isEmpty(): bool { return true; }
    public function isNotEmpty(): bool { return false; }
    public function all(): array { return []; }
    public function toArray(): array { return []; }
    public function getIterator(): Traversable { return new ArrayIterator([]); }
    public function __call($n, $a) { return $this; }
}

// Las migraciones leen configuracion (`config('latam.pagos...')`). Aqui no hay
// contenedor de Laravel, asi que devuelven el valor por defecto: el grabador
// solo necesita saber QUE DDL se emite, no con que datos.
if (! function_exists('config')) {
    function config($clave = null, $porDefecto = null) { return $porDefecto; }
}
if (! function_exists('env')) {
    function env($clave, $porDefecto = null) { return $porDefecto; }
}

class Recolector {
    public static array $tablas = [];
    public static array $raw = [];
    /** Tablas creadas con Schema::create por la migracion que se esta grabando. */
    public static array $creadas = [];
    /** Columnas que una migracion consulta ANTES de que existan. Ver H-15. */
    public static array $avisos = [];
    /** Reglas de periodo declaradas con `Periodo::sinSolape` (3.10). */
    public static array $periodos = [];
    /** Migracion que se esta grabando, para poder decir cual falla. */
    public static string $migracionActual = '';
    public static string $tablaActual = '';

    /**
     * Columnas que la migracion base de Laravel crea y que este grabador nunca
     * ve, porque no vive en app/Modules. Sin esto, cualquier consulta sobre
     * `users` seria un falso positivo -- y un verificador que se equivoca
     * ensena a ignorar sus avisos.
     */
    public const BASE_LARAVEL = [
        'users' => ['id', 'name', 'email', 'email_verified_at', 'password',
                    'remember_token', 'created_at', 'updated_at'],
        'migrations' => ['id', 'migration', 'batch'],
    ];

    /**
     * Una columna que se consulta antes de existir es `ERROR 1054` en cuanto la
     * migracion corre sobre una base limpia. Aqui se caza en seco.
     */
    public static function comprobarColumna(string $tabla, string $columna, string $metodo): void
    {
        // Expresiones, no columnas: `COUNT(*)`, `id as x`.
        if ($columna === '' || str_contains($columna, '(') || str_contains($columna, ' ')) {
            return;
        }
        $columna = str_contains($columna, '.') ? substr($columna, strrpos($columna, '.') + 1) : $columna;
        if ($columna === '*') {
            return;
        }
        if (isset(self::BASE_LARAVEL[$tabla]) && in_array($columna, self::BASE_LARAVEL[$tabla], true)) {
            return;
        }
        // Tabla que el grabador no ha visto crear: no puede opinar.
        if (! isset(self::$tablas[$tabla]['columnas'])) {
            return;
        }
        if (isset(self::$tablas[$tabla]['columnas'][$columna])) {
            return;
        }
        self::$avisos[] = [
            'migracion' => self::$migracionActual,
            'tabla' => $tabla,
            'columna' => $columna,
            'metodo' => $metodo,
        ];
    }

    /**
     * Las columnas que se anaden o quitan con SQL crudo hay que registrarlas EN
     * EL MOMENTO. El post-proceso del final llega tarde para este chequeo: la
     * pregunta es que existia cuando la migracion hizo la consulta.
     */
    public static function registrarDdlCrudo(string $sql): void
    {
        if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+COLUMN\s+`?(\w+)`?/is', $sql, $m)) {
            self::$tablas[$m[1]]['columnas'][$m[2]] ??= ['tipo' => '?', 'nullable' => true, 'default' => null];
        }
        if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+DROP\s+COLUMN\s+`?(\w+)`?/is', $sql, $m)) {
            unset(self::$tablas[$m[1]]['columnas'][$m[2]]);
        }
    }
}

// Igual que en generar-triggers.php: aqui habia una ruta ABSOLUTA del entorno
// de trabajo. Fuera de esa maquina no apuntaba a nada.
// `--crudo` cambia lo que se emite: en vez del esquema reconstruido, la lista
// de sentencias SQL literales de cada migracion, separadas por up() y down().
// La usa tools/verificar-ddl-crudo.py para EJECUTARLAS de verdad contra un
// motor. Ver H-08: este grabador nunca ejecuto nada, y por eso un ALTER que
// MariaDB acepta y MySQL 8 rechaza llego hasta la maquina de desarrollo.
$soloCrudo = in_array('--crudo', $argv, true);
$argv = array_values(array_filter($argv, fn ($a) => $a !== '--crudo'));

$dir = $argv[1] ?? null;
if ($dir === null) {
    foreach ([__DIR__.'/../app/Modules', __DIR__.'/../stage/app/Modules'] as $candidato) {
        if (is_dir($candidato)) {
            $dir = $candidato;
            break;
        }
    }
}
if ($dir === null || ! is_dir($dir)) {
    fwrite(STDERR, "No encuentro el directorio de modulos. Pase la ruta como argumento.\n");
    exit(1);
}
$archivos = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
    if ($f->isFile() && str_contains($f->getPathname(), 'Migrations') && $f->getExtension() === 'php') {
        $archivos[] = $f->getPathname();
    }
}
sort($archivos);   // el mismo orden que Laravel: por timestamp del nombre

if ($soloCrudo) {
    $salida = [];
    foreach ($archivos as $a) {
        $m = require $a;
        Recolector::$migracionActual = basename($a);
        Recolector::$raw = [];
        Recolector::$creadas = [];
        $m->up();
        $arriba = Recolector::$raw;
        $creadas = Recolector::$creadas;
        Recolector::$raw = [];
        // Un `down()` que revienta al GRABARSE es un fallo por si mismo: quiere
        // decir que la vuelta atras nunca se ha mirado.
        try {
            $m->down();
            $abajo = Recolector::$raw;
            $error = null;
        } catch (\Throwable $e) {
            $abajo = [];
            $error = $e->getMessage();
        }
        if ($arriba === [] && $abajo === []) {
            continue;
        }
        $salida[] = ['migracion' => basename($a), 'up' => $arriba, 'down' => $abajo,
                     'crea' => $creadas, 'error' => $error];
    }
    echo json_encode($salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

foreach ($archivos as $a) {
    $m = require $a;
    Recolector::$migracionActual = basename($a);
    $m->up();
}

// Indices, unicos y foraneas declarados con SQL crudo. Sin esto el contraste
// daba 30 falsos positivos: todos los indices sobre columnas generadas se
// anaden con ALTER TABLE, porque Blueprint no sabe declarar una columna
// generada.
foreach (Recolector::$raw as $sql) {
    if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+UNIQUE\s+(?:KEY|INDEX)\s+`?(\w+)`?\s*\(([^)]*)\)/is', $sql, $m)) {
        Recolector::$tablas[$m[1]]['unicos'][$m[2]] = array_map('trim', explode(',', str_replace('`','',$m[3])));
    }
    if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(?:KEY|INDEX)\s+`?(\w+)`?\s*\(([^)]*)\)/is', $sql, $m)) {
        Recolector::$tablas[$m[1]]['indices'][$m[2]] = array_map('trim', explode(',', str_replace('`','',$m[3])));
    }
    if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+CONSTRAINT\s+`?(\w+)`?\s+FOREIGN\s+KEY\s*\(([^)]*)\)/is', $sql, $m)) {
        Recolector::$tablas[$m[1]]['fk'][$m[2]] = ['columna'=>trim(str_replace('`','',$m[3])),
            'nombre'=>$m[2], 'referencia'=>null, 'tabla_destino'=>null, 'onDelete'=>null];
    }
    if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+DROP\s+INDEX\s+`?(\w+)`?/is', $sql, $m)) {
        unset(Recolector::$tablas[$m[1]]['unicos'][$m[2]], Recolector::$tablas[$m[1]]['indices'][$m[2]]);
    }
}

// Columnas generadas: se crean con SQL crudo, hay que sacarlas de ahi.
foreach (Recolector::$raw as $sql) {
    if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+COLUMN\s+`?(\w+)`?\s+(.+?)\s+GENERATED/is', $sql, $m)) {
        Recolector::$tablas[$m[1]]['columnas'][$m[2]] =
            ['tipo'=>strtoupper(trim($m[3])), 'nullable'=>true, 'default'=>null, 'generada'=>true];
    }
}

// `MODIFY <col> <tipo> [NOT] NULL`: endurecer o relajar una columna que ya
// existe. Sin esto, el grabador se quedaba con la nulabilidad que declaro la
// migracion ORIGINAL y el verificador denunciaba una discrepancia que no
// existia -- el peor tipo de falso positivo, porque desgasta la confianza en la
// herramienta justo cuando acierta.
foreach (Recolector::$raw as $sql) {
    if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+MODIFY\s+(?:COLUMN\s+)?`?(\w+)`?\s+(.+)$/is', $sql, $m)) {
        [$tabla, $columna, $resto] = [$m[1], $m[2], strtoupper(trim($m[3]))];
        if (isset(Recolector::$tablas[$tabla]['columnas'][$columna])) {
            Recolector::$tablas[$tabla]['columnas'][$columna]['nullable'] = ! str_contains($resto, 'NOT NULL');
            // Un MODIFY tambien puede cambiar el tipo, no solo la nulabilidad.
            $tipo = trim((string) preg_replace('/\s+(NOT\s+)?NULL\b.*$/i', '', $resto));
            if ($tipo !== '') {
                Recolector::$tablas[$tabla]['columnas'][$columna]['tipo'] = $tipo;
            }
        }
    }
}

echo json_encode([
    'tablas'      => Recolector::$tablas,
    'migraciones' => count($archivos),
    'raw'         => count(Recolector::$raw),
    'avisos'      => Recolector::$avisos,
    'periodos'    => Recolector::$periodos,
], JSON_PRETTY_PRINT);
}
