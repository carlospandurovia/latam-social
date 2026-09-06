<?php

/**
 * ¿Algún disparador generado mira una columna sin `NEW.`? (`DEC-304`)
 *
 * `Restriccion::reescribirConNew()` sólo antepone `NEW.` a las columnas que la
 * declaración LISTA. Una columna que aparece en la expresión y no en la lista
 * se queda desnuda, y dentro de un disparador un identificador desnudo no es
 * la fila: es `ERROR 1054 Unknown column ... in 'field list'`.
 *
 * El disparador se CREA sin protestar. El fallo aparece al primer INSERT.
 *
 * Y no se ve en MySQL 8 ni en MariaDB: allí el motor aplica `CHECK` nativo, la
 * lista de columnas no se usa para nada y la declaración incompleta es
 * inofensiva. Sólo muerde en MySQL 5.7, que es exactamente donde no se probaba.
 *
 *   php tools/servidor/verificar-disparadores.php
 *
 * Sale con código 1 si encuentra alguno.
 */

declare(strict_types=1);

$raiz = dirname(__DIR__, 2);
require $raiz.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $raiz.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$base = (string) DB::selectOne('SELECT DATABASE() AS d')->d;
echo "Base: {$base}\n";

$disparadores = DB::select(
    'SELECT trigger_name, event_object_table, action_statement
       FROM information_schema.triggers
      WHERE trigger_schema = ?
      ORDER BY event_object_table, trigger_name',
    [$base],
);

echo 'Disparadores a revisar: '.count($disparadores)."\n\n";

/** @var array<string, list<string>> $columnasDe */
$columnasDe = [];
$columnas = static function (string $tabla) use (&$columnasDe, $base): array {
    if (!isset($columnasDe[$tabla])) {
        $columnasDe[$tabla] = array_map(
            static fn ($f): string => (string) $f->c,
            DB::select(
                'SELECT column_name AS c FROM information_schema.columns
                  WHERE table_schema = ? AND table_name = ?',
                [$base, $tabla],
            ),
        );
    }

    return $columnasDe[$tabla];
};

$rotos = 0;

$saltados = 0;

foreach ($disparadores as $d) {
    $cuerpo = (string) $d->action_statement;

    // Solo se analizan los cuerpos GENERADOS por `Restriccion`, que son de una
    // sola forma: `BEGIN IF NOT (expresion) THEN SIGNAL ...; END IF; END`.
    //
    // Los escritos a mano y los de `Periodo` llevan subconsultas, y ahi una
    // columna desnuda es CORRECTA: pertenece a la otra tabla, o a las otras
    // filas de la propia. La primera version de este verificador no distinguia
    // y denuncio siete disparadores bien escritos --`tg_payout_medio_valido`,
    // `tg_cpm_compartida`, `tg_iline_*`, `tg_iconn_activa_*`-- junto a los seis
    // rotos de verdad. Un verificador que grita por nada ensena a ignorarlo,
    // que es peor que no tenerlo (`DEC-301`).
    if (preg_match('/\b(SELECT|FROM|DECLARE|INSERT|UPDATE|DELETE)\b/i', $cuerpo) === 1) {
        $saltados++;

        continue;
    }

    // Fuera los literales de texto: el mensaje de error suele nombrar columnas.
    $cuerpo = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", "''", $cuerpo) ?? $cuerpo;
    // Fuera las referencias que SI estan cualificadas.
    $cuerpo = preg_replace('/\b(?:NEW|OLD)\s*\.\s*`?\w+`?/i', '@@ok@@', $cuerpo) ?? $cuerpo;

    $desnudas = [];
    foreach ($columnas((string) $d->event_object_table) as $columna) {
        $patron = '/(?<![`\w.@])'.preg_quote($columna, '/').'(?![`\w])/i';
        if (preg_match($patron, $cuerpo) === 1) {
            $desnudas[] = $columna;
        }
    }

    if ($desnudas !== []) {
        $rotos++;
        printf(
            "  ROTO  %-28s en %-28s -> %s\n",
            (string) $d->trigger_name,
            (string) $d->event_object_table,
            implode(', ', $desnudas),
        );
    }
}

echo "\n";
echo "Analizados: ".(count($disparadores) - $saltados)." (forma generada por Restriccion)\n";
echo "Saltados:   {$saltados} (escritos a mano o de Periodo: llevan subconsultas)\n\n";

if ($rotos === 0) {
    echo "Ningun disparador mira una columna sin NEW.\n";
    exit(0);
}

echo "Disparadores rotos: {$rotos}\n";
echo "Cada uno reventara con 1054 en el primer INSERT o UPDATE que lo dispare.\n";
exit(1);
