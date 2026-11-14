<?php

use Illuminate\Contracts\Console\Kernel;

// This standalone preflight runs before PHPUnit and must never connect to a database.
putenv('DB_URL=');
$_ENV['DB_URL'] = '';
$_SERVER['DB_URL'] = '';

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expected = [
    'app.env' => 'testing',
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:',
    'session.driver' => 'array',
    'cache.default' => 'array',
    'queue.default' => 'sync',
    'baneco.enabled' => false,
    'baneco.webhook_enabled' => false,
];
foreach ($expected as $key => $value) {
    if (config($key) !== $value) {
        fwrite(STDERR, "Configuración de prueba insegura: {$key}.\n");
        exit(1);
    }
}
if (config('database.connections.sqlite.url')) {
    fwrite(STDERR, "La URL de SQLite debe estar vacía.\n");
    exit(1);
}
echo "Configuración aislada comprobada: SQLite en memoria, servicios bancarios desactivados.\n";
