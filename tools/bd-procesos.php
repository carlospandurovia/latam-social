<?php

/*
 * Que esta haciendo MySQL ahora mismo, y quien esta bloqueando a quien.
 *
 *   php tools/bd-procesos.php                 # solo mirar
 *   php tools/bd-procesos.php --matar         # cerrar las conexiones dormidas
 *
 * Por que existe:
 *
 * Un `ALTER TABLE` necesita un BLOQUEO DE METADATOS sobre la tabla. Si otra
 * conexion tiene una transaccion abierta sobre esa tabla, el ALTER espera. Y
 * espera hasta `lock_wait_timeout`, que por defecto son 31.536.000 segundos:
 * un ano. En la practica, para siempre.
 *
 * Eso convierte un proceso de pruebas cortado a medias en una trampa que se
 * repite: la conexion muerta sigue viva del lado del servidor -hasta ocho horas
 * por `wait_timeout`- y cada ejecucion posterior se queda colgada en la misma
 * migracion, sin decir por que. Parece que "la migracion se cuelga" cuando lo
 * que pasa es que hay un fantasma de la vez anterior.
 *
 * `State = Waiting for table metadata lock` es la firma exacta de ese caso.
 */

$opciones = getopt('', ['host::', 'puerto::', 'usuario::', 'clave::', 'matar']);

$host = is_string($opciones['host'] ?? null) ? $opciones['host'] : '127.0.0.1';
$puerto = is_string($opciones['puerto'] ?? null) ? $opciones['puerto'] : '3306';
$usuario = is_string($opciones['usuario'] ?? null) ? $opciones['usuario'] : 'root';
$clave = is_string($opciones['clave'] ?? null) ? $opciones['clave'] : '';
$matar = array_key_exists('matar', $opciones);

try {
    $pdo = new PDO("mysql:host={$host};port={$puerto}", $usuario, $clave, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "\n  ".$e->getMessage()."\n\n");
    fwrite(STDERR, "  Si la clave no es vacia: php tools/bd-procesos.php --clave=TU_CLAVE\n\n");
    exit(1);
}

// El propio id, para no suicidarse.
$yo = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$espera = (string) $pdo->query("SELECT @@lock_wait_timeout")->fetchColumn();

$filas = $pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC);

echo "\n  lock_wait_timeout = {$espera} s";
echo (int) $espera > 86400 ? "  <-- un ALTER bloqueado espera practicamente para siempre\n\n" : "\n\n";
printf("  %-8s %-22s %-10s %-9s %s\n", 'ID', 'BASE', 'COMANDO', 'SEGUNDOS', 'ESTADO / CONSULTA');
echo '  '.str_repeat('-', 96)."\n";

$dormidas = [];
$bloqueados = 0;

foreach ($filas as $f) {
    $id = (int) $f['Id'];
    $comando = (string) $f['Command'];
    $estado = trim((string) ($f['State'] ?? ''));
    $consulta = trim((string) ($f['Info'] ?? ''));
    $detalle = $estado !== '' ? $estado : $consulta;

    if (str_contains(mb_strtolower($estado), 'metadata lock')) {
        $detalle = '*** '.$estado.' *** '.mb_substr($consulta, 0, 40);
        $bloqueados++;
    }

    printf(
        "  %-8d %-22s %-10s %-9s %s\n",
        $id,
        mb_substr((string) ($f['db'] ?? '-'), 0, 22),
        $comando,
        (string) $f['Time'],
        mb_substr($detalle, 0, 60),
    );

    // Candidatas: dormidas, ajenas y de nuestro propio usuario.
    if ($comando === 'Sleep' && $id !== $yo) {
        $dormidas[] = $id;
    }
}

echo "\n";

if ($bloqueados > 0) {
    echo "  HAY {$bloqueados} consulta(s) esperando un bloqueo de metadatos.\n";
    echo "  Eso es un ALTER TABLE parado por una transaccion que otra conexion dejo abierta.\n\n";
}

if ($dormidas === []) {
    echo "  No hay conexiones dormidas que cerrar.\n\n";
    exit(0);
}

if (!$matar) {
    echo '  Conexiones dormidas: '.implode(', ', $dormidas)."\n";
    echo "  Para cerrarlas:  php tools/bd-procesos.php --matar\n\n";
    exit(0);
}

foreach ($dormidas as $id) {
    try {
        $pdo->exec("KILL {$id}");
        echo "  cerrada la conexion {$id}\n";
    } catch (PDOException $e) {
        echo "  no pude cerrar la {$id}: ".$e->getMessage()."\n";
    }
}

echo "\n  Vuelve a lanzar las pruebas.\n\n";
