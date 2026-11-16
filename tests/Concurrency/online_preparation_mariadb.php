<?php

use App\Actions\CloseCashSessionAction;
use App\Actions\CreateOnlineOrderRequestAction;
use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Company;
use App\Models\Membership;
use App\Models\OnlineOrderRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OnlinePaymentPreparationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'masa_preparation_concurrency_test' || ! preg_match('/^\d+$/D', getenv('DB_PORT') ?: '')
    || getenv('DB_URL') || ! preg_match('/^masa-online-mariadb-[a-f0-9]{32}$/D', getenv('ONLINE_TEST_MARKER') ?: '')) {
    throw new RuntimeException('Configuración aislada rechazada.');
}
if (is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Configuración cacheada no permitida.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
});
Http::preventStrayRequests();
$server = DB::selectOne('SELECT DATABASE() AS db, @@port AS port, @@datadir AS datadir');
if ($server->db !== 'masa_preparation_concurrency_test' || (string) $server->port !== getenv('DB_PORT')
    || ! str_contains(strtolower($server->datadir), strtolower(getenv('ONLINE_TEST_MARKER')))) {
    throw new RuntimeException('Servidor o directorio temporal no coinciden.');
}
config()->set('storefront.delivery_payment_preparation_enabled', true);

if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    $company = Company::query()->findOrFail($data['company']);
    $branch = Branch::query()->findOrFail($data['branch']);
    config()->set('storefront.visibility', ['category_names' => [], 'category_ulids' => [$data['category']], 'product_names' => [], 'product_ulids' => []]);
    config()->set('storefront.delivery.branches', [$company->id.':'.$branch->id => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]]);
    file_put_contents($data['ready'], 'ready');
    while (! is_file($data['barrier'])) {
        usleep(1000);
    }
    if (($data['operation'] ?? 'prepare') === 'close') {
        $session = CashSession::query()->findOrFail($data['session']);
        $user = User::query()->findOrFail($data['user']);
        app(CloseCashSessionAction::class)->execute($session, '0.00', $user);
        echo json_encode(['closed' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    try {
        $preparation = app(CreateOnlineOrderRequestAction::class)->execute($company, $branch, $data['payload'], true);
        app(OnlinePaymentPreparationService::class)->assertUsable($company, $branch, $preparation);
        echo json_encode(['id' => $preparation->id, 'total' => $preparation->total], JSON_THROW_ON_ERROR);
    } catch (ValidationException) {
        echo json_encode(['unavailable' => true], JSON_THROW_ON_ERROR);
    }
    exit;
}
if (($argv[1] ?? '') !== '--run-isolated') {
    throw new RuntimeException('Se requiere --run-isolated.');
}
$company = Company::factory()->create();
$branch = Branch::factory()->for($company)->create();
$category = Category::factory()->for($company)->create();
$product = Product::factory()->for($category)->create(['type' => ProductType::Beverage]);
$variant = ProductVariant::factory()->for($product)->create(['price' => '12.00', 'requires_preparation' => false]);
$user = User::factory()->create();
Membership::factory()->for($company)->for($user)->owner()->create();
$register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja aislada', 'is_active' => true]);
$initialSession = CashSession::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'active_cash_register_id' => $register->id,
    'opened_by' => $user->id, 'opening_amount' => '0.00', 'status' => 'open', 'opened_at' => now()]);
for ($i = 0; $i < 5; $i++) {
    $key = (string) Str::uuid();
    $payload = ['idempotency_key' => $key, 'customer_name' => 'Cliente prueba', 'customer_phone' => '70000000', 'fulfillment' => 'delivery',
        'address' => 'Calle prueba', 'reference' => 'Referencia', 'latitude' => -17.3769946, 'longitude' => -66.1771762, 'location_confirmed' => true,
        'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 2]]];
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-prep-'.bin2hex(random_bytes(12));
    $common = ['company' => $company->id, 'branch' => $branch->id, 'category' => $category->ulid, 'barrier' => $barrier, 'payload' => $payload];
    $worker = fn ($ready) => new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($common + ['ready' => $ready], JSON_THROW_ON_ERROR))]);
    $a = $worker($barrier.'.a');
    $b = $worker($barrier.'.b');
    $a->start();
    $b->start();
    $deadline = microtime(true) + 30;
    while ((! is_file($barrier.'.a') || ! is_file($barrier.'.b')) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($barrier.'.a') || ! is_file($barrier.'.b')) {
        throw new RuntimeException('Workers no preparados.');
    }
    file_put_contents($barrier, 'go');
    $a->wait();
    $b->wait();
    unlink($barrier);
    unlink($barrier.'.a');
    unlink($barrier.'.b');
    if (! $a->isSuccessful() || ! $b->isSuccessful()) {
        throw new RuntimeException('Falló un worker: '.$a->getErrorOutput().' '.$b->getErrorOutput());
    }
    $left = json_decode($a->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $right = json_decode($b->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    if ($left !== $right || $left['total'] !== '24.00' || OnlineOrderRequest::query()->where('idempotency_key', $key)->count() !== 1) {
        throw new RuntimeException('Resultado duplicado o inconsistente.');
    }
}
$initialSession->update(['active_cash_register_id' => null, 'status' => 'closed', 'closed_at' => now()]);
for ($i = 0; $i < 3; $i++) {
    $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Cierre '.$i, 'is_active' => true]);
    $session = CashSession::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id,
        'active_cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_amount' => '0.00', 'status' => 'open', 'opened_at' => now()]);
    $key = (string) Str::uuid();
    $payload = ['idempotency_key' => $key, 'customer_name' => 'Cliente prueba', 'customer_phone' => '70000000', 'fulfillment' => 'delivery',
        'address' => 'Calle prueba', 'reference' => 'Referencia', 'latitude' => -17.3769946, 'longitude' => -66.1771762, 'location_confirmed' => true,
        'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 2]]];
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-prep-close-'.bin2hex(random_bytes(12));
    $common = ['company' => $company->id, 'branch' => $branch->id, 'category' => $category->ulid, 'barrier' => $barrier, 'payload' => $payload, 'session' => $session->id, 'user' => $user->id];
    $worker = fn ($ready, $operation) => new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($common + ['ready' => $ready, 'operation' => $operation], JSON_THROW_ON_ERROR))]);
    $a = $worker($barrier.'.a', 'prepare');
    $b = $worker($barrier.'.b', 'close');
    $a->start();
    $b->start();
    $deadline = microtime(true) + 30;
    while ((! is_file($barrier.'.a') || ! is_file($barrier.'.b')) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($barrier.'.a') || ! is_file($barrier.'.b')) {
        throw new RuntimeException('Workers de cierre no preparados.');
    }
    file_put_contents($barrier, 'go');
    $a->wait();
    $b->wait();
    unlink($barrier);
    unlink($barrier.'.a');
    unlink($barrier.'.b');
    if (! $a->isSuccessful() || ! $b->isSuccessful()) {
        throw new RuntimeException('Falló una carrera de cierre: '.$a->getErrorOutput().' '.$b->getErrorOutput().' '.$a->getOutput().' '.$b->getOutput());
    }
    $closed = json_decode($b->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    if (($closed['closed'] ?? false) !== true) {
        throw new RuntimeException('El worker de cierre no confirmó su resultado.');
    }
    $preparation = OnlineOrderRequest::query()->where('idempotency_key', $key)->first();
    if ($session->fresh()->status->value !== 'closed' || OnlineOrderRequest::query()->where('idempotency_key', $key)->count() > 1) {
        throw new RuntimeException('El cierre o la preparación quedaron inconsistentes.');
    }
    if ($preparation) {
        config()->set('storefront.visibility', ['category_names' => [], 'category_ulids' => [$category->ulid], 'product_names' => [], 'product_ulids' => []]);
        config()->set('storefront.delivery.branches', [$company->id.':'.$branch->id => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]]);
        try {
            app(OnlinePaymentPreparationService::class)->assertUsable($company, $branch, $preparation);
            throw new RuntimeException('Una preparación permaneció utilizable tras cerrar caja.');
        } catch (ValidationException) {
            // An old preparation must never follow a new cash session.
        }
    }
}
foreach (['orders', 'payments', 'cash_movements', 'inventory_movements', 'dynamic_qr_intents', 'print_attempts'] as $table) {
    if (DB::table($table)->count() !== 0) {
        throw new RuntimeException('Efecto inesperado en '.$table);
    }
}
echo "PASS: 5 carreras de preparación y 3 carreras contra cierre de caja, sin efectos financieros.\n";
