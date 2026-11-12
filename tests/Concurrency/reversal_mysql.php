<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') !== '--create-isolated-database') {
    throw new RuntimeException('Se requiere --create-isolated-database.');
}
$nonce = bin2hex(random_bytes(6));
$testDatabase = 'pizzeria_qr_test_'.$nonce;
require __DIR__.'/qr_bootstrap.php';
$cfg = config('database.connections.mysql');
$dsn = 'mysql:host='.$cfg['host'].';port='.(int) $cfg['port'].';charset=utf8mb4';
$admin = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$check = $admin->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
$check->execute([$testDatabase]);
if ((int) $check->fetchColumn() !== 0) {
    throw new RuntimeException('La base de prueba ya existe.');
}
$admin->exec('CREATE DATABASE `'.$testDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
useQrTestDatabase($testDatabase);
if ((int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()')->n !== 0) {
    throw new RuntimeException('La base no está vacía.');
}
DB::statement('CREATE TABLE qr_concurrency_guard (nonce VARCHAR(12) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
DB::table('qr_concurrency_guard')->insert(['nonce' => $nonce]);
if (Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]) !== 0) {
    throw new RuntimeException('No se pudieron aplicar migraciones en la base aislada.');
}
if (DB::select('SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND ENGINE <> ?', ['InnoDB'])) {
    throw new RuntimeException('Se requiere InnoDB.');
}
// Una conexión física distinta verifica únicamente la base aislada recién creada.
$observer = new PDO($dsn.';dbname='.$testDatabase, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($observer->query('SELECT DATABASE()')->fetchColumn() !== $testDatabase || (int) $observer->query('SELECT COUNT(*) FROM qr_concurrency_guard')->fetchColumn() !== 1) {
    throw new RuntimeException('Conexión observadora incorrecta.');
}

function ensureResult(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$process = new Process([
    PHP_BINARY, 'vendor/bin/phpunit', '--no-coverage', '--filter',
    'test_mysql_executes_row_locks_for_the_relevant_reversal_rows|test_mysql_reversal_waits_for_a_concurrent_order_lock',
    'tests/Feature/SettledKitchenDispatchReversalTest.php',
], dirname(__DIR__, 2), [
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $testDatabase,
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $cfg['port'],
    'DB_USERNAME' => $cfg['username'], 'DB_PASSWORD' => $cfg['password'],
    'DB_URL' => '', 'DB_SOCKET' => '', 'REVERSAL_MYSQL_TEST_NONCE' => $nonce,
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BANECO_ENABLED' => 'false', 'BANECO_WEBHOOK_ENABLED' => 'false',
]);
$process->setTimeout(180);
$process->run();
echo 'Isolated InnoDB database: '.$testDatabase.PHP_EOL.$process->getOutput().$process->getErrorOutput();
if (! $process->isSuccessful()) {
    exit(1);
}
