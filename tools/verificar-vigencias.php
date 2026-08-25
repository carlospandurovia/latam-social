<?php

declare(strict_types=1);

/**
 * La puerta contra el defecto que este proyecto ha cometido NUEVE veces.
 *
 * ## Qué defecto
 *
 * `valid_to` es **inclusivo** en todo el esquema. Cerrar un periodo con
 * `valid_to = valid_from` del siguiente deja los dos vigentes el día del
 * relevo, y entonces «¿qué regía el 1 de mayo?» tiene dos respuestas.
 *
 * Ha aparecido en tarifas (`H-16`), en disponibilidad, en el histórico fiscal
 * del creador (`T-12`), en el del cliente, en la cobertura de países, en los
 * términos legales, y estuvo a punto de aparecer dos veces más en 4.4 y 4.5.
 * La respuesta fue `App\Shared\Database\Vigencia`: **un** sitio donde vive la
 * aritmética.
 *
 * Pero centralizar no impide volver a descentralizar. Cuando se escribió esta
 * puerta quedaban **dos** copias sueltas —el perfil fiscal del creador y la
 * publicación de términos—, las dos correctas, las dos escritas después de
 * `Vigencia`. Nadie las había visto porque nada las buscaba.
 *
 * ## Las dos reglas
 *
 * **A. Ninguna aritmética de días fuera de `Vigencia`.** `subDay()`, `addDay()`,
 * `subDays(1)`, `addDays(1)`, `modify('±1 day')` y `strtotime` con días.
 * Restar un día es fácil; acordarse de que hay que restarlo es lo difícil, y
 * eso sólo se recuerda si vive en un sitio con nombre.
 *
 * **B. Ninguna comparación de fechas de vigencia sin normalizar.** En PHP
 * `'2026-2-1' > '2026-11-01'` es **cierto**: son cadenas. Ese fue el fallo de
 * 4.5, y se cierra pasando los dos lados por `Vigencia::fecha()`.
 *
 * ## Por qué esto es PHP y no Python como las otras puertas
 *
 * Porque hay que distinguir el código de los comentarios y de las cadenas, y
 * este archivo está lleno de las tres cosas: los comentarios explican el
 * defecto nombrándolo (`subDay()`), y las migraciones llevan SQL con `>=`
 * dentro. Una expresión regular sobre el texto se acusaría a sí misma.
 *
 * `token_get_all()` es el lexer de PHP: no hay heurística que ajustar. Las
 * cadenas se sustituyen por un hueco antes de aplicar la regla B, así que un
 * `->where('valid_from', '<=', $hoy)` no cuenta —eso lo compara el motor, con
 * columnas `DATE`, no PHP con cadenas—.
 *
 * Uso:  php tools/verificar-vigencias.php
 */
$raiz = dirname(__DIR__);
$ds = DIRECTORY_SEPARATOR;

// DOS DISPOSICIONES, LA MISMA HERRAMIENTA.
//
// En el repositorio el codigo esta en `app/`; en el area de entrega, en
// `stage/app/`. Esta comprobacion no es cosmetica: `verificar-pantallas.py`
// apuntaba solo a `stage/` y por eso reventaba en el CI. Se mira cual existe.
$codigo = is_dir($raiz.$ds.'stage'.$ds.'app') ? $raiz.$ds.'stage' : $raiz;

// LAS PRUEBAS TAMBIEN, pero con la regla A acotada.
//
// La copia numero ONCE del defecto de `H-16` estaba en `tests/`: el apoyo que
// publica terminos cerraba `effective_to` a mano, fuera de `Vigencia`. Que
// estuviera bien calculada no la salvaba --simula `PublicarTerminosCommand`, y
// una simulacion que puede desviarse del original prueba el original de
// mentira--.
//
// Pero una prueba SI hace aritmetica de dias legitima: `now()->subDay()` para
// fechar un `created_at` en el pasado no tiene nada que ver con cerrar un
// periodo. Medido contra el arbol real: cuatro sitios, tres legitimos.
// Acusarlos seria ensenar a saltarse la puerta.
//
// Asi que en `tests/` la regla A solo salta si la linea nombra ADEMAS una
// columna de vigencia. La regla B se aplica igual que en `app/`.
$pruebas = is_dir($raiz.$ds.'.entrega'.$ds.'tests') ? $raiz.$ds.'.entrega' : $raiz;

/** El único archivo al que se le permite hacer la aritmética. */
const CASA = 'app/Shared/Database/Vigencia.php';

/** Columnas de vigencia: comparar una de éstas como cadena es el fallo de 4.5. */
const FECHAS = [
    'valid_from', 'valid_to',
    'effective_from', 'effective_to',
    'incorporated_on', 'dissolved_on',
];

$hallazgos = [];
$mirados = 0;

/** @return list<string> */
$phpDe = static function (string $dir): array {
    if (! is_dir($dir)) {
        return [];
    }
    $salida = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $salida[] = $f->getPathname();
        }
    }
    sort($salida);

    return $salida;
};

$archivos = [];

foreach ($phpDe($codigo.$ds.'app') as $r) {
    $archivos[] = [$r, str_replace('\\', '/', substr($r, strlen($codigo) + 1)), false];
}

foreach ($phpDe($pruebas.$ds.'tests') as $r) {
    $archivos[] = [$r, str_replace('\\', '/', substr($r, strlen($pruebas) + 1)), true];
}

foreach ($archivos as [$ruta, $relativa, $esPrueba]) {
    if ($relativa === CASA) {
        continue;
    }

    $mirados++;
    $fuente = (string) file_get_contents($ruta);
    $tokens = token_get_all($fuente);

    // ---------------------------------------------------------------- regla A
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];

        if (! is_array($t) || $t[0] !== T_STRING) {
            continue;
        }

        $nombre = $t[1];
        $linea = $t[2];

        // El argumento literal de la llamada, si lo hay: `subDays(1)`,
        // `modify('+1 day')`. Se mira sólo el primero.
        $arg = null;
        for ($j = $i + 1; $j < min($i + 4, $n); $j++) {
            $s = $tokens[$j];
            if ($s === '(') {
                continue;
            }
            if (is_array($s) && in_array($s[0], [T_LNUMBER, T_CONSTANT_ENCAPSED_STRING], true)) {
                $arg = trim($s[1], "'\"");
                break;
            }
            break;
        }

        $culpable = match (true) {
            in_array($nombre, ['subDay', 'addDay'], true) => true,
            in_array($nombre, ['subDays', 'addDays'], true) && $arg === '1' => true,
            $nombre === 'modify' && $arg !== null && stripos($arg, 'day') !== false => true,
            $nombre === 'strtotime' && $arg !== null && stripos($arg, 'day') !== false => true,
            default => false,
        };

        // En `tests/`, solo si la linea nombra ademas una columna de vigencia:
        // `now()->subDay()` para fechar un `created_at` es legitimo y acusarlo
        // seria ensenar a saltarse la puerta.
        if ($culpable && $esPrueba) {
            $enLinea = '';
            foreach ($tokens as $t2) {
                if (is_array($t2) && $t2[2] === $linea) {
                    $enLinea .= $t2[1];
                }
            }
            $culpable = false;
            foreach (FECHAS as $f) {
                if (str_contains($enLinea, $f)) {
                    $culpable = true;
                    break;
                }
            }
        }

        if ($culpable) {
            $hallazgos[] = [
                'regla' => 'A',
                'archivo' => $relativa,
                'linea' => $linea,
                'texto' => $nombre.'()',
                'porque' => 'aritmetica de dias fuera de Vigencia: es la copia numero N del defecto de H-16',
                'arreglo' => 'Vigencia::cerrarElDiaAntesDe() o Vigencia::elDiaDespuesDe()',
            ];
        }
    }

    // ---------------------------------------------------------------- regla B
    //
    // Se reconstruye el archivo SIN comentarios y con las cadenas sustituidas
    // por un hueco. Lo que queda es codigo, y sobre eso si se puede buscar un
    // operador de comparacion sin acusar a un comentario ni a un SQL.
    $lineas = [];
    foreach ($tokens as $t) {
        if (is_array($t)) {
            [$tipo, $texto, $linea] = $t;

            if (in_array($tipo, [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (in_array($tipo, [
                T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE,
                T_INLINE_HTML, T_START_HEREDOC, T_END_HEREDOC,
            ], true)) {
                $lineas[$linea] = ($lineas[$linea] ?? '').' @ ';
                continue;
            }

            $lineas[$linea] = ($lineas[$linea] ?? '').$texto;
            continue;
        }

        // Un caracter suelto (`;`, `(`, `>`...). No trae numero de linea: va a
        // la ultima vista, que es donde esta.
        $ultima = $lineas === [] ? 1 : array_key_last($lineas);
        $lineas[$ultima] = ($lineas[$ultima] ?? '').$t;
    }

    foreach ($lineas as $numero => $expresion) {
        if (! preg_match('/(?<![-=!<>])(<=?|>=?)(?!=)/', $expresion)) {
            continue;
        }

        // Ya normaliza. `Vigencia::fecha()` en los dos lados es la forma
        // correcta; `puedeRelevar()` la envuelve.
        if (str_contains($expresion, 'Vigencia::')) {
            continue;
        }

        // `toDateString()` devuelve SIEMPRE `Y-m-d` con ceros. Comparar eso con
        // una columna `DATE` es seguro, y acusarlo seria ruido.
        //
        // Esta excepcion no es una concesion: es la regla. Lo que hace peligrosa
        // a una comparacion no es que haya fechas, es que UNA DE LAS DOS venga
        // de fuera sin normalizar --de un formulario, de una opcion de consola--.
        // Dos columnas `DATE` comparadas entre si estan bien; el fallo de 4.5
        // fue una fecha de formulario contra una columna.
        if (str_contains($expresion, 'toDateString()')) {
            continue;
        }

        foreach (FECHAS as $columna) {
            if (! str_contains($expresion, $columna)) {
                continue;
            }

            // LOS DOS OPERANDOS, no la linea entera.
            //
            // Mirar toda la linea acusaba a
            // `if ($x !== null && $a->valid_from <= $b->valid_from)` por culpa
            // del `$x` del principio, que no participa en la comparacion. Se
            // toma la ULTIMA variable antes del operador y la PRIMERA despues:
            // eso son los operandos, y lo demas es contexto.
            if (! preg_match('/(?<![-=!<>])(<=?|>=?)(?!=)/', $expresion, $op, PREG_OFFSET_CAPTURE)) {
                break;
            }

            $corte = (int) $op[0][1];
            $variable = '/\$[A-Za-z_][A-Za-z0-9_]*(->[A-Za-z_][A-Za-z0-9_]*)?/';

            preg_match_all($variable, substr($expresion, 0, $corte), $izq);
            preg_match($variable, substr($expresion, $corte + strlen($op[0][0])), $der);

            $operandos = array_values(array_filter([
                $izq[0] === [] ? null : end($izq[0]),
                $der[0] ?? null,
            ]));

            $sueltas = array_values(array_filter($operandos, static function (string $v): bool {
                foreach (FECHAS as $f) {
                    if (str_ends_with($v, '->'.$f)) {
                        return false;
                    }
                }

                return true;
            }));

            if ($sueltas === []) {
                break;
            }

            $hallazgos[] = [
                'regla' => 'B',
                'archivo' => $relativa,
                'linea' => $numero,
                'texto' => trim(preg_replace('/\s+/', ' ', $expresion) ?? ''),
                'porque' => "se compara `{$columna}` con ".implode(', ', $sueltas)
                    .", que puede no venir normalizada: '2026-2-1' > '2026-11-01' es CIERTO como cadena",
                'arreglo' => 'Vigencia::fecha() en los DOS lados, o Vigencia::puedeRelevar()',
            ];
            break;
        }
    }
}

// ---------------------------------------------------------------------- salida

// UNA PUERTA QUE NO MIRA NADA NO ES UNA PUERTA.
//
// Si la ruta esta mal, lo de arriba recorre cero archivos y esto sale verde
// diciendo que todo esta bien. Es exactamente como fallaba la otra puerta, y
// el modo de fallo mas caro que tiene una comprobacion automatica: contar que
// no hay problemas cuando lo que no hay es busqueda.
if ($mirados === 0) {
    fwrite(STDERR, "\n  \033[31mNo se encontro codigo que mirar en {$codigo}{$ds}app.\033[0m\n\n");
    exit(2);
}

echo "\n  Aritmetica de vigencias\n";
echo '  '.str_repeat('-', 68)."\n";

if ($hallazgos === []) {
    printf("\n  \033[32mLa aritmetica de vigencias vive en un solo sitio.\033[0m  (%d archivos)\n\n", $mirados);
    exit(0);
}

foreach ($hallazgos as $h) {
    printf("\n  \033[31m%s\033[0m  %s:%d\n", $h['regla'], $h['archivo'], $h['linea']);
    printf("     %s\n", $h['texto']);
    printf("     \033[33m%s\033[0m\n", $h['porque']);
    printf("     arreglo: %s\n", $h['arreglo']);
}

printf("\n  \033[31m%d sitio(s) hacen por su cuenta lo que Vigencia hace por todos.\033[0m\n\n", count($hallazgos));

exit(1);
