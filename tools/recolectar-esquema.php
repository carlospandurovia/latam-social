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

namespace Illuminate\Database\Migrations { abstract class Migration {} }

namespace Illuminate\Support\Facades {
    class DB {
        public static function statement(string $s): void { \Recolector::$raw[] = $s; }
        public static function unprepared(string $s): void { \Recolector::$raw[] = $s; }
        public static function table(string $t) { return new class { 
            public function __call($n, $a) { return $this; } }; }
        public static function selectOne(string $s) { return null; }
    }
    class Schema {
        public static function create(string $tabla, callable $cb): void {
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
        public static function hasColumn(string $t, string $c): bool { return true; }
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
    public function __construct(public string $tabla, string $columna, ?string $nombre) {
        $this->d = ['columna'=>$columna, 'nombre'=>$nombre, 'referencia'=>null, 'tabla_destino'=>null, 'onDelete'=>null];
    }
    public function references(string $c): static { $this->d['referencia'] = $c; return $this->guardar(); }
    public function on(string $t): static { $this->d['tabla_destino'] = $t; return $this->guardar(); }
    public function restrictOnDelete(): static { $this->d['onDelete'] = 'RESTRICT'; return $this->guardar(); }
    public function cascadeOnDelete(): static { $this->d['onDelete'] = 'CASCADE'; return $this->guardar(); }
    public function nullOnDelete(): static { $this->d['onDelete'] = 'SET NULL'; return $this->guardar(); }
    public function __call($n, $a): static { return $this; }
    private function guardar(): static {
        $k = $this->d['nombre'] ?? ($this->tabla.'_'.$this->d['columna'].'_foreign');
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
    public function foreign(string $col, ?string $nombre = null): ForaneaBuilder {
        return new ForaneaBuilder($this->tabla, $col, $nombre); }
    public function dropColumn($c): void {
        foreach ((array) $c as $x) unset(\Recolector::$tablas[$this->tabla]['columnas'][$x]); }
    public function __call($n, $a) { return new Columna($this->tabla, (string)($a[0] ?? '?')); }
}
}

namespace {
class Recolector {
    public static array $tablas = [];
    public static array $raw = [];
    public static string $tablaActual = '';
}

$dir = $argv[1] ?? '/root/proyecto/stage/app/Modules';
$archivos = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
    if ($f->isFile() && str_contains($f->getPathname(), 'Migrations') && $f->getExtension() === 'php') {
        $archivos[] = $f->getPathname();
    }
}
sort($archivos);   // el mismo orden que Laravel: por timestamp del nombre
foreach ($archivos as $a) {
    $m = require $a;
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

echo json_encode([
    'tablas'      => Recolector::$tablas,
    'migraciones' => count($archivos),
    'raw'         => count(Recolector::$raw),
], JSON_PRETTY_PRINT);
}
