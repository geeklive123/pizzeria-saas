<?php

use App\Actions\DynamicQrAction;
use App\Enums\DynamicQrStatus;
use App\Models\DynamicQrIntent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

[$script, $baseline, $database, $nonce, $intentId] = $argv;
$baseline = realpath($baseline);
$workspace = realpath(dirname(__DIR__, 2));
if (! $baseline || ! str_starts_with(strtolower($baseline), strtolower($workspace.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'qr-baseline-'))
    || ! preg_match('/^pizzeria_qr_test_[a-f0-9]{12}$/D', $database)
    || ! preg_match('/^[a-f0-9]{12}$/D', $nonce) || getenv('DB_HOST') !== '127.0.0.1') {
    throw new RuntimeException('Configuración de reversión aislada rechazada.');
}
require $workspace.'/vendor/autoload.php';
spl_autoload_register(function (string $class) use ($baseline): void {
    if (str_starts_with($class, 'App\\')) {
        $path = $baseline.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($path)) {
            require $path;
        }
    }
}, prepend: true);
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=mysql');
putenv('DB_DATABASE='.$database);
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'mysql';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;
$app = require $baseline.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (realpath((new ReflectionClass(DynamicQrAction::class))->getFileName()) !== realpath($baseline.'/app/Actions/DynamicQrAction.php')) {
    throw new RuntimeException('La acción bancaria no corresponde a la versión base aislada.');
}
$server = DB::selectOne('SELECT DATABASE() AS db, @@datadir AS datadir');
if ($server->db !== $database || ! str_contains(strtolower($server->datadir), 'masa-qr-pos-safety-')
    || DB::table('qr_concurrency_guard')->where('nonce', $nonce)->count() !== 1) {
    throw new RuntimeException('La reversión no apunta a la base aislada creada para el ensayo.');
}
Http::preventStrayRequests();
Http::fake(function ($request) {
    if (str_contains($request->url(), 'authentication/encrypt')) {
        return Http::response('"fake"');
    }
    if (str_contains($request->url(), 'authentication/authenticate')) {
        return Http::response(['responseCode' => 0, 'token' => 'fake']);
    }
    if (str_contains($request->url(), 'statusQR/')) {
        $qr = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));

        return Http::response(['responseCode' => 0, 'statusQrCode' => 1, 'payment' => ['qrId' => $qr, 'amount' => '80.00', 'currency' => 'BOB', 'transactionId' => 'simulated-rollback']]);
    }
    throw new RuntimeException('Solicitud bancaria no simulada.');
});
config(['baneco.enabled' => true]);
$intent = DynamicQrIntent::query()->findOrFail((int) $intentId);
if (app(DynamicQrAction::class)->check($intent)->status !== DynamicQrStatus::Paid) {
    throw new RuntimeException('La versión anterior no confirmó la intención simulada.');
}
echo "PASS: versión base confirmó el POS en la base aislada.\n";
