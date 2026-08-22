<?php
// Puente: genera los TRIGGER usando la clase de produccion, no una copia.
declare(strict_types=1);
/*
 * La ruta a Restriccion.php se BUSCA, no se fija.
 *
 * Aqui habia `__DIR__ . '/../stage/app/...'`, que es la disposicion del entorno
 * donde se escribio esto y no la del repositorio. En un checkout limpio ese
 * archivo no existe y el script muere. Lo encontro el CI, que es exactamente
 * para lo que sirve: correr sobre una maquina que no es la de nadie.
 */
$candidatos = [
    __DIR__.'/../app/Shared/Database/Restriccion.php',        // repositorio
    __DIR__.'/../stage/app/Shared/Database/Restriccion.php',  // entorno de trabajo
];
$origen = null;
foreach ($candidatos as $candidato) {
    if (is_file($candidato)) {
        $origen = $candidato;
        break;
    }
}
if ($origen === null) {
    fwrite(STDERR, "No encuentro Restriccion.php. Buscado en:\n  - ".implode("\n  - ", $candidatos)."\n");
    exit(1);
}
require $origen;

$decl = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);

echo "-- Generado por App\\Shared\\Database\\Restriccion (mecanismo: trigger)\n";
echo "-- NO editar a mano: se regenera con tools/generar-triggers.py\n";
echo "DELIMITER \$\$\n";
foreach ($decl as $d) {
    foreach (['INSERT', 'UPDATE'] as $evento) {
        echo \App\Shared\Database\Restriccion::sqlTrigger(
            $d['tabla'], $d['nombre'], $d['expresion'],
            $d['columnas'], $d['mensaje'], $evento
        ), "\$\$\n";
    }
}
echo "DELIMITER ;\n";
