<?php
// Puente: genera los TRIGGER usando la clase de produccion, no una copia.
declare(strict_types=1);
require __DIR__ . '/../stage/app/Shared/Database/Restriccion.php';

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
