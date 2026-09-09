<?php

/**
 * Termina las reglas que una migración cortada dejó a medias.
 *
 * ### Por qué existe
 *
 * `Restriccion::comprobacion()` hace tres cosas por regla --crear el disparador
 * de INSERT, el de UPDATE, y anotarla en `schema_constraints`-- y **no es
 * idempotente**: si la conexion se cae entre la primera y la segunda, volver a
 * lanzar la migracion se estrella contra el disparador que si se creo.
 *
 * Deshacer la migracion entera para rehacerla no siempre es una opcion: un
 * `down()` que reconstruye columnas soltadas las devuelve VACIAS, y el `up()`
 * de detras puede reventar sobre los datos que quedaron (`T-109`).
 *
 * Asi que esto hace lo minimo: mira regla por regla que falta, y **solo toca
 * las incompletas**. `Restriccion::quitar()` si es idempotente --`DROP TRIGGER
 * IF EXISTS` de los dos-- asi que quitar y volver a poner una regla a medias
 * deja exactamente lo que la migracion habria dejado.
 *
 * ### Las declaraciones salen de la migracion, no de aqui
 *
 * Se leen del propio archivo por reflexion. Copiarlas aqui seria tener las
 * reglas escritas en dos sitios, y la copia de la herramienta de reparacion es
 * justo la que nadie volveria a mirar.
 *
 *   php tools/servidor/rehacer-reglas.php app/Modules/.../2026_09_03_000400_....php
 *   php tools/servidor/rehacer-reglas.php app/Modules/.../2026_09_03_000400_....php --rehacer
 *
 * Sin `--rehacer` no escribe nada.
 */

declare(strict_types=1);

$raiz = dirname(__DIR__, 2);
require $raiz.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $raiz.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Shared\Database\Restriccion;
use Illuminate\Support\Facades\DB;

$relativa = $argv[1] ?? '';
$rehacer = in_array('--rehacer', $argv, true);

if ($relativa === '') {
    fwrite(STDERR, "Falta la ruta de la migracion.\n");
    exit(1);
}

$ruta = str_starts_with($relativa, '/') || preg_match('/^[A-Za-z]:/', $relativa) === 1
    ? $relativa
    : $raiz.'/'.ltrim(str_replace('\\', '/', $relativa), '/');

if (!is_file($ruta)) {
    fwrite(STDERR, "No existe: {$ruta}\n");
    exit(1);
}

// La migracion devuelve una clase anonima. `restricciones()` es privada a
// proposito --nadie de fuera tiene por que llamarla-- y esta herramienta es
// justamente el «nadie» que hace falta una vez.
$migracion = require $ruta;
$reflexion = new ReflectionClass($migracion);

if (!$reflexion->hasMethod('restricciones')) {
    fwrite(STDERR, "Esa migracion no declara restricciones: no hay nada que rehacer.\n");
    exit(1);
}

$metodo = $reflexion->getMethod('restricciones');
$metodo->setAccessible(true);
/** @var list<array{0:string,1:string,2:string,3:list<string>,4:string}> $reglas */
$reglas = $metodo->invoke(null);

$base = (string) DB::selectOne('SELECT DATABASE() AS d')->d;
$tablas = array_values(array_unique(array_column($reglas, 0)));

echo "Base: {$base}\n";
echo 'Migracion: '.basename($ruta)."\n";
echo 'Tablas que toca: '.implode(', ', $tablas)."\n\n";

// Una sola consulta para todos los disparadores: son 60 viajes de ida y vuelta
// los que trajeron el problema, no hace falta añadir uno por regla.
$disparadores = DB::table('information_schema.triggers')
    ->where('trigger_schema', $base)
    ->whereIn('event_object_table', $tablas)
    ->pluck('trigger_name')
    ->map(static fn ($n): string => (string) $n)
    ->all();

$registradas = DB::table('schema_constraints')
    ->whereIn('table_name', $tablas)
    ->pluck('constraint_name')
    ->map(static fn ($n): string => (string) $n)
    ->all();

$incompletas = [];
$reconocidos = [];

printf("  %-18s  %-4s  %-4s  %-10s  %s\n", 'REGLA', 'INS', 'UPD', 'ANOTADA', 'ESTADO');
echo '  '.str_repeat('-', 66)."\n";

foreach ($reglas as [$tabla, $nombre]) {
    $mios = array_values(array_filter(
        $disparadores,
        static fn (string $t): bool => str_starts_with($t, 'tg_'.$nombre.'_'),
    ));
    $reconocidos = array_merge($reconocidos, $mios);

    $ins = in_array('tg_'.$nombre.'_ins', $mios, true);
    $upd = in_array('tg_'.$nombre.'_upd', $mios, true);
    $anotada = in_array($nombre, $registradas, true);
    $entera = $ins && $upd && $anotada;

    if (!$entera) {
        $incompletas[] = $nombre;
    }

    printf("  %-18s  %-4s  %-4s  %-10s  %s\n", $nombre,
        $ins ? 'si' : 'NO', $upd ? 'si' : 'NO', $anotada ? 'si' : 'NO',
        $entera ? 'completa' : '<-- INCOMPLETA');
}

// Los que no son de ninguna regla de esta migracion. Pueden ser legitimos --de
// otra migracion sobre la misma tabla-- o restos de una regla que se quito a
// medias. Se enseñan SIEMPRE en vez de callarlos: un numero de disparadores que
// no cuadra y nadie explica es como empiezan los misterios.
$ajenos = array_values(array_diff($disparadores, $reconocidos));

echo "\n  Disparadores en esas tablas: ".count($disparadores)
    .'  (de esta migracion: '.count($reconocidos).', ajenos: '.count($ajenos).")\n";

if ($ajenos !== []) {
    echo "  Ajenos --de otra migracion sobre la misma tabla, o restos--:\n";
    foreach ($ajenos as $t) {
        $suRegla = preg_replace('/^tg_|_(ins|upd)$/', '', $t);
        $anotada = in_array((string) $suRegla, $registradas, true);
        echo "    {$t}".($anotada ? '' : "   <-- SIN regla anotada")."\n";
    }
}

if ($incompletas === []) {
    echo "\nTodas las reglas de esta migracion estan enteras. No hay nada que rehacer.\n";
    echo "Si la migracion sigue sin apuntarse, ese es el ultimo paso.\n";
    exit(0);
}

echo "\nIncompletas: ".implode(', ', $incompletas)."\n";

if (!$rehacer) {
    echo "No se ha tocado nada. Con `--rehacer` se quitan y se vuelven a poner SOLO esas.\n";
    exit(0);
}

echo "\nRehaciendo...\n";

foreach ($reglas as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
    if (!in_array($nombre, $incompletas, true)) {
        continue;
    }

    // Quitar primero: es idempotente --`DROP TRIGGER IF EXISTS` de los dos y
    // borrado del registro-- asi que deja el mismo punto de partida tanto si la
    // regla estaba a medias como si no estaba en absoluto.
    Restriccion::quitar($tabla, $nombre);
    Restriccion::comprobacion(
        tabla: $tabla, nombre: $nombre, expresion: $expresion,
        columnas: $columnas, mensaje: $mensaje,
    );

    echo "  {$nombre}  puesta\n";
}

echo "\nHecho. Vuelve a correr esta herramienta SIN `--rehacer` para comprobarlo.\n";
