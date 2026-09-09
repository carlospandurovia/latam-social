<?php

/**
 * ¿Lo que el registro dice coincide con lo que el motor impone? (`T-110`)
 *
 * ### De dónde sale
 *
 * De un fallo medido, no imaginado. Una migración se cortó por una conexión
 * caída justo entre el `CREATE TRIGGER` y su anotación, y dejó `ck_lb_cta`
 * **aplicándose en la base y ausente de `schema_constraints`**. El servidor
 * habia ejecutado la sentencia; lo que se perdió fue la respuesta.
 *
 * Eso no da ningún error. La regla protege, el sistema no sabe que existe, y
 * `schema_constraints` --que es la verdad registrada que lee la pantalla de
 * Sistema (`DEC-307`)-- cuenta una menos para siempre. En una base de
 * desarrollo con 18 disparadores se ve a ojo. En produccion, con **824**, no.
 *
 * ### Los tres desajustes, y cuál es el peligroso
 *
 * | Qué | Gravedad |
 * |---|---|
 * | **Anotada sin imponer** | 🔴 el sistema se cree protegido y no lo está |
 * | **Impone sin anotar** | 🟠 protege, pero el inventario miente por lo bajo |
 * | **Anotada con otro mecanismo** | 🟠 la pantalla dice `CHECK` y hay disparador |
 *
 * La primera es la que puede costar dinero: alguien mira la lista de reglas,
 * ve la suya, y da por hecho que la base rechaza lo que en realidad admite.
 *
 * ### Por qué mira los DOS sentidos
 *
 * Un verificador que solo comprueba «lo anotado existe» habría dado verde el
 * dia del incidente, porque lo que faltaba era la anotación y no el disparador.
 * La comprobación tiene que ir en las dos direcciones o no comprueba nada
 * (`DEC-301`).
 *
 *   php tools/servidor/verificar-registro.php
 *
 * Sale con código 1 si algo no cuadra.
 */

declare(strict_types=1);

$raiz = dirname(__DIR__, 2);
require $raiz.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $raiz.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$base = (string) DB::selectOne('SELECT DATABASE() AS d')->d;
$version = (string) DB::selectOne('SELECT VERSION() AS v')->v;

echo "Base: {$base}\n";
echo "Motor: {$version}\n\n";

if (!Schema::hasTable('schema_constraints')) {
    fwrite(STDERR, "No existe `schema_constraints`: no hay registro contra el que comparar.\n");
    exit(1);
}

// --------------------------------------------------------- lo que hay dentro

$anotadas = DB::table('schema_constraints')
    ->orderBy('constraint_name')
    ->get(['constraint_name', 'table_name', 'mechanism']);

/** @var array<string, string> $disparadores nombre => tabla */
$disparadores = [];
foreach (DB::table('information_schema.triggers')
    ->where('trigger_schema', $base)
    ->get(['trigger_name', 'event_object_table']) as $t) {
    $disparadores[(string) $t->trigger_name] = (string) $t->event_object_table;
}

// `information_schema.check_constraints` no existe en MySQL 5.7. Que no exista
// NO es un fallo: es el motor que este proyecto sabe que no aplica `CHECK`. Se
// dice y se sigue, en vez de reventar con un 1146 que parece otra cosa.
$checks = [];
$hayCatalogoDeChecks = true;

try {
    // Solo estas dos columnas: MySQL 8 no tiene `TABLE_NAME` en esa vista y
    // MariaDB si. Pedir la que no esta seria un fallo por motor.
    foreach (DB::select(
        'SELECT constraint_name FROM information_schema.check_constraints WHERE constraint_schema = ?',
        [$base],
    ) as $c) {
        $checks[(string) $c->constraint_name] = true;
    }
} catch (\Throwable) {
    $hayCatalogoDeChecks = false;
}

echo 'Reglas anotadas: '.$anotadas->count()
    .'   disparadores en la base: '.count($disparadores)
    .'   restricciones CHECK: '.($hayCatalogoDeChecks ? (string) count($checks) : 'el motor no las tiene')
    ."\n\n";

// ------------------------------------------------- 1. anotadas sin imponer

$problemas = [];
$reconocidos = [];

foreach ($anotadas as $regla) {
    $nombre = (string) $regla->constraint_name;
    $ins = 'tg_'.$nombre.'_ins';
    $upd = 'tg_'.$nombre.'_upd';
    $tieneIns = isset($disparadores[$ins]);
    $tieneUpd = isset($disparadores[$upd]);
    $tieneCheck = isset($checks[$nombre]);

    if ($tieneIns) {
        $reconocidos[] = $ins;
    }
    if ($tieneUpd) {
        $reconocidos[] = $upd;
    }

    if ((string) $regla->mechanism === 'trigger') {
        if ($tieneIns && $tieneUpd) {
            continue;
        }

        $falta = match (true) {
            !$tieneIns && !$tieneUpd => 'no tiene ninguno de sus dos disparadores',
            !$tieneIns => 'le falta el de INSERT',
            default => 'le falta el de UPDATE',
        };

        $pista = Schema::hasTable((string) $regla->table_name)
            ? ''
            : ' --y su tabla ya no existe, asi que la fila sobra casi seguro--';

        $problemas[] = ['ROJO', $nombre, (string) $regla->table_name,
            "anotada como disparador y {$falta}: la base NO la esta imponiendo{$pista}"];

        continue;
    }

    // Anotada como `check`.
    if (!$hayCatalogoDeChecks) {
        // El registro dice `check` y el motor de hoy no los tiene. Es una base
        // que se anoto en otro motor --`DEC-307`--: no es un agujero, pero la
        // pantalla de Sistema estaria contando mal.
        $problemas[] = ['AMBAR', $nombre, (string) $regla->table_name,
            'anotada como CHECK y este motor no tiene CHECK: el registro viene de otra base'];

        continue;
    }

    if (!$tieneCheck) {
        $problemas[] = ['ROJO', $nombre, (string) $regla->table_name,
            'anotada como CHECK y el motor no tiene esa restriccion: NO se esta imponiendo'];
    }
}

// ------------------------------------------------- 2. imponen sin anotar
//
// Solo los generados. Los disparadores escritos a mano --`tg_ledger_no_update`,
// `tg_ds_forma_ins`…-- no salen de `Restriccion` y no tienen por que estar en
// `schema_constraints`: acusarlos seria el falso positivo de `DEC-301`.

foreach ($disparadores as $nombre => $tabla) {
    if (in_array($nombre, $reconocidos, true)) {
        continue;
    }

    if (preg_match('/^tg_(ck_[a-z0-9_]+)_(ins|upd)$/', $nombre, $coincidencia) !== 1) {
        continue;
    }

    $problemas[] = ['AMBAR', $coincidencia[1], $tabla,
        "el disparador `{$nombre}` impone una regla que NO esta anotada"];
}

// ------------------------------------------------------------------ informe

if ($problemas === []) {
    echo "\033[32mTodo lo que el registro dice, el motor lo impone. Y al reves.\033[0m\n";
    exit(0);
}

usort($problemas, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

foreach ($problemas as [$nivel, $nombre, $tabla, $texto]) {
    $color = $nivel === 'ROJO' ? "\033[31m" : "\033[33m";
    echo "  {$color}x\033[0m {$tabla}.{$nombre}\n      {$texto}\n";
}

$rojos = count(array_filter($problemas, static fn (array $p): bool => $p[0] === 'ROJO'));

echo "\n".count($problemas)." desajuste(s), {$rojos} de ellos graves.\n\n";

// **No se recomienda un remedio a secas.** Una regla que falta y una regla que
// SOBRA se ven identicas desde el motor --las dos son «anotada y sin
// disparadores»-- y los arreglos son opuestos: poner la que falta, o borrar el
// papel de la que se elimino a proposito. Rehacer una regla que el negocio
// mato la resucita, y eso puede ser una factura rechazada.
//
// Es la misma leccion de `DEC-327` y `DEC-332`: un consejo por omision en una
// herramienta de reparacion es una trampa con la firma de la casa.
echo "Antes de arreglar, la pregunta que decide --y que esta herramienta NO puede contestar--:\n";
echo "  .SIGUE DECLARADA esa regla en alguna migracion?\n\n";
echo "  SI  -> falta de verdad. Se rehace, sin deshacer nada:\n";
echo "         php tools/servidor/rehacer-reglas.php <ruta/de/su/migracion.php> --rehacer\n\n";
echo "  NO  -> se elimino a proposito y lo que sobra es la fila. Se quita con una\n";
echo "         migracion que llame a `Restriccion::quitar(<tabla>, <regla>)`, para que\n";
echo "         quede arreglado en TODAS las bases y con fecha.\n\n";
echo "  Para saberlo:  grep -rn \"'<regla>'\" app/Modules/*/Database/Migrations/\n";

exit(1);
