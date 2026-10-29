<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$testDatabase = $testDatabase ?? null;
if (! is_string($testDatabase) || ! preg_match('/^pizzeria_qr_test_[a-f0-9]{12}$/D', $testDatabase)) {
    throw new RuntimeException('Nombre de base de prueba rechazado antes de iniciar Laravel.');
}
if (is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('La prueba exige configuración sin caché.');
}
// El arranque no puede heredar la conexión operativa, incluso en los hijos.
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = ':memory:';
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$connection = config('database.connections.mysql');
if (config('app.env') !== 'testing' || ! in_array($connection['host'], ['127.0.0.1', '::1'], true)
    || ! empty($connection['url']) || ! empty($connection['unix_socket'])) {
    throw new RuntimeException('La prueba exige MySQL TCP local sin DB_URL ni socket.');
}
config(['database.connections.mysql.database' => $testDatabase]);

config(['baneco.enabled' => true, 'baneco.webhook_enabled' => false, 'queue.default' => 'sync', 'cache.default' => 'database']);
Http::preventStrayRequests();

function useQrTestDatabase(string $database, ?string $nonce = null): void
{
    global $testDatabase;
    if ($database !== $testDatabase || ! preg_match('/^pizzeria_qr_test_[a-f0-9]{12}$/D', $database)) {
        throw new RuntimeException('Nombre de base de prueba rechazado.');
    }
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database]);
    DB::purge('mysql');
    if (DB::connection()->getDatabaseName() !== $database || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
        throw new RuntimeException('La conexión no apunta a la base aislada.');
    }
    if ($nonce !== null && DB::table('qr_concurrency_guard')->where('nonce', $nonce)->count() !== 1) {
        throw new RuntimeException('La base no pertenece a esta ejecución.');
    }
}
