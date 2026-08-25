<?php

/*
 * Copia `tools/github-workflow-ci.yml` a `.github/workflows/ci.yml`.
 *
 *   php tools/sincronizar-ci.php              # copia si hace falta
 *   php tools/sincronizar-ci.php --comprobar  # solo mira y avisa, no escribe
 *
 * ## Por que hace falta un script para copiar un archivo
 *
 * `.github/workflows/` es ruta PROTEGIDA: las herramientas remotas no pueden
 * escribir ahi, y con razon —quien puede editar el fichero de CI puede hacer
 * que el CI ejecute cualquier cosa—. Asi que el fichero se mantiene en
 * `tools/github-workflow-ci.yml`, que si viaja, y esta copia la hace usted
 * desde su maquina.
 *
 * Lo que pasaba mientras esa copia era "acuerdate de hacerlo a mano": el CI se
 * quedo dos dias corriendo una version vieja que no conocia las suites 4.3, 4.4
 * ni 4.5 ni el gate de nombres. Un CI desactualizado no falla: sale verde
 * comprobando menos cosas, que es la peor forma de fallar.
 *
 * Por eso `diagnostico.php` lo comprueba en cada pasada (puerta `ci`).
 *
 * ## Por que PHP y no PowerShell
 *
 * Porque ya tiene PHP —lo usa para `diagnostico.php`— y porque este proyecto ya
 * se quemo una vez con las rarezas de redireccion de PowerShell. Esto funciona
 * igual en Windows, Linux y macOS.
 */

$raiz = dirname(__DIR__);
$origen = $raiz.'/tools/github-workflow-ci.yml';
$destino = $raiz.'/.github/workflows/ci.yml';
$soloComprobar = in_array('--comprobar', array_slice($argv, 1), true);

$rel = static fn (string $ruta): string => str_replace($raiz.'/', '', str_replace('\\', '/', $ruta));

if (! is_file($origen)) {
    fwrite(STDERR, "  No existe {$rel($origen)}. Sin el, no hay nada que copiar.\n");
    exit(2);
}

$nuevo = file_get_contents($origen);
$actual = is_file($destino) ? file_get_contents($destino) : null;

if ($actual === $nuevo) {
    echo "  El CI ya esta al dia (".number_format(strlen($nuevo))." bytes).\n";
    exit(0);
}

// --------------------------------------------------------- que va a cambiar

echo "  El CI esta desactualizado.\n\n";

if ($actual === null) {
    echo "    ahora:  no existe {$rel($destino)}\n";
} else {
    printf("    ahora:  %s bytes, del %s\n", number_format(strlen($actual)), date('Y-m-d H:i', (int) filemtime($destino)));
}

printf("    nuevo:  %s bytes, del %s\n\n", number_format(strlen($nuevo)), date('Y-m-d H:i', (int) filemtime($origen)));

// Un resumen de las lineas que cambian, para que la copia no sea a ciegas.
if ($actual !== null) {
    $antes = explode("\n", $actual);
    $despues = explode("\n", $nuevo);
    $quitadas = array_diff($antes, $despues);
    $puestas = array_diff($despues, $antes);

    printf("    %d lineas se van, %d entran. Las que entran:\n", count($quitadas), count($puestas));

    $muestra = array_slice($puestas, 0, 12);

    foreach ($muestra as $linea) {
        $linea = trim($linea);

        if ($linea !== '') {
            echo '      + '.mb_substr($linea, 0, 90)."\n";
        }
    }

    if (count($puestas) > count($muestra)) {
        printf("      ... y %d mas\n", count($puestas) - count($muestra));
    }

    echo "\n";
}

if ($soloComprobar) {
    echo "  Para copiarlo:  php tools/sincronizar-ci.php\n";
    exit(1);
}

// ------------------------------------------------------------------ copiar

$carpeta = dirname($destino);

if (! is_dir($carpeta) && ! mkdir($carpeta, 0o775, true) && ! is_dir($carpeta)) {
    fwrite(STDERR, "  No se pudo crear {$rel($carpeta)}.\n");
    exit(2);
}

if (file_put_contents($destino, $nuevo) === false) {
    fwrite(STDERR, "  No se pudo escribir {$rel($destino)}. Compruebe permisos.\n");
    exit(2);
}

// Se relee de disco: confirmar con lo que se pretendia escribir no confirma nada.
if (file_get_contents($destino) !== $nuevo) {
    fwrite(STDERR, "  Se escribio {$rel($destino)} pero no coincide. No lo de por bueno.\n");
    exit(2);
}

echo "  Copiado a {$rel($destino)}.\n";
echo "  Acuerdese de incluirlo en el commit: `git add .github/workflows/ci.yml`\n";
exit(0);
