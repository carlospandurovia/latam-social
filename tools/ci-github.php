<?php

/*
 * Trae el resultado del ultimo CI de GitHub a un archivo legible.
 *
 *   php tools/ci-github.php          # el ultimo flujo
 *   php tools/ci-github.php 12345    # un run concreto por id
 *
 * Mismo motivo que `tools/diagnostico.php`: `gh run view > salida.txt` en
 * PowerShell escribe UTF-16 con BOM y convierte el stderr en objetos de error.
 * Aqui la captura la hace PHP y el archivo sale en UTF-8 plano.
 *
 * Escribe `tools/ci-github.txt` con el estado de cada paso y, si algo fallo, el
 * log del paso que fallo -- que es lo unico que hace falta leer; el log entero
 * de un flujo son decenas de miles de lineas.
 */

$raiz = dirname(__DIR__);
chdir($raiz);

$ds = DIRECTORY_SEPARATOR;
$run = $argv[1] ?? null;

if ($run !== null && preg_match('/^\d+$/', $run) !== 1) {
    fwrite(STDERR, "  El id del run debe ser un numero.\n");
    exit(2);
}

exec('gh --version 2>&1', $prueba, $codigo);

if ($codigo !== 0) {
    fwrite(STDERR, "\n  No encuentro `gh` (GitHub CLI).\n");
    fwrite(STDERR, "  Instalalo con:  winget install --id GitHub.cli\n");
    fwrite(STDERR, "  y despues:      gh auth login\n\n");
    exit(1);
}

// El id se resuelve SIEMPRE, aunque no lo hayan pasado. `gh run view` sin id
// solo funciona en modo interactivo: fuera de una consola pide que se lo den y
// devuelve la ayuda del comando en vez del informe. Con `--json` no hay que
// adivinar nada leyendo texto.
$crudo = [];
exec('gh run list --limit 5 --json databaseId,conclusion,status,displayTitle,headBranch,createdAt 2>&1', $crudo);
$ejecuciones = json_decode(implode('', $crudo), true);

if (! is_array($ejecuciones) || $ejecuciones === []) {
    fwrite(STDERR, "\n  No pude leer la lista de ejecuciones:\n");
    fwrite(STDERR, '  '.implode("\n  ", $crudo)."\n\n");
    exit(1);
}

$elegida = null;

foreach ($ejecuciones as $e) {
    if ($run === null || (string) $e['databaseId'] === $run) {
        $elegida = $e;
        break;
    }
}

if ($elegida === null) {
    fwrite(STDERR, "  No encuentro el run {$run} entre los ultimos 5.\n");
    exit(1);
}

$run = (string) $elegida['databaseId'];
$estado = (string) $elegida['status'];
$resultado = (string) ($elegida['conclusion'] ?: '(sin terminar)');

$partes = [];
$partes[] = str_repeat('=', 78);
$partes[] = 'CI DE GITHUB  '.date('Y-m-d H:i:s');
$partes[] = "run {$run}  ·  {$estado}  ·  {$resultado}";
$partes[] = $elegida['displayTitle'];
$partes[] = str_repeat('=', 78);

$partes[] = '';
$partes[] = '### Ultimas ejecuciones';

foreach ($ejecuciones as $e) {
    $partes[] = sprintf(
        '  %-11s %-9s %-11s %s',
        $e['databaseId'],
        $e['status'],
        $e['conclusion'] ?: '-',
        $e['displayTitle'],
    );
}

$partes[] = '';
$partes[] = str_repeat('-', 78);
$partes[] = '### Pasos';
$partes[] = str_repeat('-', 78);
$detalle = [];
exec("gh run view {$run} --verbose 2>&1", $detalle);
$partes[] = implode(PHP_EOL, $detalle);

// El resultado sale del campo `conclusion`, no de buscar palabras en el texto:
// el texto cambia entre versiones de `gh` y la deteccion por cadenas se rompe
// en silencio, que es la peor manera de romperse.
if ($estado !== 'completed') {
    $partes[] = '';
    $partes[] = '>>> El flujo sigue corriendo. Vuelve a ejecutar esto en un minuto.';
} elseif ($resultado !== 'success') {
    $partes[] = '';
    $partes[] = str_repeat('-', 78);
    $partes[] = '### Log del paso que fallo';
    $partes[] = str_repeat('-', 78);
    $log = [];
    exec("gh run view {$run} --log-failed 2>&1", $log);
    $partes[] = $log === [] ? '(sin log disponible)' : implode(PHP_EOL, $log);
}

file_put_contents($raiz.$ds.'tools'.$ds.'ci-github.txt', implode(PHP_EOL, $partes).PHP_EOL);

echo "\n  run {$run}  ->  {$estado} / {$resultado}\n";
echo '  '.$elegida['displayTitle']."\n";
echo "\n  Detalle en: tools".$ds."ci-github.txt\n\n";

exit($estado === 'completed' && $resultado === 'success' ? 0 : 1);
