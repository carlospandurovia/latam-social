<?php

/*
 * Ejecuta las puertas de calidad y deja TODA la salida en un archivo.
 *
 *   php tools/diagnostico.php            # todas
 *   php tools/diagnostico.php pruebas    # solo una (formato|analisis|fronteras|pruebas|ci)
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
 * Aqui la captura la hace PHP: el hijo escribe su stdout y su stderr en el
 * mismo archivo, y nosotros lo volcamos en UTF-8 plano. Lo que hay en el
 * archivo es exactamente lo que imprimieron las herramientas.
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
    // `.github/workflows/` es ruta protegida: las herramientas remotas no
    // escriben ahi. El fichero viaja en `tools/github-workflow-ci.yml` y la
    // copia se hace desde esta maquina.
    //
    // Se comprueba aqui porque un CI desactualizado NO falla: sale verde
    // comprobando menos cosas, que es la peor forma de fallar. Paso: el CI
    // estuvo dos dias corriendo una version que no conocia las suites 4.3, 4.4
    // ni 4.5 ni el gate de nombres, y nadie se entero.
    // La aritmetica de vigencias. El defecto de `H-16` --cerrar un periodo el
    // mismo dia en que empieza el siguiente, siendo `valid_to` inclusivo-- ha
    // aparecido NUEVE veces en este proyecto. `Vigencia` le dio un sitio; esto
    // impide que vuelva a salir de el.
    //
    // Al escribirla quedaban dos copias sueltas y una comparacion de cadenas
    // sin normalizar, las tres escritas DESPUES de `Vigencia`. Centralizar no
    // impide descentralizar; buscarlo si.
    'vigencias' => [
        'titulo' => 'La aritmetica de vigencias vive en un solo sitio',
        'cmd' => $php.' tools'.$ds.'verificar-vigencias.php',
        'arreglo' => null,
    ],
    // 8.11, del QA de la Fase 8. Los cuatro ayudantes de las suites estaban
    // copiados en las treinta y habian derivado en SEIS variantes; nueve no
    // tenian la guarda de conexion, y sin ella un motor caido pone en verde
    // cada asercion de rechazo. Medido: la suite de 7.6 contra una base que no
    // existe daba 39 correctas y cero fallidas.
    //
    // Y no era una leccion nueva: 2.13 lleva escrito desde la Fase 2 «25
    // aserciones en verde contra un socket muerto». Se arreglo en un archivo, y
    // habia treinta.
    'suites' => [
        'titulo' => 'Las suites comparten ayudantes, y ninguna miente con el motor apagado',
        'cmd' => 'python3 tools'.$ds.'verificar-suites.py',
        'arreglo' => null,
    ],
    'ci' => [
        'titulo' => 'El CI del repositorio esta al dia',
        'cmd' => $php.' tools'.$ds.'sincronizar-ci.php --comprobar',
        'arreglo' => 'php tools'.$ds.'sincronizar-ci.php   (copia el fichero y ya)',
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
 * stdout y stderr van al mismo sitio a proposito: asi los errores llegan
 * mezclados en su orden real, que es como hay que leerlos.
 *
 * @return array{0: list<string>, 1: int}
 */
$ejecutar = static function (string $comando, ?callable $parcial = null): array {
    // La salida del hijo va a un ARCHIVO, no a una tuberia. Y esto es el tercer
    // intento, asi que conviene dejar escrito por que.
    //
    // Intento 1: `fgets` bloqueante. El archivo solo se escribia al TERMINAR la
    //   puerta, asi que un cuelgue en PHPUnit no dejaba ni una linea.
    // Intento 2: `stream_select` con tiempo de espera. En Windows no funciona
    //   sobre las tuberias de `proc_open` --solo sobre sockets-- y el bucle se
    //   quedaba bloqueado ahi.
    // Intento 3: `stream_set_blocking(false)` + `fread`. Tampoco: **PHP en
    //   Windows no admite modo NO bloqueante en las tuberias de `proc_open`**.
    //   `stream_set_blocking()` devuelve false y el flujo sigue bloqueando, asi
    //   que `fread` espera a llenar el bufer o al final del proceso. El sintoma
    //   era identico al del intento 2: una escritura a los dos segundos y nada
    //   mas. En Linux los tres funcionaban, que es exactamente lo que hace tan
    //   dificil de ver este fallo desde aqui.
    //
    // La salida es no usar tuberias. Un archivo se lee sin bloquear en los dos
    // sistemas, y `proc_get_status()` dice cuando termino el hijo. Menos elegante
    // y portable de verdad.
    $bruto = tempnam(sys_get_temp_dir(), 'diag');

    if ($bruto === false) {
        return [['No pude crear el archivo temporal de salida.'], 127];
    }

    // UN SOLO manejador para stdout y stderr, no dos descriptores `['file', ...]`
    // apuntando al mismo archivo.
    //
    // Con dos, cada uno lleva su propia posicion: el de stdout empieza en 0 y
    // avanza, el de stderr escribe siempre al final. Se pisan. En la prueba de
    // banco se perdieron dos lineas enteras y una tercera salio como mezcla de
    // otras dos --y como no aparecia ninguna linea nueva, el contador de
    // "segundos callado" tampoco se reiniciaba: parecia congelado cuando el
    // proceso estaba hablando.
    //
    // Compartiendo el manejador comparten la posicion, y el orden que queda en
    // el archivo es el real.
    $escritor = fopen($bruto, 'w');

    if ($escritor === false) {
        @unlink($bruto);

        return [['No pude abrir el archivo temporal de salida.'], 127];
    }

    $proceso = proc_open($comando, [
        1 => $escritor,
        2 => $escritor,
    ], $tuberias);

    if (!is_resource($proceso)) {
        fclose($escritor);
        @unlink($bruto);

        return [['No se pudo lanzar: '.$comando], 127];
    }

    $lector = fopen($bruto, 'r');

    if ($lector === false) {
        proc_close($proceso);
        fclose($escritor);
        @unlink($bruto);

        return [['No pude leer el archivo temporal de salida.'], 127];
    }

    $lineas = [];
    $resto = '';
    $ultimoVolcado = 0.0;
    $ultimaLinea = microtime(true);
    $codigo = 0;

    while (true) {
        $trozo = stream_get_contents($lector);

        if (is_string($trozo) && $trozo !== '') {
            $resto .= $trozo;

            while (($corte = strpos($resto, "\n")) !== false) {
                $linea = rtrim(substr($resto, 0, $corte), "\r\n");
                $resto = substr($resto, $corte + 1);
                $lineas[] = $linea;
                $ultimaLinea = microtime(true);
                echo '  | '.$linea.PHP_EOL;
            }

            continue;   // puede quedar mas; no dormir todavia
        }

        $estado = proc_get_status($proceso);

        if ($estado === false || $estado['running'] === false) {
            // Una ultima pasada: entre el ultimo `stream_get_contents` y el
            // final del hijo pueden haber quedado bytes sin leer.
            $ultimo = stream_get_contents($lector);

            if (is_string($ultimo) && $ultimo !== '') {
                $resto .= $ultimo;

                continue;
            }

            $codigo = $estado === false ? 1 : (int) $estado['exitcode'];
            break;
        }

        $ahora = microtime(true);

        if ($parcial !== null && $ahora - $ultimoVolcado >= 2.0) {
            $parcial($lineas, (int) round($ahora - $ultimaLinea));
            $ultimoVolcado = $ahora;
        }

        usleep(200000);
    }

    if ($resto !== '') {
        $lineas[] = rtrim($resto, "\r\n");
    }

    fclose($lector);
    proc_close($proceso);
    fclose($escritor);
    @unlink($bruto);

    return [$lineas, $codigo];
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

    // Lo que se escribe MIENTRAS la puerta corre. Si se queda colgada, esto es
    // todo lo que va a haber, asi que dice tambien cuanto lleva sin hablar.
    $enCurso = static function (array $lineas, int $callado) use ($volcar, $informe, $puerta, $inicio): void {
        $transcurrido = round(microtime(true) - $inicio, 1);
        $volcar(array_merge($informe, [
            '',
            str_repeat('-', 78),
            "### {$puerta['titulo']}  ->  EN CURSO   [{$transcurrido}s]",
            '### '.$puerta['cmd'],
            str_repeat('-', 78),
            $lineas === [] ? '(todavia sin salida)' : implode(PHP_EOL, $lineas),
            '',
            $callado >= 20
                ? ">>> Lleva {$callado}s sin escribir nada. Si no avanza: php tools/bd-procesos.php"
                : ">>> En curso ({$callado}s desde la ultima linea).",
        ]));
    };

    [$lineas, $codigo] = $ejecutar($puerta['cmd'], $enCurso);
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
