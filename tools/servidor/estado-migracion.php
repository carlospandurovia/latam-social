<?php

/**
 * ¿En qué estado quedó una migración que se cortó a mitad?
 *
 * ### Para qué
 *
 * Una migración que falla **no se apunta** en la tabla `migrations`: para
 * Laravel no ha ocurrido, aunque medio esquema diga lo contrario. Volver a
 * lanzar `migrate` la reintenta desde la primera línea y se estrella contra lo
 * que sí llegó a hacerse --«table already exists»-- con un mensaje que acusa a
 * la tabla y no a lo que pasó.
 *
 * Esta herramienta hace dos cosas y las separa a propósito:
 *
 * 1. **Mira** --sin tocar nada-- qué hay de esa migración dentro de la base.
 * 2. Con `--apuntar`, escribe su fila en `migrations` **en un lote propio**,
 *    que es lo que permite deshacerla con `migrate:rollback --step=1` sin
 *    llevarse por delante las que sí terminaron.
 *
 * El lote propio no es un detalle: si se apunta en el mismo lote que sus
 * vecinas, un `rollback` deshace las cuatro.
 *
 *   php tools/servidor/estado-migracion.php 2026_09_03_000400_las_secciones_de_la_portada
 *   php tools/servidor/estado-migracion.php 2026_09_03_000400_las_secciones_de_la_portada --apuntar
 *
 * Sale con código 1 si la migración ya está apuntada --entonces no hay nada
 * que reparar-- o si no se le da nombre.
 */

declare(strict_types=1);

$raiz = dirname(__DIR__, 2);
require $raiz.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $raiz.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$nombre = $argv[1] ?? '';
$apuntar = in_array('--apuntar', $argv, true);

if ($nombre === '') {
    fwrite(STDERR, "Falta el nombre de la migracion, sin `.php`.\n");
    exit(1);
}

$base = (string) DB::selectOne('SELECT DATABASE() AS d')->d;
$anfitrion = (string) config('database.connections.'.config('database.default').'.host');

echo "Base: {$base}  ({$anfitrion})\n\n";

// ------------------------------------------------------- lo que dice Laravel

$fila = DB::table('migrations')->where('migration', $nombre)->first();
$ultimas = DB::table('migrations')->orderByDesc('id')->limit(5)->get();
$maximo = (int) DB::table('migrations')->max('batch');

echo "Ultimas migraciones apuntadas:\n";
foreach ($ultimas as $u) {
    echo sprintf("  lote %-3d  %s\n", $u->batch, $u->migration);
}

echo "\nLote mas alto: {$maximo}\n";
echo 'Esta migracion: '.($fila === null
    ? "NO apuntada  <-- se corto a mitad\n"
    : "ya apuntada en el lote {$fila->batch}: no hay nada que reparar\n");

// ------------------------------------------------- lo que dice de verdad la base
//
// Se pregunta por las huellas que deja `las_secciones_de_la_portada`, que son
// las que dicen POR DONDE se cortó. Para otra migracion, este bloque no aplica
// y se dice en vez de callarse: una herramienta que no mira nada no debe
// parecerse a una que no encontro nada.

if (str_contains($nombre, 'las_secciones_de_la_portada')) {
    echo "\nHuellas de esta migracion en el esquema:\n";

    $huellas = [
        'tabla landing_sections' => Schema::hasTable('landing_sections'),
        'landing_blocks.landing_section_id' => Schema::hasColumn('landing_blocks', 'landing_section_id'),
        'landing_blocks.cta_url' => Schema::hasColumn('landing_blocks', 'cta_url'),
        'landing_blocks.landing_page_id (deberia haberse ido)' => Schema::hasColumn('landing_blocks', 'landing_page_id'),
        'landing_blocks.kind (deberia haberse ido)' => Schema::hasColumn('landing_blocks', 'kind'),
    ];

    foreach ($huellas as $que => $hay) {
        echo sprintf("  %-45s %s\n", $que, $hay ? 'si' : 'no');
    }

    // Las reglas del bucle final, que es donde se corto. `schema_constraints`
    // solo se escribe DESPUES de crear los dos disparadores, asi que la ultima
    // registrada dice exactamente hasta donde llego.
    $reglas = DB::table('schema_constraints')
        ->whereIn('table_name', ['landing_sections', 'landing_blocks'])
        ->orderBy('id')->pluck('constraint_name');

    echo "\n  Reglas registradas ({$reglas->count()}):\n";
    echo $reglas->isEmpty() ? "    ninguna\n" : '    '.$reglas->implode(', ')."\n";

    $disparadores = DB::table('information_schema.triggers')
        ->where('trigger_schema', $base)
        ->whereIn('event_object_table', ['landing_sections', 'landing_blocks'])
        ->count();

    echo "  Disparadores en esas dos tablas: {$disparadores}\n";

    // --------------------------------------------------------------- el veto
    //
    // Esta es la pregunta que decide si se puede reparar con un `rollback`.
    //
    // El `down()` de esta migracion devuelve `landing_page_id` **vacia** --lo
    // dice en su propio comentario: los datos no se devuelven-- y el `up()`
    // siguiente hace `INSERT ... SELECT DISTINCT landing_page_id ... FROM
    // landing_blocks`. Con filas dentro, eso intenta meter un NULL en una
    // columna NOT NULL y revienta con un 1048 a mitad de la reparacion, que es
    // el peor sitio posible para reventar.
    //
    // Con la tabla vacia no hay nada que seleccionar y el camino esta limpio.

    $bloques = Schema::hasTable('landing_blocks') ? DB::table('landing_blocks')->count() : 0;
    $secciones = Schema::hasTable('landing_sections') ? DB::table('landing_sections')->count() : 0;

    echo "\n  Filas en landing_blocks:   {$bloques}\n";
    echo "  Filas en landing_sections: {$secciones}\n";

    echo "\n  ¿Se puede reparar con rollback? ";
    echo $bloques === 0
        ? "SI. `landing_blocks` esta vacia, asi que el `up()` no tiene nada que mudar.\n"
        : "NO. Con {$bloques} bloques dentro, el `up()` intentaria mudarlos sin pagina y "
          ."reventaria con un 1048.\n     Hay que rehacer solo las reglas que faltan, sin deshacer nada.\n";
}

// ------------------------------------------------------------------ apuntar

if (!$apuntar) {
    echo "\nNo se ha tocado nada. Con `--apuntar` se escribe la fila en su propio lote.\n";
    exit(0);
}

if ($fila !== null) {
    fwrite(STDERR, "\nYa estaba apuntada. No se hace nada.\n");
    exit(1);
}

$lote = $maximo + 1;
DB::table('migrations')->insert(['migration' => $nombre, 'batch' => $lote]);

echo "\nApuntada en el lote {$lote}, ella sola.\n\n";

// El siguiente paso NO es siempre el mismo, y decirlo a secas seria repetir el
// error que hizo falta arreglar: un `rollback` en un bloque de copiar y pegar,
// debajo de un «si sale lo que espero». Aqui ya se sabe cual de los dos toca
// --el bloque de arriba lo acaba de calcular-- asi que se dice el que toca y
// solo ese.
if (isset($bloques) && $bloques > 0) {
    echo "SIGUIENTE PASO --y NO es el rollback--:\n";
    echo "  Con {$bloques} filas dentro, deshacer esta migracion revienta al volver a subirla\n";
    echo "  (`T-109`). Las reglas que le falten se rehacen SIN deshacer nada:\n\n";
    echo "    php tools/servidor/rehacer-reglas.php <ruta/de/esta/migracion.php>\n";
    echo "    php artisan migrate\n";
} elseif (isset($bloques)) {
    echo "SIGUIENTE PASO:\n";
    echo "  Las tablas que toca estan vacias, asi que deshacerla es seguro.\n\n";
    echo "    php artisan migrate:rollback --step=1   (deshace SOLO esta: tiene lote propio)\n";
    echo "    php artisan migrate\n";
} else {
    // De esta migracion no se sabe si mueve datos, y **no se recomienda a
    // ciegas**: un `rollback` que reconstruye columnas soltadas las devuelve
    // vacias, y el `up()` de detras puede reventar sobre lo que quedo dentro.
    echo "SIGUIENTE PASO: hay que MIRARLO antes, no lo adivino.\n";
    echo "  Si el `down()` de esta migracion suelta y vuelve a crear columnas, mira primero\n";
    echo "  cuantas filas hay en esas tablas: con datos dentro, el `up()` de detras revienta\n";
    echo "  (`T-109`). Con dudas, el camino que NO deshace nada:\n\n";
    echo "    php tools/servidor/rehacer-reglas.php <ruta/de/esta/migracion.php>\n";
    echo "    php artisan migrate\n";
}

echo "\nY al terminar, siempre:\n";
echo "  php tools/servidor/verificar-registro.php\n";
