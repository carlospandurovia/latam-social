<?php

/**
 * Añade a composer.json lo que es propio de LATAM Social:
 *   - autoload PSR-4 de app/Modules y app/Shared
 *   - atajos de calidad equivalentes a lo que corre CI
 *   - requisito de PHP 8.3
 *
 * Idempotente: se puede ejecutar las veces que haga falta.
 * Lo lanza tools/bootstrap-laravel.ps1, pero se puede correr suelto:
 *     php tools/patch-composer.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root . '/composer.json';

if (! is_file($file)) {
    fwrite(STDERR, "No encuentro composer.json. Ejecuta antes el bootstrap.\n");
    exit(1);
}

$json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$cambios = [];

// --- PHP 8.3 como mínimo (DEC-001) ---------------------------------------
if (($json['require']['php'] ?? '') !== '^8.3') {
    $json['require']['php'] = '^8.3';
    $cambios[] = 'require.php = ^8.3';
}

// --- Autoload de los módulos ---------------------------------------------
$psr4 = $json['autoload']['psr-4'] ?? [];
foreach (['App\\Modules\\' => 'app/Modules/', 'App\\Shared\\' => 'app/Shared/'] as $ns => $path) {
    if (($psr4[$ns] ?? null) !== $path) {
        $psr4[$ns] = $path;
        $cambios[] = "autoload {$ns}";
    }
}
$json['autoload']['psr-4'] = $psr4;

// --- Atajos ---------------------------------------------------------------
$scripts = [
    'arch'    => 'vendor/bin/deptrac analyse --fail-on-uncovered --report-uncovered',
    'lint'    => 'vendor/bin/pint --test',
    'fix'     => 'vendor/bin/pint',
    'stan'    => 'vendor/bin/phpstan analyse --no-progress',
    'quality' => [
        '@lint',
        '@stan',
        '@arch',
        '@php artisan test',
    ],
];
foreach ($scripts as $name => $cmd) {
    if (($json['scripts'][$name] ?? null) !== $cmd) {
        $json['scripts'][$name] = $cmd;
        $cambios[] = "script {$name}";
    }
}

// --- Descripción ----------------------------------------------------------
$meta = [
    'name'        => 'latam-social/plataforma',
    'description' => 'LATAM Social — plataforma de Creator Marketing',
    'license'     => 'proprietary',
];
foreach ($meta as $k => $v) {
    if (($json[$k] ?? null) !== $v) {
        $json[$k] = $v;
        $cambios[] = $k;
    }
}

if ($cambios === []) {
    echo "   composer.json ya estaba configurado\n";
    exit(0);
}

file_put_contents(
    $file,
    json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

echo '   composer.json actualizado: ' . implode(', ', $cambios) . "\n";
