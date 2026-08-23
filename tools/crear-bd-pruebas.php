<?php

/*
 * Crea la base de datos de pruebas. Nada mas.
 *
 * Existe porque el cliente `mysql` de linea de comandos no siempre esta en el
 * PATH --en Windows con XAMPP casi nunca lo esta-- y buscar donde lo instalo
 * cada quien es una perdida de tiempo que se repite en cada maquina nueva.
 * PHP siempre esta, y PDO habla el mismo protocolo.
 *
 * `RefreshDatabase` sabe crear las TABLAS, pero no la BASE: la base tiene que
 * existir antes de que Laravel pueda conectarse a ella.
 *
 *   php tools/crear-bd-pruebas.php
 *   php tools/crear-bd-pruebas.php --host=127.0.0.1 --puerto=3307 --usuario=root --clave=secreto
 *
 * Los valores por defecto son los mismos que declara `phpunit.xml`.
 */

$opciones = getopt('', ['host::', 'puerto::', 'usuario::', 'clave::', 'base::']);

$host    = $opciones['host']    ?? '127.0.0.1';
$puerto  = $opciones['puerto']  ?? '3306';
$usuario = $opciones['usuario'] ?? 'root';
$clave   = $opciones['clave']   ?? '';
$base    = $opciones['base']    ?? 'latam_social_test';

// Se conecta SIN nombre de base: no existe todavia, ese es el asunto.
try {
    $pdo = new PDO(
        "mysql:host={$host};port={$puerto}",
        $usuario,
        $clave,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
} catch (PDOException $e) {
    // Se separan los dos fallos a proposito. "No conecta" y "conecta pero te
    // rechaza" tienen arreglos distintos, y un mensaje que los mezcla manda a
    // revisar si el servicio esta arrancado cuando el servicio estaba bien y
    // lo que sobraba era la contrasena.
    $mensaje = $e->getMessage();
    $esAutenticacion = str_contains($mensaje, '1045') || str_contains($mensaje, '1698')
        || str_contains($mensaje, 'Access denied');

    fwrite(STDERR, "\n  ".$mensaje."\n\n");

    if ($esAutenticacion) {
        fwrite(STDERR, "  El servidor SI responde en {$host}:{$puerto}: lo que rechaza es el usuario.\n");
        fwrite(STDERR, "  Prueba con la clave que tengas puesta:\n");
        fwrite(STDERR, "      php tools/crear-bd-pruebas.php --usuario=root --clave=TU_CLAVE\n");
        fwrite(STDERR, "  Y pon esa misma clave en el DB_PASSWORD de phpunit.xml.\n\n");
    } else {
        fwrite(STDERR, "  No hay ningun MySQL escuchando en {$host}:{$puerto}.\n");
        fwrite(STDERR, "  Si usas XAMPP: abre el panel de control y arranca MySQL.\n");
        fwrite(STDERR, "  Si el puerto es otro: php tools/crear-bd-pruebas.php --puerto=3307\n");
        fwrite(STDERR, "  Si no tienes MySQL local: crea `latam_social_test` en tu servidor\n");
        fwrite(STDERR, "  y ajusta DB_HOST/DB_USERNAME/DB_PASSWORD en phpunit.xml.\n\n");
    }

    exit(1);
}

$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

// El nombre viene de la linea de comandos, asi que se valida en vez de
// interpolarlo a ciegas: `CREATE DATABASE` no admite parametros ligados.
if (preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $base) !== 1) {
    fwrite(STDERR, "  Nombre de base no valido: solo letras, numeros y guion bajo.\n");
    exit(1);
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

echo "\n  Base `{$base}` lista en {$host}:{$puerto} ({$version}).\n";
echo "  Ya puedes ejecutar: php artisan test\n\n";

// Aviso que ahorra un susto: esta base se BORRA entera en cada ejecucion de
// las pruebas (`RefreshDatabase` empieza por `migrate:fresh`). Si alguien
// apunta esto a una base con datos que le importan, los pierde.
if (! in_array($base, ['latam_social_test'], true)) {
    echo "  AVISO: las pruebas hacen `migrate:fresh` sobre `{$base}`.\n";
    echo "  Todo lo que haya ahi se borra en cada ejecucion.\n\n";
}
