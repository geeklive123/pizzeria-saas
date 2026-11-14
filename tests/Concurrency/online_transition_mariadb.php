<?php

// Uses only a new, explicitly identified local test server and database.
use App\Http\Controllers\OnlineOrderRequestController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\OnlineOrderRequest;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'masa_online_concurrency_test'
    || ! preg_match('/^\d+$/D', getenv('DB_PORT') ?: '') || getenv('DB_URL')
    || ! preg_match('/^masa-online-mariadb-[a-f0-9]{32}$/D', getenv('ONLINE_TEST_MARKER') ?: '')) {
    throw new RuntimeException('Configuracion aislada rechazada.');
}
if (is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Configuracion cacheada no permitida.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
$server = DB::selectOne('SELECT DATABASE() AS db, @@port AS port, @@datadir AS datadir');
if ($server->db !== 'masa_online_concurrency_test' || (string) $server->port !== getenv('DB_PORT')
    || ! str_contains(strtolower($server->datadir), strtolower(getenv('ONLINE_TEST_MARKER')))) {
    throw new RuntimeException('Servidor o directorio temporal no coinciden.');
}

if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    $company = Company::query()->findOrFail($data['company']);
    $branch = Branch::query()->findOrFail($data['branch']);
    $user = User::query()->findOrFail($data['user']);
    app(CompanyContext::class)->setForUser($user, $company);
    app(BranchContext::class)->set($company, $branch);
    Auth::setUser($user);
    $request = Request::create('/online-orders/'.$data['order'].'/transition', 'POST', ['status' => $data['status']]);
    $request->setUserResolver(fn () => $user);
    app('session')->start();
    file_put_contents($data['ready'], 'ready');
    while (! is_file($data['barrier'])) {
        usleep(1000);
    }
    $response = app(OnlineOrderRequestController::class)->transition($request, (string) $data['order']);
    echo json_encode(['redirect' => $response->getStatusCode(), 'notice' => session()->get('online_order_notice'), 'user' => $user->id], JSON_THROW_ON_ERROR);
    exit;
}
if (($argv[1] ?? '') !== '--run-isolated') {
    throw new RuntimeException('Se requiere --run-isolated.');
}
function verifyTransition(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}
$company = Company::factory()->create();
$branch = Branch::factory()->for($company)->create();
$users = [User::factory()->create(), User::factory()->create()];
foreach ($users as $user) {
    Membership::factory()->for($company)->for($user)->owner()->create();
}
$barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-online-transition-'.bin2hex(random_bytes(12));
foreach ([['confirmed', 'confirmed'], ['cancelled', 'cancelled'], ['confirmed', 'cancelled'], ['cancelled', 'confirmed']] as [$first, $second]) {
    for ($i = 0; $i < 3; $i++) {
        $order = OnlineOrderRequest::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'customer_name' => 'Cliente prueba',
            'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'status' => 'pending_confirmation', 'subtotal' => '12.00', 'total' => '12.00']);
        $base = ['company' => $company->id, 'branch' => $branch->id, 'order' => $order->id, 'barrier' => $barrier];
        $make = fn ($user, $status, $ready) => new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($base + ['user' => $user->id, 'status' => $status, 'ready' => $ready], JSON_THROW_ON_ERROR))]);
        $a = $make($users[0], $first, $barrier.'.a');
        $b = $make($users[1], $second, $barrier.'.b');
        $a->start();
        $b->start();
        $deadline = microtime(true) + 30;
        while ((! is_file($barrier.'.a') || ! is_file($barrier.'.b')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        verifyTransition(is_file($barrier.'.a') && is_file($barrier.'.b'), 'Workers no preparados.');
        file_put_contents($barrier, 'go');
        $a->wait();
        $b->wait();
        unlink($barrier);
        unlink($barrier.'.a');
        unlink($barrier.'.b');
        verifyTransition($a->isSuccessful() && $b->isSuccessful(), 'Error de worker: '.$a->getErrorOutput().' '.$b->getErrorOutput().' '.$a->getOutput().' '.$b->getOutput());
        $results = [json_decode($a->getOutput(), true, flags: JSON_THROW_ON_ERROR), json_decode($b->getOutput(), true, flags: JSON_THROW_ON_ERROR)];
        verifyTransition($results[0]['redirect'] === 302 && $results[1]['redirect'] === 302, 'Respuesta distinta de redireccion.');
        $winners = array_values(array_filter($results, fn (array $result): bool => $result['notice'] === null));
        $losers = array_values(array_filter($results, fn (array $result): bool => $result['notice'] === 'Esta solicitud ya fue atendida. Se conserva el estado actual.'));
        verifyTransition(count($winners) === 1 && count($losers) === 1, 'Aviso o ganador incorrecto.');
        $order->refresh();
        $expectedStatus = $winners[0]['user'] === $users[0]->id ? $first : $second;
        verifyTransition($order->status === $expectedStatus && $order->resolved_by === $winners[0]['user'] && $order->resolved_at !== null, 'Estado o auditoria modificados.');
    }
}
echo "PASS: 12 carreras entre dos administradores; estado, aviso y auditoria conservados.\n";
