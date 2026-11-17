<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Standalone development harness. This file is outside public/ and has no app route.
if (! in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
    throw new RuntimeException('CLI only');
}
$root = dirname(__DIR__, 2);
$expectedRoot = realpath('C:/Dev/masa-mana-tienda-fotos');
$dotenv = is_file($root.'/.env') ? file_get_contents($root.'/.env') : '';
if (PHP_OS_FAMILY !== 'Windows' || ! $expectedRoot || realpath($root) !== $expectedRoot
    || strtolower((string) getenv('APP_ENV')) === 'production'
    || preg_match('/^\s*APP_ENV\s*=\s*["\']?production["\']?\s*$/mi', $dotenv)) {
    throw new RuntimeException('Local demo is forbidden in production');
}
$dir = getenv('ONLINE_QR_DEMO_DIR');
$tmp = realpath($root.'/tmp');
$dir = $dir ? realpath($dir) : false;
if (! $tmp || ! $dir || ! str_starts_with(strtolower($dir), strtolower($tmp.DIRECTORY_SEPARATOR))
    || ! preg_match('/^online-qr-demo-[a-f0-9]{16}$/', basename($dir)) || ! is_file($dir.'/demo.marker')) {
    throw new RuntimeException('Invalid isolated demo directory');
}
if (PHP_SAPI === 'cli-server' && ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
$database = $dir.'/demo.sqlite';
if (! is_file($database) || is_link($database)) {
    throw new RuntimeException('Isolated demo database missing');
}
$env = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('D', 32)),
    'APP_URL' => 'http://127.0.0.1:18765', 'APP_CONFIG_CACHE' => $dir.'/never-config-cache.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync', 'BCRYPT_ROUNDS' => '4',
    'BANECO_ENABLED' => 'false', 'BANECO_WEBHOOK_ENABLED' => 'false', 'LOG_CHANNEL' => 'stderr'];
foreach ($env as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
    'database.connections.sqlite.url' => null, 'session.driver' => 'file', 'session.files' => $dir.'/sessions',
    'cache.default' => 'array', 'queue.default' => 'sync', 'app.key' => $env['APP_KEY'],
    'storefront.demo_mode' => false, 'storefront.delivery_payment_preparation_enabled' => true,
    'storefront.online_dynamic_qr_enabled' => true, 'baneco.enabled' => true, 'baneco.poll_seconds' => 0,
    'storefront.default' => ['company_id' => 1, 'branch_id' => 1],
    'storefront.delivery.branches' => ['1:1' => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]]]);
DB::purge();
if (! $app->runningUnitTests() || DB::connection()->getDriverName() !== 'sqlite'
    || DB::connection()->getDatabaseName() !== $database) {
    throw new RuntimeException('Unsafe demo configuration');
}

return $app;
