<?php

// This harness only operates on a deliberately initialized local temporary server.
use App\Actions\CreateOnlineOrderRequestAction;
use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\OnlineOrderRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'masa_online_concurrency_test'
    || ! preg_match('/^\d+$/D', getenv('DB_PORT') ?: '') || getenv('DB_URL')
    || ! preg_match('/masa-online-mariadb-[a-f0-9]{32}/D', getenv('ONLINE_TEST_MARKER') ?: '')) {
    throw new RuntimeException('Configuracion de base aislada rechazada.');
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
    $input = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    config()->set('storefront.visibility', ['category_names' => [], 'category_ulids' => [$input['category']], 'product_names' => [], 'product_ulids' => []]);
    $company = Company::query()->findOrFail($input['company']);
    $branch = Branch::query()->findOrFail($input['branch']);
    file_put_contents($input['ready'], 'ready');
    while (! is_file($input['barrier'])) {
        usleep(1000);
    }
    try {
        $action = app(CreateOnlineOrderRequestAction::class);
        $result = $input['operation'] === 'resolve'
            ? $action->resolve($company, $branch, $input['payload']['idempotency_key'])
            : $action->execute($company, $branch, $input['payload']);
        echo json_encode(['result' => $result->is_fence ? 'fenced' : 'created', 'id' => $result->id], JSON_THROW_ON_ERROR);
    } catch (ValidationException) {
        echo json_encode(['result' => 'conflict'], JSON_THROW_ON_ERROR);
    }
    exit;
}
if (($argv[1] ?? '') !== '--run-isolated') {
    throw new RuntimeException('Se requiere --run-isolated.');
}
function verify(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}
$company = Company::factory()->create();
$branch = Branch::factory()->for($company)->create();
$category = Category::factory()->for($company)->create();
$product = Product::factory()->for($category)->create(['type' => ProductType::Pizza]);
$variant = ProductVariant::factory()->for($product)->create(['price' => '12.50']);
$barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-online-barrier-'.bin2hex(random_bytes(12));
$run = function (string $left, string $right, bool $different = false) use ($company, $branch, $category, $variant, $barrier): array {
    $key = (string) Str::uuid();
    $payload = ['idempotency_key' => $key, 'customer_name' => 'Cliente prueba', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 2]]];
    $base = ['company' => $company->id, 'branch' => $branch->id, 'category' => $category->ulid, 'barrier' => $barrier];
    $make = fn ($operation, $data, $ready) => new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($base + ['operation' => $operation, 'payload' => $data, 'ready' => $ready], JSON_THROW_ON_ERROR))]);
    $changed = $payload;
    if ($different) {
        $changed['customer_name'] = 'Cliente diferente';
    }
    $a = $make($left, $payload, $barrier.'.a');
    $b = $make($right, $changed, $barrier.'.b');
    $a->start();
    $b->start();
    $deadline = microtime(true) + 30;
    while ((! is_file($barrier.'.a') || ! is_file($barrier.'.b')) && microtime(true) < $deadline) {
        usleep(10000);
    }
    verify(is_file($barrier.'.a') && is_file($barrier.'.b'), 'Workers no preparados.');
    file_put_contents($barrier, 'go');
    $a->wait();
    $b->wait();
    unlink($barrier);
    unlink($barrier.'.a');
    unlink($barrier.'.b');
    verify($a->isSuccessful() && $b->isSuccessful(), 'Error de worker: '.$a->getErrorOutput().' '.$b->getErrorOutput());
    $results = [json_decode($a->getOutput(), true, flags: JSON_THROW_ON_ERROR), json_decode($b->getOutput(), true, flags: JSON_THROW_ON_ERROR)];
    $rows = OnlineOrderRequest::query()->where('idempotency_key', $key)->with('items')->get();
    verify($rows->count() === 1, 'Clave duplicada o perdida.');
    verify($rows[0]->items->count() === ($rows[0]->is_fence ? 0 : 1), 'Lineas duplicadas o incompletas.');
    if (! $rows[0]->is_fence) {
        verify($rows[0]->subtotal === '25.00', 'Monto incorrecto.');
    }

    return [$results, $rows[0]];
};
for ($i = 0; $i < 5; $i++) {
    [$results, $row] = $run('create', 'create');
    verify($results[0]['result'] === 'created' && $results[1]['result'] === 'created' && $results[0]['id'] === $results[1]['id'], 'Reintento no idempotente.');
    [$results, $row] = $run('create', 'create', true);
    $states = array_column($results, 'result');
    sort($states);
    verify($states === ['conflict', 'created'], 'Contenido distinto no rechazado.');
    [$results, $row] = $run('create', 'resolve');
    verify($row->is_fence ? in_array('conflict', array_column($results, 'result'), true) : $row->items->count() === 1, 'Carrera resolver/crear inconsistente.');
}
$row = OnlineOrderRequest::query()->where('company_id', $company->id)->firstOrFail();
$duplicateRejected = false;
try {
    $row->replicate()->save();
} catch (QueryException) {
    $duplicateRejected = true;
}
verify($duplicateRejected, 'El indice unico no rechazo la clave duplicada.');
$rollbackKey = (string) Str::uuid();
try {
    DB::transaction(function () use ($row, $rollbackKey): void {
        $copy = $row->replicate();
        $copy->idempotency_key = $rollbackKey;
        $copy->save();
        $copy->items()->create(['company_id' => $copy->company_id, 'branch_id' => $copy->branch_id, 'product_variant_id' => 999999999, 'product_name' => 'invalido', 'variant_name' => 'invalido', 'quantity' => 1, 'unit_price' => '1.00', 'subtotal' => '1.00']);
    });
} catch (QueryException) {
    // The invalid foreign key must roll back the parent insert as well.
}
verify(! OnlineOrderRequest::query()->where('idempotency_key', $rollbackKey)->exists(), 'Transaccion parcial persistida.');
echo "PASS: 15 carreras entre procesos; indice unico e integridad transaccional verificados.\n";
