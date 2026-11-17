<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'masa_qr_partial_test' || ! preg_match('/^\d+$/D', getenv('DB_PORT') ?: '')
    || getenv('DB_URL') || ! preg_match('/^masa-online-mariadb-[a-f0-9]{32}$/D', getenv('ONLINE_TEST_MARKER') ?: '')) {
    throw new RuntimeException('Configuración aislada rechazada.');
}
if (is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Configuración cacheada no permitida.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$server = DB::selectOne('SELECT DATABASE() AS db, @@port AS port, @@datadir AS datadir');
if ($server->db !== 'masa_qr_partial_test' || (string) $server->port !== getenv('DB_PORT')
    || ! str_contains(strtolower($server->datadir), strtolower(getenv('ONLINE_TEST_MARKER')))
    || DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()')->n !== 0) {
    throw new RuntimeException('Servidor o base aislada no coinciden.');
}
DB::unprepared('CREATE TABLE online_qr_dispositions (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
DB::unprepared("CREATE TRIGGER online_qr_dispositions_no_delete BEFORE DELETE ON online_qr_dispositions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'existing test trigger'");
$migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_17_180000_protect_online_qr_dispositions.php';
$failed = false;
try {
    $migration->up();
} catch (Throwable) {
    $failed = true;
}
$triggers = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $server->db)
    ->whereIn('TRIGGER_NAME', ['online_qr_dispositions_no_update', 'online_qr_dispositions_no_delete'])->pluck('TRIGGER_NAME')->all();
if (! $failed || count($triggers) !== 2) {
    throw new RuntimeException('No se observó el fallo parcialmente aplicado.');
}
$retryFailed = false;
try {
    $migration->up();
} catch (Throwable) {
    $retryFailed = true;
}
if (! $retryFailed) {
    throw new RuntimeException('El reintento no debería ser ciego sobre DDL parcial.');
}
// Only this freshly created, verified fixture may be repaired this way. Never
// apply this example to a real database without a reviewed recovery plan.
DB::unprepared('DROP TRIGGER online_qr_dispositions_no_update');
DB::unprepared('DROP TRIGGER online_qr_dispositions_no_delete');
$migration->up();
DB::table('online_qr_dispositions')->insert(['id' => 1]);
foreach (['UPDATE online_qr_dispositions SET id = 2 WHERE id = 1', 'DELETE FROM online_qr_dispositions WHERE id = 1'] as $sql) {
    try {
        DB::unprepared($sql);
        throw new RuntimeException('La protección inmutable no rechazó la modificación.');
    } catch (QueryException) {
        // Expected: both restored triggers block changes.
    }
}
if (DB::table('online_qr_dispositions')->count() !== 1) {
    throw new RuntimeException('La fila de auditoría no se conservó.');
}
echo "PASS: fallo DDL parcial detectado; recuperación aislada y ambos triggers verificados.\n";
