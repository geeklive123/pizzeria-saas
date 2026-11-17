<?php

use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CreateOnlineOrderRequestAction;
use App\Actions\DynamicQrAction;
use App\Enums\InventoryMovementType;
use App\Enums\ProductType;
use App\Models\BankQrSetting;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\EmitOnlineDynamicQr;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'masa_qr_status_validation_test' || ! preg_match('/^\d+$/D', getenv('DB_PORT') ?: '')
    || getenv('DB_URL') || ! preg_match('/^masa-online-mariadb-[a-f0-9]{32}$/D', getenv('ONLINE_TEST_MARKER') ?: '')) {
    throw new RuntimeException('Configuración aislada rechazada.');
}
if (is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Configuración cacheada no permitida.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, 'Falló la prueba aislada: '.get_class($exception).PHP_EOL);
    exit(1);
});
$server = DB::selectOne('SELECT DATABASE() AS db, @@port AS port, @@datadir AS datadir, VERSION() AS version');
if ($server->db !== 'masa_qr_status_validation_test' || (string) $server->port !== getenv('DB_PORT')
    || ! str_contains(strtolower($server->datadir), strtolower(getenv('ONLINE_TEST_MARKER')))
    || ! str_starts_with($server->version, '10.4.32-MariaDB')) {
    throw new RuntimeException('Servidor aislado no coincide.');
}
if (DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'")) {
    throw new RuntimeException('La prueba exige tablas InnoDB.');
}
config(['storefront.online_dynamic_qr_enabled' => false, 'storefront.delivery_payment_preparation_enabled' => false,
    'storefront.domains' => [], 'cache.default' => 'array', 'session.driver' => 'array',
    'app.key' => 'base64:'.base64_encode(str_repeat('D', 32)), 'app.cipher' => 'AES-256-CBC', 'app.previous_keys' => []]);
$app->forgetInstance('encrypter');
Http::allowStrayRequests([]);
Http::preventStrayRequests();

function queryStatus(int $requestId, int $companyId, int $branchId): array
{
    config(['storefront.default.company_id' => $companyId, 'storefront.default.branch_id' => $branchId]);
    $key = DB::table('online_order_requests')->where('id', $requestId)->value('idempotency_key');
    $url = '/tienda/checkout/preparacion/qr/estado';
    $request = Request::create($url, 'POST', [], [], [], ['HTTP_HOST' => 'localhost', 'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json'], json_encode(['idempotency_key' => $key], JSON_THROW_ON_ERROR));
    $response = app(HttpKernel::class)->handle($request);
    if (str_contains($request->getRequestUri(), (string) $key) || str_contains($response->getContent(), (string) $key)) {
        throw new RuntimeException('La capacidad apareció en una URL o respuesta.');
    }

    return ['code' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true),
        'cache' => (string) $response->headers->get('Cache-Control')];
}

if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($data['ready'], 'ready');
    while (! is_file($data['barrier'])) {
        usleep(1000);
    }
    echo json_encode(queryStatus($data['request'], $data['company'], $data['branch']), JSON_THROW_ON_ERROR);
    exit;
}
if (($argv[1] ?? '') !== '--run-isolated') {
    throw new RuntimeException('Se requiere --run-isolated.');
}
if (DB::table('dynamic_qr_intents')->count() !== 0 || DB::table('online_order_requests')->count() !== 0) {
    throw new RuntimeException('La base de esta prueba debe estar recién migrada.');
}
$company = Company::factory()->create();
$branch = Branch::factory()->for($company)->create();
$category = Category::factory()->for($company)->create();
$product = Product::factory()->for($category)->create(['type' => ProductType::Beverage, 'is_active' => true]);
$variant = ProductVariant::factory()->for($product)->create(['price' => '12.00', 'requires_preparation' => false]);
$user = User::factory()->create();
Membership::factory()->for($company)->for($user)->owner()->create();
$register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Prueba aislada', 'is_active' => true]);
CashSession::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id,
    'active_cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_amount' => '0.00', 'status' => 'open', 'opened_at' => now()]);
$unit = Unit::factory()->for($company)->create();
$inventory = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id,
    'product_variant_id' => $variant->id, 'name' => 'Inventario aislado', 'is_active' => true]);
app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventory, InventoryMovementType::AdjustmentIn,
    '2.000', '1.000000', $user, reason: 'Prueba de consulta');
config(['storefront.delivery_payment_preparation_enabled' => true, 'storefront.online_dynamic_qr_enabled' => true,
    'baneco.enabled' => true, 'storefront.visibility' => ['category_names' => [], 'category_ulids' => [$category->ulid], 'product_names' => [], 'product_ulids' => []],
    'storefront.delivery.branches' => [$company->id.':'.$branch->id => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]]]);
BankQrSetting::query()->create(['company_id' => $company->id, 'active_scope' => $company->id.':company',
    'credentials' => ['username' => 'fake', 'password' => 'fake', 'aes_key' => 'fake', 'account_credit' => 'fake']]);
$paid = false;
Http::fake(function ($request) use (&$paid) {
    if (str_contains($request->url(), 'authentication/encrypt')) {
        return Http::response('"encrypted"');
    }
    if (str_contains($request->url(), 'authentication/authenticate')) {
        return Http::response(['responseCode' => 0, 'token' => 'fake-token']);
    }
    if (str_contains($request->url(), 'generateQR')) {
        return Http::response(['responseCode' => 0, 'qrId' => 'status-test-bank', 'qrImage' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=']);
    }
    if (str_contains($request->url(), 'statusQR')) {
        return Http::response(['responseCode' => 0, 'statusQrCode' => $paid ? 1 : 0,
            'payment' => ['qrId' => 'status-test-bank', 'amount' => '12.00', 'currency' => 'BOB', 'transactionId' => 'fake-payment']]);
    }
    throw new RuntimeException('Petición bancaria simulada inesperada.');
});
$payload = ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente aislado', 'customer_phone' => '70000000', 'fulfillment' => 'delivery',
    'address' => 'Calle de prueba', 'reference' => 'Referencia', 'latitude' => -17.3769946, 'longitude' => -66.1771762, 'location_confirmed' => true,
    'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]];
$preparation = app(CreateOnlineOrderRequestAction::class)->execute($company, $branch, $payload, true);
$seed = app(EmitOnlineDynamicQr::class)->execute($company, $branch, $preparation);
$paid = true;
app(DynamicQrAction::class)->check($seed);
config(['storefront.online_dynamic_qr_enabled' => false, 'storefront.delivery_payment_preparation_enabled' => false]);
$fixture = DB::table('online_qr_operations as operation')->join('dynamic_qr_intents as intent', 'intent.id', '=', 'operation.dynamic_qr_intent_id')
    ->join('online_order_requests as request', 'request.id', '=', 'operation.online_order_request_id')
    ->where('intent.status', 'paid')->select('operation.id as operation_id', 'operation.company_id', 'operation.branch_id',
        'operation.pos_order_id', 'operation.original_cashier_id', 'request.id as request_id', 'intent.id as intent_id')->first();
if (! $fixture) {
    throw new RuntimeException('Falta la preparación pagada aislada del runner online.');
}
$before = ['payments' => DB::table('payments')->count(), 'prints' => DB::table('print_attempts')->count(),
    'movements' => DB::table('inventory_movements')->count(), 'intents' => DB::table('dynamic_qr_intents')->count()];
$bankCallsBefore = Http::recorded()->count();
$barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-status-'.bin2hex(random_bytes(12));
$common = ['request' => $fixture->request_id, 'company' => $fixture->company_id, 'branch' => $fixture->branch_id, 'barrier' => $barrier];
$workers = [];
foreach (['a', 'b'] as $suffix) {
    $worker = new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($common + ['ready' => $barrier.'.'.$suffix], JSON_THROW_ON_ERROR))]);
    $worker->start();
    $workers[] = $worker;
}
$deadline = microtime(true) + 30;
while ((! is_file($barrier.'.a') || ! is_file($barrier.'.b')) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($barrier.'.a') || ! is_file($barrier.'.b')) {
    throw new RuntimeException('Workers no preparados.');
}
file_put_contents($barrier, 'go');
foreach ($workers as $worker) {
    $worker->wait();
    if (! $worker->isSuccessful()) {
        throw new RuntimeException('Falló la consulta concurrente: '.$worker->getErrorOutput());
    }
    $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    if ($result['code'] !== 200 || $result['body'] !== ['status' => 'paid'] || ! str_contains($result['cache'], 'no-store')) {
        throw new RuntimeException('La consulta concurrente devolvió un resultado inesperado.');
    }
}
$otherBranch = Branch::factory()->for(Company::findOrFail($fixture->company_id))->create();
$otherCompany = Company::factory()->create();
$otherCompanyBranch = Branch::factory()->for($otherCompany)->create();
foreach ([[$fixture->company_id, $otherBranch->id], [$otherCompany->id, $otherCompanyBranch->id]] as [$companyId, $branchId]) {
    if (queryStatus($fixture->request_id, $companyId, $branchId)['code'] !== 404) {
        throw new RuntimeException('Se permitió una consulta entre ámbitos.');
    }
}
$unrelated = Order::factory()->for(Branch::findOrFail($fixture->branch_id))->create(['company_id' => $fixture->company_id, 'created_by' => $fixture->original_cashier_id]);
DB::transaction(function () use ($fixture, $unrelated): void {
    DB::table('dynamic_qr_intents')->where('id', $fixture->intent_id)->update(['order_id' => $unrelated->id]);
    if (queryStatus($fixture->request_id, $fixture->company_id, $fixture->branch_id)['code'] !== 404) {
        throw new RuntimeException('Se aceptó una intención de otra orden.');
    }
    DB::table('dynamic_qr_intents')->where('id', $fixture->intent_id)->update(['order_id' => $fixture->pos_order_id]);
});
foreach (['pending' => 'pending', 'paid' => 'paid', 'reconciliation_required' => 'review', 'generation_unknown' => 'review'] as $stored => $expected) {
    DB::transaction(function () use ($fixture, $stored, $expected): void {
        $previous = DB::table('dynamic_qr_intents')->where('id', $fixture->intent_id)->value('status');
        DB::table('dynamic_qr_intents')->where('id', $fixture->intent_id)->update(['status' => $stored]);
        $result = queryStatus($fixture->request_id, $fixture->company_id, $fixture->branch_id);
        if ($result['code'] !== 200 || $result['body'] !== ['status' => $expected]) {
            throw new RuntimeException('No se recuperó el estado esperado.');
        }
        DB::table('dynamic_qr_intents')->where('id', $fixture->intent_id)->update(['status' => $previous]);
    });
}
$after = ['payments' => DB::table('payments')->count(), 'prints' => DB::table('print_attempts')->count(),
    'movements' => DB::table('inventory_movements')->count(), 'intents' => DB::table('dynamic_qr_intents')->count()];
if ($before !== $after || Http::recorded()->count() !== $bankCallsBefore) {
    throw new RuntimeException('Las consultas alteraron efectos financieros.');
}
echo "PASS: dos consultas simultáneas, cuatro estados y aislamiento por empresa, sucursal y orden; sin efectos adicionales.\n";
