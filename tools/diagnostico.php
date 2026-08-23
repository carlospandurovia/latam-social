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

// El archivo se reescribe DESPUES DE CADA PUERTA, no al final.
//
// La primera version solo escribia al terminar las cuatro, y con las pruebas
// tardando un minuto eso dejaba un archivo viejo en disco mientras la ejecucion
// seguia viva: quien lo abriera leia el resultado de la vez ANTERIOR creyendo
// que era el de ahora. Un informe obsoleto que parece actual es peor que no
// tener informe.
$volcar = static function (array $lineas) use ($destino): void {
    file_put_contents($destino, implode(PHP_EOL, $lineas).PHP_EOL);
};

/*
 * Ejecuta un comando MOSTRANDO la salida mientras ocurre, y ademas la devuelve.
 *
 * `exec()` no sirve para esto: no imprime nada hasta que el proceso termina, asi
 * que las pruebas -que tardan dos minutos- parecian colgadas. Un proceso que
 * trabaja sin dar senales es indistinguible de uno muerto, y quien mira acaba
 * cortandolo por si acaso.
 *
 * `2>&1` va en el comando y no en un descriptor aparte: asi los errores llegan
 * mezclados en su orden real, que es como hay que leerlos.
 *
 * @return array{0: list<string>, 1: int}
 */
$ejecutar = static function (string $comando): array {
    $tuberias = [];
    $proceso = proc_open($comando.' 2>&1', [1 => ['pipe', 'w']], $tuberias);

    if (!is_resource($proceso)) {
        return [['No se pudo lanzar: '.$comando], 127];
    }

    $lineas = [];

    while (($linea = fgets($tuberias[1])) !== false) {
        $linea = rtrim($linea, "\r\n");
        $lineas[] = $linea;
        echo '  | '.$linea.PHP_EOL;
    }

    fclose($tuberias[1]);

    return [$lineas, proc_close($proceso)];
};

$informe = [];
$resumen = [];
$fallos = 0;

$informe[] = str_repeat('=', 78);
$informe[] = 'DIAGNOSTICO  '.date('Y-m-d H:i:s');
$informe[] = 'PHP '.PHP_VERSION.' en '.PHP_OS_FAMILY;
$informe[] = str_repeat('=', 78);

foreach ($puertas as $clave => $puerta) {
    echo PHP_EOL."  == {$puerta['titulo']} ==".PHP_EOL;

    $inicio = microtime(true);
    [$lineas, $codigo] = $ejecutar($puerta['cmd']);
    $segundos = round(microtime(true) - $inicio, 1);

    $estado = $codigo === 0 ? 'OK' : "FALLO (codigo {$codigo})";
    $codigo === 0 or $fallos++;
    echo "  -> {$estado} [{$segundos}s]".PHP_EOL;

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

    // Volcado incremental: si la ejecucion se corta o alguien abre el archivo
    // mientras corre, lo que hay dentro es de ESTA ejecucion.
    $volcar(array_merge($informe, ['', '(ejecucion en curso: faltan puertas por correr)']));
}

$informe[] = '';
$informe[] = str_repeat('=', 78);
$informe[] = 'RESUMEN';
$informe = array_merge($informe, $resumen);
$informe[] = str_repeat('=', 78);

// UTF-8 plano, sin BOM: PHP escribe los bytes tal cual.
$volcar($informe);

echo "\n".implode("\n", $resumen)."\n";
echo "\n  Salida completa en: tools".$ds."diagnostico.txt\n\n";

exit($fallos === 0 ? 0 : 1);
