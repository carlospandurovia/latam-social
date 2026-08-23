<?php

/*
 * Ejecuta las cuatro puertas de calidad y deja TODA la salida en un archivo.
 *
 *   php tools/diagnostico.php            # las cuatro
 *   php tools/diagnostico.php pruebas    # solo una (formato|analisis|fronteras|pruebas)
 *
 * Por que existe, que no es por comodidad:
 *
 * En PowerShell, `comando > salida.txt` escribe el archivo en UTF-16 con BOM y
 * ademas convierte cada linea de stderr en un objeto de error con su traza de
 * PowerShell alrededor. El resultado es un archivo que ni se lee bien ni
 * contiene lo que uno cree: la salida real queda enterrada entre `CategoryInfo`
 * y `NativeCommandError`, y si el comando sigue vivo el archivo se queda a
 * medias sin avisar. Nos costo dos rondas descubrirlo.
 *
 * Aqui la captura la hace PHP: `2>&1` a nivel del proceso hijo, se junta todo
 * en memoria y se escribe de una vez en UTF-8 plano. Lo que hay en el archivo
 * es exactamente lo que imprimieron las herramientas.
 */

$raiz = dirname(__DIR__);
chdir($raiz);

$ds = DIRECTORY_SEPARATOR;
// En Windows, Composer deja un proxy `.bat` junto al binario real; sin el
// sufijo, `cmd` no encuentra nada que ejecutar.
$bat = PHP_OS_FAMILY === 'Windows' ? '.bat' : '';
$php = '"'.PHP_BINARY.'"';

$puertas = [
    'formato' => [
        'titulo' => 'Formato (Pint)',
        'cmd' => "vendor{$ds}bin{$ds}pint{$bat} --test",
        'arreglo' => "vendor{$ds}bin{$ds}pint{$bat}   (sin --test, corrige solo)",
    ],
    'analisis' => [
        'titulo' => 'Analisis estatico (PHPStan)',
        'cmd' => "vendor{$ds}bin{$ds}phpstan{$bat} analyse --no-progress",
        'arreglo' => null,
    ],
    'fronteras' => [
        'titulo' => 'Fronteras entre modulos (Deptrac)',
        'cmd' => "vendor{$ds}bin{$ds}deptrac{$bat} analyse --fail-on-uncovered --report-uncovered",
        'arreglo' => null,
    ],
    'pruebas' => [
        'titulo' => 'Pruebas (PHPUnit)',
        // Sin color: los codigos ANSI en un archivo son ruido que estorba al leerlo.
        'cmd' => $php.' artisan test --colors=never',
        'arreglo' => null,
    ],
];

$soloEsta = $argv[1] ?? null;

if ($soloEsta !== null && ! isset($puertas[$soloEsta])) {
    fwrite(STDERR, "  Puerta desconocida: {$soloEsta}\n");
    fwrite(STDERR, '  Validas: '.implode(', ', array_keys($puertas))."\n");
    exit(2);
}

if ($soloEsta !== null) {
    $puertas = [$soloEsta => $puertas[$soloEsta]];
}

$destino = $raiz.$ds.'tools'.$ds.'diagnostico.txt';
$informe = [];
$resumen = [];
$fallos = 0;

$informe[] = str_repeat('=', 78);
$informe[] = 'DIAGNOSTICO  '.date('Y-m-d H:i:s');
$informe[] = 'PHP '.PHP_VERSION.' en '.PHP_OS_FAMILY;
$informe[] = str_repeat('=', 78);

foreach ($puertas as $clave => $puerta) {
    echo "  ejecutando: {$puerta['titulo']} ... ";

    $inicio = microtime(true);
    $lineas = [];
    $codigo = 0;
    // `2>&1` en el comando hijo: asi la salida de error llega mezclada en su
    // orden real, que es como hay que leerla, y no como excepciones aparte.
    exec($puerta['cmd'].' 2>&1', $lineas, $codigo);
    $segundos = round(microtime(true) - $inicio, 1);

    $estado = $codigo === 0 ? 'OK' : "FALLO (codigo {$codigo})";
    $codigo === 0 or $fallos++;
    echo $estado." [{$segundos}s]\n";

    $resumen[] = sprintf('  %-36s %s', $puerta['titulo'], $estado);

    $informe[] = '';
    $informe[] = str_repeat('-', 78);
    $informe[] = "### {$puerta['titulo']}  ->  {$estado}   [{$segundos}s]";
    $informe[] = '### '.$puerta['cmd'];
    $informe[] = str_repeat('-', 78);
    $informe[] = $lineas === [] ? '(sin salida)' : implode(PHP_EOL, $lineas);

    if ($codigo !== 0 && $puerta['arreglo'] !== null) {
        $informe[] = '';
        $informe[] = '>>> Se arregla solo con: '.$puerta['arreglo'];
    }
}

$informe[] = '';
$informe[] = str_repeat('=', 78);
$informe[] = 'RESUMEN';
$informe = array_merge($informe, $resumen);
$informe[] = str_repeat('=', 78);

// UTF-8 plano, sin BOM: PHP escribe los bytes tal cual.
file_put_contents($destino, implode(PHP_EOL, $informe).PHP_EOL);

echo "\n".implode("\n", $resumen)."\n";
echo "\n  Salida completa en: tools".$ds."diagnostico.txt\n\n";

exit($fallos === 0 ? 0 : 1);
