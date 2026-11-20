<?php

use App\Actions\AddOrderItemAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CloseCashSessionAction;
use App\Actions\ConfirmOnlineQrDeliveryAction;
use App\Actions\CreateOnlineOrderRequestAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\DynamicQrAction;
use App\Actions\ResolveOnlineQrDispositionAction;
use App\Enums\InventoryMovementType;
use App\Enums\PrinterPurpose;
use App\Enums\ProductType;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\BancoEconomicoWebhookController;
use App\Models\BankQrSetting;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Company;
use App\Models\DynamicQrIntent;
use App\Models\InventoryItem;
use App\Models\Membership;
use App\Models\OnlineOrderRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PrinterSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\BankQrHistory;
use App\Services\CreatePendingOnlineOrder;
use App\Services\EmitOnlineDynamicQr;
use App\Services\OnlinePaymentPreparationService;
use App\Services\PrepareOnlineQrOperation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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
config()->set('storefront.online_dynamic_qr_enabled', true);
config()->set('baneco.observations_enabled', true);
// Preserve the original conservative experiment and its assertions explicitly.
config()->set('baneco.experimental_transaction_identity_guard', true);

if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    if (($data['qr_identity_policy'] ?? false) === true) {
        config()->set('baneco.experimental_transaction_identity_guard', false);
    }
    $company = Company::query()->findOrFail($data['company']);
    $branch = Branch::query()->findOrFail($data['branch']);
    config()->set('storefront.visibility', ['category_names' => [], 'category_ulids' => [$data['category']], 'product_names' => [], 'product_ulids' => []]);
    config()->set('storefront.delivery.branches', [$company->id.':'.$branch->id => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]]);
    file_put_contents($data['ready'], 'ready');
    while (! is_file($data['barrier'])) {
        usleep(1000);
    }
    if (($data['operation'] ?? '') === 'resolve_disposition') {
        try {
            app(ResolveOnlineQrDispositionAction::class)->execute(
                OnlineOrderRequest::query()->findOrFail($data['preparation']),
                User::query()->findOrFail($data['user']),
                ['disposition' => $data['disposition'] ?? 'accounting', 'amount' => '12.00', 'action_date' => $data['date'],
                    'reason' => 'Regularización de prueba', 'evidence' => 'Comprobante simulado 123', 'confirmed' => '1'],
            );
            echo json_encode(['resolved' => true], JSON_THROW_ON_ERROR);
        } catch (ValidationException $exception) {
            if (($data['disposition'] ?? '') !== 'cancel') {
                throw $exception;
            }
            echo json_encode(['blocked' => true], JSON_THROW_ON_ERROR);
        }
        exit;
    }
    if (($data['operation'] ?? '') === 'confirm_delivery') {
        app(ConfirmOnlineQrDeliveryAction::class)->execute(
            OnlineOrderRequest::query()->findOrFail($data['preparation']), User::query()->findOrFail($data['user']),
            ['action_date' => $data['date'], 'reason' => 'Entrega física de prueba', 'evidence' => 'Acta simulada de entrega 123', 'confirmed' => '1'],
        );
        echo json_encode(['delivered' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    if (($data['operation'] ?? 'prepare') === 'close') {
        $session = CashSession::query()->findOrFail($data['session']);
        $user = User::query()->findOrFail($data['user']);
        app(CloseCashSessionAction::class)->execute($session, '0.00', $user);
        echo json_encode(['closed' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    if (($data['operation'] ?? 'prepare') === 'link') {
        $preparation = OnlineOrderRequest::query()->where('company_id', $company->id)->where('branch_id', $branch->id)
            ->where('idempotency_key', $data['payload']['idempotency_key'])->firstOrFail();
        $operation = app(PrepareOnlineQrOperation::class)->execute($company, $branch, $preparation);
        echo json_encode(['id' => $operation->id, 'actor' => $operation->system_user_id], JSON_THROW_ON_ERROR);
        exit;
    }
    if (($data['operation'] ?? 'prepare') === 'reserve') {
        try {
            $item = app(AddOrderItemAction::class)->execute(Order::query()->findOrFail($data['order']),
                ProductVariant::query()->findOrFail($data['variant']), 1, User::query()->findOrFail($data['user']));
            echo json_encode(['reserved' => true, 'item' => $item->id], JSON_THROW_ON_ERROR);
        } catch (InsufficientStockException) {
            echo json_encode(['reserved' => false], JSON_THROW_ON_ERROR);
        }
        exit;
    }
    if (($data['operation'] ?? 'prepare') === 'verify_reserved') {
        $preparation = OnlineOrderRequest::query()->findOrFail($data['preparation']);
        app(OnlinePaymentPreparationService::class)->assertUsable($company, $branch, $preparation);
        echo json_encode(['verified' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    if (($data['operation'] ?? 'prepare') === 'create_order') {
        $preparation = OnlineOrderRequest::query()->findOrFail($data['preparation']);
        $order = app(CreatePendingOnlineOrder::class)->execute($company, $branch, $preparation);
        echo json_encode(['order' => $order->id], JSON_THROW_ON_ERROR);
        exit;
    }
    if (($data['operation'] ?? 'prepare') === 'emit_qr') {
        config()->set('baneco.enabled', true);
        Http::fake(function ($request) use ($data) {
            if (str_contains($request->url(), 'authentication/encrypt')) {
                return Http::response('"encrypted"');
            }
            if (str_contains($request->url(), 'authentication/authenticate')) {
                return Http::response(['responseCode' => 0, 'token' => 'fake']);
            }
            if (str_contains($request->url(), 'generateQR')) {
                file_put_contents($data['bank_log'], "sent\n", FILE_APPEND | LOCK_EX);
                usleep(250000);

                return Http::response(['responseCode' => 0, 'qrId' => 'bank-concurrent-1', 'qrImage' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=']);
            }
            throw new RuntimeException('Solicitud bancaria inesperada.');
        });
        $intent = app(EmitOnlineDynamicQr::class)->execute($company, $branch, OnlineOrderRequest::query()->findOrFail($data['preparation']));
        echo json_encode(['intent' => $intent->id], JSON_THROW_ON_ERROR);
        exit;
    }
    if (($data['operation'] ?? 'prepare') === 'confirm_qr') {
        config()->set('baneco.enabled', true);
        config()->set('baneco.webhook_enabled', true);
        Http::fake(function ($request) use ($data) {
            if (str_contains($request->url(), 'authentication/encrypt')) {
                return Http::response('"encrypted"');
            }
            if (str_contains($request->url(), 'authentication/authenticate')) {
                return Http::response(['responseCode' => 0, 'token' => 'fake']);
            }
            if (str_contains($request->url(), 'statusQR')) {
                return Http::response(['responseCode' => 0, 'statusQrCode' => 1,
                    'payment' => ['qrId' => $data['bank_id'] ?? 'bank-concurrent-1', 'amount' => $data['amount'] ?? '24.00', 'currency' => 'BOB', 'transactionId' => array_key_exists('transaction_id', $data) ? $data['transaction_id'] : 'simulated-concurrent']]);
            }
            throw new RuntimeException('Solicitud bancaria inesperada.');
        });
        if ($data['via_webhook'] ?? false) {
            $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['payment' => ['qrId' => 'bank-concurrent-1']], JSON_THROW_ON_ERROR));
            app(BancoEconomicoWebhookController::class)($request);
            $intent = DynamicQrIntent::query()->findOrFail($data['intent']);
        } else {
            $intent = app(DynamicQrAction::class)->check(DynamicQrIntent::query()->findOrFail($data['intent']));
        }
        echo json_encode(['intent' => $intent->id, 'status' => $intent->status->value], JSON_THROW_ON_ERROR);
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
if (($argv[1] ?? '') === '--cancel-race-existing') {
    $seedIntent = DynamicQrIntent::query()->where('bank_qr_id', 'like', 'race-disposition-%')->sole();
    $seedOperation = DB::table('online_qr_operations')->where('dynamic_qr_intent_id', $seedIntent->id)->sole();
    $company = Company::query()->findOrFail($seedIntent->company_id);
    $branch = Branch::query()->findOrFail($seedIntent->branch_id);
    $user = User::query()->findOrFail($seedOperation->original_cashier_id);
    $seedOrder = Order::query()->findOrFail($seedIntent->order_id);
    $variant = $seedOrder->items()->firstOrFail()->productVariant;
    $category = $variant->product->category;
    $inventory = InventoryItem::query()->where('product_variant_id', $variant->id)->firstOrFail();
    config()->set('storefront.visibility', ['category_names' => [], 'category_ulids' => [$category->ulid], 'product_names' => [], 'product_ulids' => []]);
    config()->set('storefront.delivery.branches', [$company->id.':'.$branch->id => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]]);
    app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventory, InventoryMovementType::AdjustmentIn,
        '1.000', '1.000000', $user, reason: 'Inventario aislado para cancelación concurrente');
    $payload = ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente prueba', 'customer_phone' => '70000000', 'fulfillment' => 'delivery',
        'address' => 'Calle prueba', 'reference' => 'Referencia', 'latitude' => -17.3769946, 'longitude' => -66.1771762, 'location_confirmed' => true,
        'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]];
    $preparation = app(CreateOnlineOrderRequestAction::class)->execute($company, $branch, $payload, true);
    $order = app(CreatePendingOnlineOrder::class)->execute($company, $branch, $preparation);
    $operation = DB::table('online_qr_operations')->where('online_order_request_id', $preparation->id)->sole();
    $intent = DynamicQrIntent::query()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'order_id' => $order->id,
        'cash_session_id' => $operation->cash_session_id, 'user_id' => $operation->system_user_id,
        'bank_qr_setting_id' => $seedIntent->bank_qr_setting_id, 'amount' => '12.00', 'currency' => 'BOB',
        'commercial_reference' => 'cancel-race-'.bin2hex(random_bytes(4)), 'bank_qr_id' => 'cancel-race-'.bin2hex(random_bytes(4)),
        'idempotency_key' => (string) Str::uuid(), 'status' => 'cancelled', 'bank_status' => '9',
        'expires_at' => now()->addDay(), 'last_checked_at' => now(),
    ]);
    DB::table('online_qr_operations')->where('id', $operation->id)->update(['dynamic_qr_intent_id' => $intent->id]);
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-cancel-disposition-'.bin2hex(random_bytes(12));
    $common = ['company' => $company->id, 'branch' => $branch->id, 'category' => $category->ulid, 'barrier' => $barrier,
        'preparation' => $preparation->id, 'user' => $user->id, 'operation' => 'resolve_disposition', 'disposition' => 'cancel', 'date' => now()->toDateString()];
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
        throw new RuntimeException('Workers de cancelación no preparados.');
    }
    file_put_contents($barrier, 'go');
    $a->wait();
    $b->wait();
    if (! $a->isSuccessful() || ! $b->isSuccessful()) {
        throw new RuntimeException('Falló la cancelación concurrente: '.$a->getErrorOutput().' '.$b->getErrorOutput());
    }
    if ((json_decode($a->getOutput(), true)['blocked'] ?? false) !== true || (json_decode($b->getOutput(), true)['blocked'] ?? false) !== true
        || DB::table('online_qr_dispositions')->where('online_qr_operation_id', $operation->id)->count() !== 0
        || DB::table('inventory_reservations')->where('order_id', $order->id)->where('status', 'reserved')->count() !== 1
        || DB::table('inventory_reservations')->where('order_id', $seedOrder->id)->where('status', 'reserved')->count() !== 1
        || DB::table('payments')->where('order_id', $order->id)->count() !== 0
        || DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->where('reference_id', $order->items()->value('id'))->exists()) {
        throw new RuntimeException('La cancelación concurrente alteró otras reservas o duplicó efectos.');
    }
    echo "PASS: cancelación bloqueada en ambos procesos; reservas propias y ajenas intactas.\n";
    exit;
}
if (($argv[1] ?? '') === '--delivery-race-existing') {
    $seedIntent = DynamicQrIntent::query()->where('bank_qr_id', 'like', 'race-disposition-%')->sole();
    $operation = DB::table('online_qr_operations')->where('dynamic_qr_intent_id', $seedIntent->id)->sole();
    $company = Company::query()->findOrFail($seedIntent->company_id);
    $branch = Branch::query()->findOrFail($seedIntent->branch_id);
    $order = Order::query()->findOrFail($seedIntent->order_id);
    $category = $order->items()->firstOrFail()->productVariant->product->category;
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-delivery-'.bin2hex(random_bytes(12));
    $common = ['company' => $company->id, 'branch' => $branch->id, 'category' => $category->ulid, 'barrier' => $barrier,
        'preparation' => $operation->online_order_request_id, 'user' => $operation->original_cashier_id, 'operation' => 'confirm_delivery', 'date' => now()->toDateString()];
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
        throw new RuntimeException('Workers de entrega no preparados.');
    }
    file_put_contents($barrier, 'go');
    $a->wait();
    $b->wait();
    if (! $a->isSuccessful() || ! $b->isSuccessful()) {
        throw new RuntimeException('Falló la entrega concurrente: '.$a->getErrorOutput().' '.$b->getErrorOutput());
    }
    $disposition = DB::table('online_qr_dispositions')->where('online_qr_operation_id', $operation->id)->sole();
    if (DB::table('online_qr_inventory_outcomes')->where('online_qr_disposition_id', $disposition->id)->count() !== 1
        || DB::table('inventory_reservations')->where('order_id', $order->id)->where('status', 'consumed')->count() !== 1
        || DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->where('reference_id', $order->items()->value('id'))->count() !== 1
        || DB::table('payments')->where('order_id', $order->id)->count() !== 0) {
        throw new RuntimeException('La entrega concurrente duplicó o alteró efectos.');
    }
    echo "PASS: entrega concurrente, un consumo y ningún pago POS.\n";
    exit;
}
if (! in_array($argv[1] ?? '', ['--run-isolated', '--acceptance-isolated'], true)) {
    throw new RuntimeException('Se requiere --run-isolated o --acceptance-isolated.');
}
$exploratory = ($argv[1] ?? '') === '--run-isolated';
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
    if (! $a->isSuccessful() || ! $b->isSuccessful()) {
        throw new RuntimeException('Falló un worker: '.$a->getErrorOutput().' '.$b->getErrorOutput());
    }
    $left = json_decode($a->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $right = json_decode($b->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    if ($left !== $right || $left['total'] !== '24.00' || OnlineOrderRequest::query()->where('idempotency_key', $key)->count() !== 1) {
        throw new RuntimeException('Resultado duplicado o inconsistente.');
    }
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-operation-'.bin2hex(random_bytes(12));
    $common['barrier'] = $barrier;
    $worker = fn ($ready) => new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($common + ['ready' => $ready, 'operation' => 'link'], JSON_THROW_ON_ERROR))]);
    $a = $worker($barrier.'.a');
    $b = $worker($barrier.'.b');
    $a->start();
    $b->start();
    $deadline = microtime(true) + 30;
    while ((! is_file($barrier.'.a') || ! is_file($barrier.'.b')) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($barrier.'.a') || ! is_file($barrier.'.b')) {
        throw new RuntimeException('Workers de vínculo no preparados.');
    }
    file_put_contents($barrier, 'go');
    $a->wait();
    $b->wait();
    if (! $a->isSuccessful() || ! $b->isSuccessful()) {
        throw new RuntimeException('Falló un vínculo concurrente: '.$a->getErrorOutput().' '.$b->getErrorOutput());
    }
    $left = json_decode($a->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $right = json_decode($b->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $preparationId = OnlineOrderRequest::query()->where('idempotency_key', $key)->value('id');
    if ($left !== $right || DB::table('online_qr_operations')->where('online_order_request_id', $preparationId)->count() !== 1) {
        throw new RuntimeException('Vínculo duplicado o inconsistente.');
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
$unit = Unit::factory()->for($company)->create();
$inventory = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id,
    'product_variant_id' => $variant->id, 'name' => 'Última unidad', 'is_active' => true]);
app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventory, InventoryMovementType::AdjustmentIn,
    '1.000', '1.000000', $user, reason: 'Existencia aislada para concurrencia');
$leftOrder = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $user);
$rightOrder = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $user);
$barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-stock-'.bin2hex(random_bytes(12));
$common = ['company' => $company->id, 'branch' => $branch->id, 'category' => $category->ulid, 'barrier' => $barrier,
    'variant' => $variant->id, 'user' => $user->id, 'operation' => 'reserve'];
$worker = fn ($ready, $order) => new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($common + ['ready' => $ready, 'order' => $order], JSON_THROW_ON_ERROR))]);
$a = $worker($barrier.'.a', $leftOrder->id);
$b = $worker($barrier.'.b', $rightOrder->id);
$a->start();
$b->start();
$deadline = microtime(true) + 30;
while ((! is_file($barrier.'.a') || ! is_file($barrier.'.b')) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($barrier.'.a') || ! is_file($barrier.'.b')) {
    throw new RuntimeException('Workers de inventario no preparados.');
}
file_put_contents($barrier, 'go');
$a->wait();
$b->wait();
if (! $a->isSuccessful() || ! $b->isSuccessful()) {
    throw new RuntimeException('Falló la carrera por inventario: '.$a->getErrorOutput().' '.$b->getErrorOutput());
}
$left = json_decode($a->getOutput(), true, flags: JSON_THROW_ON_ERROR);
$right = json_decode($b->getOutput(), true, flags: JSON_THROW_ON_ERROR);
if ((int) $left['reserved'] + (int) $right['reserved'] !== 1
    || DB::table('inventory_reservations')->where('inventory_item_id', $inventory->id)->where('status', 'reserved')->count() !== 1
    || DB::table('order_items')->whereIn('order_id', [$leftOrder->id, $rightOrder->id])->count() !== 1
    || (string) DB::table('inventory_stocks')->where('inventory_item_id', $inventory->id)->value('quantity') !== '1.000'
    || DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count() !== 0
    || DB::table('payments')->count() !== 0 || DB::table('dynamic_qr_intents')->count() !== 0) {
    throw new RuntimeException('La reserva concurrente no conservó la última unidad.');
}
$newProduct = Product::factory()->for($category)->create(['type' => ProductType::Beverage]);
$newVariant = ProductVariant::factory()->for($newProduct)->create(['price' => '12.00', 'requires_preparation' => false]);
$newInventory = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id,
    'product_variant_id' => $newVariant->id, 'name' => 'Última unidad online', 'is_active' => true]);
app(ApplyInventoryMovementAction::class)->execute($company, $branch, $newInventory, InventoryMovementType::AdjustmentIn,
    '1.000', '1.000000', $user, reason: 'Existencia aislada para reserva online');
$register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja reserva propia', 'is_active' => true]);
CashSession::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id,
    'active_cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_amount' => '0.00', 'status' => 'open', 'opened_at' => now()]);
config()->set('storefront.visibility', ['category_names' => [], 'category_ulids' => [$category->ulid], 'product_names' => [], 'product_ulids' => []]);
config()->set('storefront.delivery.branches', [$company->id.':'.$branch->id => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]]);
$payload = ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente prueba', 'customer_phone' => '70000000', 'fulfillment' => 'delivery',
    'address' => 'Calle prueba', 'reference' => 'Referencia', 'latitude' => -17.3769946, 'longitude' => -66.1771762, 'location_confirmed' => true,
    'items' => [['variant_ulid' => $newVariant->ulid, 'quantity' => 1]]];
$preparation = app(CreateOnlineOrderRequestAction::class)->execute($company, $branch, $payload, true);
$operation = app(PrepareOnlineQrOperation::class)->execute($company, $branch, $preparation);
$ownPreparation = $preparation;
$ownOperation = $operation;
$ownOrder = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $user);
$ownItem = app(AddOrderItemAction::class)->execute($ownOrder, $newVariant, 1, $user);
// Construct the future online linkage; the online order creator is not implemented yet.
$ownOrder->update(['created_by' => $operation->system_user_id]);
$ownItem->update(['created_by' => $operation->system_user_id]);
DB::table('online_qr_operations')->where('id', $operation->id)->update(['pos_order_id' => $ownOrder->id]);
$preparation->update(['pos_order_id' => $ownOrder->id]);
$competingOrder = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $user);
$barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-own-stock-'.bin2hex(random_bytes(12));
$common = ['company' => $company->id, 'branch' => $branch->id, 'category' => $category->ulid, 'barrier' => $barrier,
    'variant' => $newVariant->id, 'user' => $user->id, 'preparation' => $preparation->id, 'order' => $competingOrder->id];
$worker = fn ($ready, $operation) => new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($common + ['ready' => $ready, 'operation' => $operation], JSON_THROW_ON_ERROR))]);
$a = $worker($barrier.'.a', 'verify_reserved');
$b = $worker($barrier.'.b', 'reserve');
$a->start();
$b->start();
$deadline = microtime(true) + 30;
while ((! is_file($barrier.'.a') || ! is_file($barrier.'.b')) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($barrier.'.a') || ! is_file($barrier.'.b')) {
    throw new RuntimeException('Workers de reserva propia no preparados.');
}
file_put_contents($barrier, 'go');
$a->wait();
$b->wait();
if (! $a->isSuccessful() || ! $b->isSuccessful()) {
    throw new RuntimeException('Falló la carrera de reserva propia: '.$a->getErrorOutput().' '.$b->getErrorOutput());
}
$ownResult = json_decode($a->getOutput(), true, flags: JSON_THROW_ON_ERROR);
$otherResult = json_decode($b->getOutput(), true, flags: JSON_THROW_ON_ERROR);
if (($ownResult['verified'] ?? false) !== true || ($otherResult['reserved'] ?? true) !== false
    || DB::table('inventory_reservations')->where('inventory_item_id', $newInventory->id)->where('status', 'reserved')->count() !== 1
    || DB::table('payments')->count() !== 0 || DB::table('dynamic_qr_intents')->count() !== 0
    || DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count() !== 0) {
    throw new RuntimeException('La reserva propia y la reserva ajena quedaron inconsistentes.');
}
$thirdProduct = Product::factory()->for($category)->create(['type' => ProductType::Beverage]);
$thirdVariant = ProductVariant::factory()->for($thirdProduct)->create(['price' => '12.00', 'requires_preparation' => false]);
$thirdInventory = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id,
    'product_variant_id' => $thirdVariant->id, 'name' => 'Inventario orden concurrente', 'is_active' => true]);
app(ApplyInventoryMovementAction::class)->execute($company, $branch, $thirdInventory, InventoryMovementType::AdjustmentIn,
    '2.000', '1.000000', $user, reason: 'Existencia aislada para orden concurrente');
$payload['idempotency_key'] = (string) Str::uuid();
$payload['items'] = [['variant_ulid' => $thirdVariant->ulid, 'quantity' => 2]];
$preparation = app(CreateOnlineOrderRequestAction::class)->execute($company, $branch, $payload, true);
$barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-order-'.bin2hex(random_bytes(12));
$common = ['company' => $company->id, 'branch' => $branch->id, 'category' => $category->ulid, 'barrier' => $barrier,
    'preparation' => $preparation->id, 'operation' => 'create_order'];
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
    throw new RuntimeException('Workers de orden online no preparados.');
}
file_put_contents($barrier, 'go');
$a->wait();
$b->wait();
if (! $a->isSuccessful() || ! $b->isSuccessful()) {
    throw new RuntimeException('Falló la creación concurrente: '.$a->getErrorOutput().' '.$b->getErrorOutput());
}
$left = json_decode($a->getOutput(), true, flags: JSON_THROW_ON_ERROR);
$right = json_decode($b->getOutput(), true, flags: JSON_THROW_ON_ERROR);
$orderId = $preparation->fresh()->pos_order_id;
if ($left !== $right || (int) $left['order'] !== (int) $orderId
    || DB::table('online_qr_operations')->where('online_order_request_id', $preparation->id)->where('pos_order_id', $orderId)->count() !== 1
    || DB::table('order_items')->where('order_id', $orderId)->count() !== 1
    || DB::table('inventory_reservations')->where('order_id', $orderId)->where('status', 'reserved')->count() !== 1
    || DB::table('payments')->count() !== 0 || DB::table('dynamic_qr_intents')->count() !== 0
    || DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count() !== 0) {
    throw new RuntimeException('La creación concurrente duplicó o alteró la orden online.');
}
BankQrSetting::query()->create(['company_id' => $company->id, 'active_scope' => $company->id.':company',
    'credentials' => ['username' => 'fake', 'password' => 'fake', 'aes_key' => 'fake', 'account_credit' => 'fake']]);
$barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-qr-'.bin2hex(random_bytes(12));
$bankLog = $barrier.'.bank';
$common = ['company' => $company->id, 'branch' => $branch->id, 'category' => $category->ulid, 'barrier' => $barrier,
    'preparation' => $preparation->id, 'operation' => 'emit_qr', 'bank_log' => $bankLog];
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
    throw new RuntimeException('Workers QR no preparados.');
}
file_put_contents($barrier, 'go');
$a->wait();
$b->wait();
if (! $a->isSuccessful() || ! $b->isSuccessful()) {
    throw new RuntimeException('Falló la emisión concurrente: '.$a->getErrorOutput().' '.$b->getErrorOutput());
}
$left = json_decode($a->getOutput(), true, flags: JSON_THROW_ON_ERROR);
$right = json_decode($b->getOutput(), true, flags: JSON_THROW_ON_ERROR);
if ($left !== $right || ! is_file($bankLog) || count(file($bankLog)) !== 1
    || DB::table('dynamic_qr_intents')->where('order_id', $orderId)->where('bank_qr_id', 'bank-concurrent-1')->count() !== 1
    || DB::table('payments')->count() !== 0) {
    throw new RuntimeException('La emisión concurrente duplicó el envío.');
}
foreach ([PrinterPurpose::Kitchen, PrinterPurpose::CustomerTicket] as $purpose) {
    PrinterSetting::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'purpose' => $purpose,
        'windows_printer_name' => 'Simulated printer', 'is_active' => true, 'auto_print' => true, 'copies' => 1, 'paper_width_mm' => 80]);
}
$intentId = DB::table('dynamic_qr_intents')->where('order_id', $orderId)->value('id');
$barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-confirm-'.bin2hex(random_bytes(12));
$common = ['company' => $company->id, 'branch' => $branch->id, 'category' => $category->ulid, 'barrier' => $barrier,
    'intent' => $intentId, 'operation' => 'confirm_qr'];
$worker = fn ($ready, $webhook) => new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($common + ['ready' => $ready, 'via_webhook' => $webhook], JSON_THROW_ON_ERROR))]);
$a = $worker($barrier.'.a', true);
$b = $worker($barrier.'.b', false);
$a->start();
$b->start();
$deadline = microtime(true) + 30;
while ((! is_file($barrier.'.a') || ! is_file($barrier.'.b')) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($barrier.'.a') || ! is_file($barrier.'.b')) {
    throw new RuntimeException('Workers de confirmación no preparados.');
}
file_put_contents($barrier, 'go');
$a->wait();
$b->wait();
if (! $a->isSuccessful() || ! $b->isSuccessful()) {
    throw new RuntimeException('Falló confirmación concurrente: '.$a->getErrorOutput().' '.$b->getErrorOutput());
}
if (DB::table('dynamic_qr_intents')->where('id', $intentId)->value('status') !== 'paid'
    || DB::table('orders')->where('id', $orderId)->value('status') !== 'paid'
    || DB::table('online_order_requests')->where('id', $preparation->id)->value('status') !== 'paid'
    || DB::table('payments')->where('order_id', $orderId)->count() !== 1
    || DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count() !== 1
    || DB::table('print_attempts')->where('order_id', $orderId)->count() !== 2) {
    throw new RuntimeException('La confirmación concurrente no conservó una venta, pago, consumo y dos impresiones.');
}
$paymentId = DB::table('dynamic_qr_intents')->where('id', $intentId)->value('payment_id');
$observation = DB::table('bank_qr_observations')->where('dynamic_qr_intent_id', $intentId)->where('kind', 'observation')->first();
if (! $observation || ! $paymentId
    || DB::table('cash_movements')->where('reference_type', Payment::class)->where('reference_id', $paymentId)->exists()) {
    throw new RuntimeException('La confirmación QR carece de evidencia o alteró efectivo.');
}
foreach ([PrinterPurpose::Kitchen, PrinterPurpose::CustomerTicket] as $purpose) {
    if (DB::table('print_attempts')->where('order_id', $orderId)->where('purpose', $purpose->value)->count() !== 1) {
        throw new RuntimeException('La confirmación no conservó una impresión por propósito.');
    }
}
$evidence = json_decode(Crypt::decryptString($observation->evidence), true, flags: JSON_THROW_ON_ERROR);
if (($evidence['payment']['qrId'] ?? null) !== 'bank-concurrent-1' || str_contains($observation->evidence, 'bank-concurrent-1')) {
    throw new RuntimeException('La evidencia de la misma intención no se conservó cifrada.');
}
$retry = $worker($barrier.'.retry', false);
$retry->run();
if (! $retry->isSuccessful() || DB::table('payments')->where('order_id', $orderId)->count() !== 1
    || DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count() !== 1
    || DB::table('print_attempts')->where('order_id', $orderId)->count() !== 2
    || DB::table('dynamic_qr_intents')->where('id', $intentId)->value('payment_id') !== $paymentId) {
    throw new RuntimeException('El reintento de la misma intención duplicó efectos.');
}
foreach ([PrinterPurpose::Kitchen, PrinterPurpose::CustomerTicket] as $purpose) {
    if (DB::table('print_attempts')->where('order_id', $orderId)->where('purpose', $purpose->value)->count() !== 1) {
        throw new RuntimeException('El reintento duplicó una impresión por propósito.');
    }
}
echo "PASS: confirmación simultánea y reintento de la misma intención: un pago, un consumo, dos impresiones y evidencia cifrada.\n";
$date = now()->timezone('America/La_Paz')->toDateString();
$historyFilters = ['from' => $date, 'to' => $date, 'branch' => (string) $branch->id,
    'order' => '', 'reference' => '', 'qr' => '', 'state' => '', 'category' => 'confirmed'];
$history = app(BankQrHistory::class);
$confirmedRows = $history->report($company, $historyFilters, null, 1)['paginator']->getCollection();
if (! $confirmedRows->contains(fn ($row) => $row['intent']?->id === $intentId)) {
    throw new RuntimeException('La conciliación no mostró el pago validado en su sucursal.');
}
$previousEvidence = DB::table('dynamic_qr_intents')->where('id', $intentId)->value('reconciliation_data');
DB::table('dynamic_qr_intents')->where('id', $intentId)->update(['reconciliation_data' => json_encode(['qrId' => 'foreign', 'amount' => '24.00', 'currency' => 'BOB'], JSON_THROW_ON_ERROR)]);
try {
    $confirmedRows = $history->report($company, $historyFilters, null, 1)['paginator']->getCollection();
    $reviewRows = $history->report($company, array_merge($historyFilters, ['category' => 'review']), null, 1)['paginator']->getCollection();
    if ($confirmedRows->contains(fn ($row) => $row['intent']?->id === $intentId)
        || ! $reviewRows->contains(fn ($row) => $row['intent']?->id === $intentId)) {
        throw new RuntimeException('Una evidencia contradictoria no quedó en revisión.');
    }
} finally {
    DB::table('dynamic_qr_intents')->where('id', $intentId)->update(['reconciliation_data' => $previousEvidence]);
}
echo "PASS: conciliación InnoDB distingue pago validado de evidencia contradictoria.\n";
$barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-contradiction-'.bin2hex(random_bytes(12));
$common = ['company' => $company->id, 'branch' => $branch->id, 'category' => $category->ulid, 'barrier' => $barrier,
    'intent' => $intentId, 'operation' => 'confirm_qr', 'transaction_id' => 'conflicting-concurrent'];
DB::table('dynamic_qr_intents')->where('id', $intentId)->update(['last_checked_at' => null]);
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
    throw new RuntimeException('Workers de contradicción no preparados.');
}
file_put_contents($barrier, 'go');
$a->wait();
$b->wait();
$reviewRows = $history->report($company, array_merge($historyFilters, ['category' => 'review']), null, 1)['paginator']->getCollection();
if (! $a->isSuccessful() || ! $b->isSuccessful()
    || DB::table('dynamic_qr_intents')->where('id', $intentId)->value('issue_code') !== 'contradictory_bank_response'
    || DB::table('dynamic_qr_intents')->where('id', $intentId)->value('reconciliation_data') !== $previousEvidence
    || ! $reviewRows->contains(fn ($row) => $row['intent']?->id === $intentId)
    || DB::table('payments')->where('order_id', $orderId)->count() !== 1
    || DB::table('cash_movements')->where('reference_type', Payment::class)->where('reference_id', $paymentId)->exists()
    || DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count() !== 1
    || DB::table('print_attempts')->where('order_id', $orderId)->count() !== 2) {
    throw new RuntimeException('La contradicción concurrente no conservó el pago y sus efectos en revisión.');
}
echo "PASS: respuestas contradictorias concurrentes conservan un pago, consumo e impresiones.\n";
$setting = BankQrSetting::query()->where('company_id', $company->id)->firstOrFail();
$ownIntent = DynamicQrIntent::query()->create([
    'company_id' => $company->id, 'branch_id' => $branch->id, 'order_id' => $ownOrder->id,
    'active_order_id' => $ownOrder->id, 'cash_session_id' => $ownOperation->cash_session_id,
    'user_id' => $ownOperation->system_user_id, 'bank_qr_setting_id' => $setting->id,
    'amount' => '12.00', 'currency' => 'BOB', 'commercial_reference' => 'race-disposition-'.bin2hex(random_bytes(4)),
    'bank_qr_id' => 'race-disposition-'.bin2hex(random_bytes(4)), 'idempotency_key' => (string) Str::uuid(),
    'status' => 'reconciliation_required', 'bank_status' => '1', 'issue_code' => 'cash_session_closed',
    'expires_at' => now()->addDay(), 'last_checked_at' => now(),
]);
$ownIntent->update(['reconciliation_data' => ['qrId' => $ownIntent->bank_qr_id, 'amount' => '12.00', 'currency' => 'BOB']]);
DB::table('online_qr_operations')->where('id', $ownOperation->id)->update(['dynamic_qr_intent_id' => $ownIntent->id]);
$barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-disposition-'.bin2hex(random_bytes(12));
$common = ['company' => $company->id, 'branch' => $branch->id, 'category' => $category->ulid, 'barrier' => $barrier,
    'preparation' => $ownPreparation->id, 'user' => $user->id, 'operation' => 'resolve_disposition', 'date' => now()->toDateString()];
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
    throw new RuntimeException('Workers de disposición no preparados.');
}
file_put_contents($barrier, 'go');
$a->wait();
$b->wait();
if (! $a->isSuccessful() || ! $b->isSuccessful()) {
    throw new RuntimeException('Falló la disposición concurrente: '.$a->getErrorOutput().' '.$b->getErrorOutput());
}
if (DB::table('online_qr_dispositions')->where('online_qr_operation_id', $ownOperation->id)->count() !== 1
    || DB::table('inventory_reservations')->where('order_id', $ownOrder->id)->where('status', 'reserved')->count() !== 1
    || DB::table('payments')->where('order_id', $ownOrder->id)->count() !== 0) {
    throw new RuntimeException('La disposición concurrente alteró la reserva o creó efectos duplicados.');
}
echo "PASS: 19 carreras, incluida la disposición administrativa concurrente.\n";

// Two different online QR IDs receive the same simulated transaction ID.
$company = Company::factory()->create();
$branch = Branch::factory()->for($company)->create();
$collisionBranch = Branch::factory()->for($company)->create();
$category = Category::factory()->for($company)->create();
$product = Product::factory()->for($category)->create(['type' => ProductType::Beverage]);
$variant = ProductVariant::factory()->for($product)->create(['price' => '12.00', 'requires_preparation' => false]);
$user = User::factory()->create();
Membership::factory()->for($company)->for($user)->owner()->create();
$register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja colisión simulada', 'is_active' => true]);
$cash = CashSession::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id,
    'active_cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_amount' => '0.00', 'status' => 'open', 'opened_at' => now()]);
$secondRegister = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $collisionBranch->id, 'name' => 'Otra caja simulada', 'is_active' => true]);
CashSession::query()->create(['company_id' => $company->id, 'branch_id' => $collisionBranch->id, 'cash_register_id' => $secondRegister->id,
    'active_cash_register_id' => $secondRegister->id, 'opened_by' => $user->id, 'opening_amount' => '0.00', 'status' => 'open', 'opened_at' => now()]);
$unit = Unit::factory()->for($company)->create();
$inventory = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'product_variant_id' => $variant->id,
    'name' => 'Existencia colisión simulada', 'is_active' => true]);
app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventory, InventoryMovementType::AdjustmentIn, '1.000', '1.000000', $user, reason: 'Carrera aislada');
app(ApplyInventoryMovementAction::class)->execute($company, $collisionBranch, $inventory, InventoryMovementType::AdjustmentIn, '1.000', '1.000000', $user, reason: 'Carrera aislada');
config(['storefront.visibility' => ['category_names' => [], 'category_ulids' => [$category->ulid], 'product_names' => [], 'product_ulids' => []],
    'storefront.delivery.branches' => [$company->id.':'.$branch->id => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800],
        $company->id.':'.$collisionBranch->id => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]], 'baneco.enabled' => true]);
BankQrSetting::query()->create(['company_id' => $company->id, 'active_scope' => $company->id.':company',
    'credentials' => ['username' => 'fake', 'password' => 'fake', 'aes_key' => 'fake', 'account_credit' => 'fake']]);
$generated = 0;
Http::fake(function ($request) use (&$generated) {
    if (str_contains($request->url(), 'authentication/encrypt')) {
        return Http::response('"encrypted"');
    }
    if (str_contains($request->url(), 'authentication/authenticate')) {
        return Http::response(['responseCode' => 0, 'token' => 'fake']);
    }
    if (str_contains($request->url(), 'generateQR')) {
        return Http::response(['responseCode' => 0, 'qrId' => 'collision-'.++$generated, 'qrImage' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=']);
    }
    throw new RuntimeException('Solicitud bancaria inesperada.');
});
$intents = [];
for ($i = 0; $i < 2; $i++) {
    $payload = ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente prueba', 'customer_phone' => '70000000', 'fulfillment' => 'delivery',
        'address' => 'Calle prueba', 'reference' => 'Referencia', 'latitude' => -17.3769946, 'longitude' => -66.1771762, 'location_confirmed' => true,
        'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]];
    $requestBranch = $i === 0 ? $branch : $collisionBranch;
    $request = app(CreateOnlineOrderRequestAction::class)->execute($company, $requestBranch, $payload, true);
    $intents[] = app(EmitOnlineDynamicQr::class)->execute($company, $requestBranch, $request);
}
if ($exploratory) {
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-collision-'.bin2hex(random_bytes(12));
    $common = ['company' => $company->id, 'category' => $category->ulid, 'barrier' => $barrier, 'operation' => 'confirm_qr', 'amount' => '12.00'];
    $worker = fn ($index) => new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($common + ['ready' => $barrier.'.'.$index,
        'branch' => $index === 0 ? $branch->id : $collisionBranch->id, 'intent' => $intents[$index]->id, 'bank_id' => $intents[$index]->bank_qr_id], JSON_THROW_ON_ERROR))]);
    $a = $worker(0);
    $b = $worker(1);
    $a->start();
    $b->start();
    $deadline = microtime(true) + 30;
    while ((! is_file($barrier.'.0') || ! is_file($barrier.'.1')) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($barrier.'.0') || ! is_file($barrier.'.1')) {
        throw new RuntimeException('Workers de colisión no preparados.');
    }
    file_put_contents($barrier, 'go');
    $a->wait();
    $b->wait();
    if (! $a->isSuccessful() || ! $b->isSuccessful()) {
        throw new RuntimeException('Falló la carrera de colisión: '.$a->getErrorOutput().' '.$b->getErrorOutput());
    }
    $ids = [$intents[0]->id, $intents[1]->id];
    if (DB::table('dynamic_qr_intents')->whereIn('id', $ids)->where('status', 'paid')->count() !== 1
        || DB::table('dynamic_qr_intents')->whereIn('id', $ids)->where('issue_code', 'bank_transaction_identity_collision')->count() !== 1
        || DB::table('payments')->where('company_id', $company->id)->count() !== 1) {
        throw new RuntimeException('La colisión concurrente no quedó en revisión con un único pago.');
    }
    echo "PASS: dos QR distintos con identidad simulada coincidente, un pago y una revisión.\n";
}

// Separate contract-backed policy: distinct QR IDs do not share a payment identity
// merely because the bank transaction numbers match. Preserve the legacy case above.
foreach ([$branch, $collisionBranch] as $stockBranch) {
    app(ApplyInventoryMovementAction::class)->execute($company, $stockBranch, $inventory, InventoryMovementType::AdjustmentIn,
        '1.000', '1.000000', $user, reason: 'Política QR aislada');
}
$qrPolicyIntents = [];
foreach ([$branch, $collisionBranch] as $requestBranch) {
    $payload['idempotency_key'] = (string) Str::uuid();
    $request = app(CreateOnlineOrderRequestAction::class)->execute($company, $requestBranch, $payload, true);
    $qrPolicyIntents[] = app(EmitOnlineDynamicQr::class)->execute($company, $requestBranch, $request);
}
if ($qrPolicyIntents[0]->bank_qr_id === $qrPolicyIntents[1]->bank_qr_id) {
    throw new RuntimeException('La prueba requiere dos QR diferentes.');
}
$policyBarrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-qr-policy-'.bin2hex(random_bytes(12));
$policyCommon = ['company' => $company->id, 'category' => $category->ulid, 'barrier' => $policyBarrier,
    'operation' => 'confirm_qr', 'amount' => '12.00', 'transaction_id' => 'simulated-matching-number', 'qr_identity_policy' => true];
$policyWorkers = [];
foreach ($qrPolicyIntents as $index => $candidate) {
    $policyWorkers[] = new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($policyCommon + [
        'ready' => $policyBarrier.'.'.$index, 'branch' => $candidate->branch_id,
        'intent' => $candidate->id, 'bank_id' => $candidate->bank_qr_id,
    ], JSON_THROW_ON_ERROR))]);
}
foreach ($policyWorkers as $process) {
    $process->start();
}
$policyDeadline = microtime(true) + 30;
while ((! is_file($policyBarrier.'.0') || ! is_file($policyBarrier.'.1')) && microtime(true) < $policyDeadline) {
    usleep(10000);
}
if (! is_file($policyBarrier.'.0') || ! is_file($policyBarrier.'.1')) {
    throw new RuntimeException('Workers de política por QR no preparados.');
}
file_put_contents($policyBarrier, 'go');
foreach ($policyWorkers as $process) {
    $process->wait();
    if (! $process->isSuccessful()) {
        throw new RuntimeException('Falló la política por QR: '.$process->getErrorOutput());
    }
}
foreach ($qrPolicyIntents as $candidate) {
    if ($candidate->fresh()->status->value !== 'paid'
        || DB::table('payments')->where('order_id', $candidate->order_id)->count() !== 1) {
        throw new RuntimeException('Un QR independiente no produjo exactamente un pago por intención.');
    }
}
echo "PASS: dos QR independientes con el mismo número simulado confirmaron un pago por intención.\n";

// Exercise the unresolved POS/online boundary using separate cash sessions.
app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventory, InventoryMovementType::AdjustmentIn, '1.000', '1.000000', $user, reason: 'Carrera POS online aislada');
app(ApplyInventoryMovementAction::class)->execute($company, $collisionBranch, $inventory, InventoryMovementType::AdjustmentIn, '1.000', '1.000000', $user, reason: 'Carrera POS online aislada');
$posOrder = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $user);
app(AddOrderItemAction::class)->execute($posOrder, $variant, 1, $user);
$posIntent = DynamicQrIntent::query()->create([
    'company_id' => $company->id, 'branch_id' => $branch->id, 'order_id' => $posOrder->id,
    'active_order_id' => $posOrder->id, 'cash_session_id' => $cash->id, 'user_id' => $user->id,
    'bank_qr_setting_id' => BankQrSetting::query()->where('company_id', $company->id)->firstOrFail()->id,
    'amount' => '12.00', 'currency' => 'BOB', 'commercial_reference' => 'cross-pos-'.bin2hex(random_bytes(4)),
    'bank_qr_id' => 'cross-pos-'.bin2hex(random_bytes(4)), 'idempotency_key' => (string) Str::uuid(),
    'status' => 'pending', 'expires_at' => now()->addDay(),
]);
$payload['idempotency_key'] = (string) Str::uuid();
$crossRequest = app(CreateOnlineOrderRequestAction::class)->execute($company, $collisionBranch, $payload, true);
$crossOnline = app(EmitOnlineDynamicQr::class)->execute($company, $collisionBranch, $crossRequest);
try {
    DB::table('dynamic_qr_intents')->where('id', $posIntent->id)->update(['bank_qr_id' => $crossOnline->bank_qr_id]);
    throw new RuntimeException('La base aceptó el mismo qrId en dos intenciones.');
} catch (QueryException $exception) {
    if (($exception->errorInfo[0] ?? null) !== '23000' || (int) ($exception->errorInfo[1] ?? 0) !== 1062
        || $posIntent->fresh()->bank_qr_id !== $posIntent->bank_qr_id) {
        throw $exception;
    }
    echo "PASS: InnoDB rechazó asociar el mismo qrId a dos intenciones POS/online.\n";
}
$crossFailed = false;
if ($exploratory) {
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-cross-'.bin2hex(random_bytes(12));
    $crossIntents = [$posIntent, $crossOnline];
    $common = ['company' => $company->id, 'category' => $category->ulid, 'barrier' => $barrier,
        'operation' => 'confirm_qr', 'amount' => '12.00', 'transaction_id' => 'simulated-cross-evidence'];
    $worker = fn ($index) => new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($common + ['ready' => $barrier.'.'.$index,
        'branch' => $index === 0 ? $branch->id : $collisionBranch->id, 'intent' => $crossIntents[$index]->id,
        'bank_id' => $crossIntents[$index]->bank_qr_id], JSON_THROW_ON_ERROR))]);
    $a = $worker(0);
    $b = $worker(1);
    $a->start();
    $b->start();
    $deadline = microtime(true) + 30;
    while ((! is_file($barrier.'.0') || ! is_file($barrier.'.1')) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($barrier.'.0') || ! is_file($barrier.'.1')) {
        throw new RuntimeException('Workers POS/online no preparados.');
    }
    file_put_contents($barrier, 'go');
    $a->wait();
    $b->wait();
    if (! $a->isSuccessful() || ! $b->isSuccessful()) {
        throw new RuntimeException('Falló la carrera POS/online: '.$a->getErrorOutput().' '.$b->getErrorOutput());
    }
    $crossIds = [$posIntent->id, $crossOnline->id];
    $paid = DB::table('dynamic_qr_intents')->whereIn('id', $crossIds)->where('status', 'paid')->count();
    $review = DB::table('dynamic_qr_intents')->whereIn('id', $crossIds)->where('issue_code', 'bank_transaction_identity_collision')->count();
    $crossPayments = DB::table('payments')->whereIn('order_id', [$posIntent->order_id, $crossOnline->order_id])->count();
    $crossFailed = $paid !== 1 || $review !== 1 || $crossPayments !== 1;
    foreach ($crossIntents as $candidate) {
        $records = DB::table('payments')->where('order_id', $candidate->order_id)->get();
        if ($records->count() > 1 || $records->contains(fn ($record) => (int) $record->cash_session_id !== (int) $candidate->cash_session_id
            || (int) $record->company_id !== (int) $candidate->company_id || (int) $record->branch_id !== (int) $candidate->branch_id)) {
            throw new RuntimeException('La carrera cruzada duplicó pagos por orden o utilizó otra caja.');
        }
        foreach (DB::table('order_items')->where('order_id', $candidate->order_id)->pluck('id') as $itemId) {
            if (DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->where('reference_id', $itemId)->count() > 1) {
                throw new RuntimeException('La carrera cruzada duplicó un consumo de inventario.');
            }
        }
        if (DB::table('kitchen_dispatches')->where('order_id', $candidate->order_id)->count() > 1
            || DB::table('print_attempts')->where('order_id', $candidate->order_id)->where('is_reprint', false)
                ->select('purpose')->groupBy('purpose')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('La carrera cruzada duplicó una comanda o ticket automático.');
        }
    }
    if ($crossOnline->fresh()->status->value === 'reconciliation_required'
        && DB::table('inventory_reservations')->where('order_id', $crossOnline->order_id)->where('status', 'reserved')->count() !== 1) {
        throw new RuntimeException('La carrera cruzada liberó una reserva incierta.');
    }
    echo "PASS: integridad por orden de caja, inventario, comandas, tickets y reserva incierta en carrera cruzada.\n";
    echo $crossFailed
        ? "FAIL: carrera POS/online: pagadas=$paid, revisión=$review, pagos=$crossPayments.\n"
        : "PASS: carrera POS/online con una confirmación y una revisión.\n";
}

// Keep exercising independent cases even when the known collision fails.
$cases = $exploratory ? ['independent', 'missing', 'late', 'online_first', 'qr_policy_cross'] : ['independent', 'late', 'qr_policy_cross'];
foreach ($cases as $case) {
    foreach ([$branch, $collisionBranch] as $stockBranch) {
        app(ApplyInventoryMovementAction::class)->execute($company, $stockBranch, $inventory, InventoryMovementType::AdjustmentIn,
            '1.000', '1.000000', $user, reason: 'Escenario aislado POS online');
    }
    $pos = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $user);
    app(AddOrderItemAction::class)->execute($pos, $variant, 1, $user);
    $reference = 'matrix-'.$case.'-'.bin2hex(random_bytes(4));
    $posQr = DynamicQrIntent::query()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'order_id' => $pos->id,
        'active_order_id' => $pos->id, 'cash_session_id' => $cash->id, 'user_id' => $user->id,
        'bank_qr_setting_id' => $posIntent->bank_qr_setting_id, 'amount' => '12.00', 'currency' => 'BOB',
        'commercial_reference' => $reference, 'bank_qr_id' => $reference, 'idempotency_key' => (string) Str::uuid(),
        'status' => 'pending', 'expires_at' => now()->addDay(),
    ]);
    $payload['idempotency_key'] = (string) Str::uuid();
    $preparation = app(CreateOnlineOrderRequestAction::class)->execute($company, $collisionBranch, $payload, true);
    $onlineQr = app(EmitOnlineDynamicQr::class)->execute($company, $collisionBranch, $preparation);
    if ($case === 'late') {
        $onlineQr->update(['expires_at' => now()->subMinute()]);
    }
    $pair = [$posQr, $onlineQr];
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-matrix-'.bin2hex(random_bytes(12));
    $common = ['company' => $company->id, 'category' => $category->ulid, 'barrier' => $barrier, 'operation' => 'confirm_qr', 'amount' => '12.00'];
    $worker = fn ($index, $suffix) => new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($common + [
        'ready' => $barrier.'.'.$suffix, 'branch' => $index === 0 ? $branch->id : $collisionBranch->id,
        'intent' => $pair[$index]->id, 'bank_id' => $pair[$index]->bank_qr_id,
        'transaction_id' => $case === 'missing' ? null : (in_array($case, ['online_first', 'qr_policy_cross'], true) ? $reference : $reference.'-'.$index),
        'qr_identity_policy' => $case === 'qr_policy_cross',
    ], JSON_THROW_ON_ERROR))]);
    $a = $worker(0, 'a');
    $b = $worker(1, 'b');
    $b->start();
    if ($case !== 'online_first') {
        $a->start();
    }
    $deadline = microtime(true) + 30;
    while ((! is_file($barrier.'.b') || ($case !== 'online_first' && ! is_file($barrier.'.a'))) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($barrier.'.b') || ($case !== 'online_first' && ! is_file($barrier.'.a'))) {
        throw new RuntimeException("Workers de $case no preparados.");
    }
    file_put_contents($barrier, 'go');
    $b->wait();
    if ($case === 'online_first') {
        $a->start();
    }
    $a->wait();
    if (! $a->isSuccessful() || ! $b->isSuccessful()) {
        throw new RuntimeException("Falló el escenario $case: ".$a->getErrorOutput().' '.$b->getErrorOutput());
    }
    $posStatus = $posQr->fresh()->status->value;
    $onlineStatus = $onlineQr->fresh()->status->value;
    $expectedOnline = in_array($case, ['independent', 'qr_policy_cross'], true) ? 'paid' : 'reconciliation_required';
    $expectedIssue = $case === 'missing' ? 'bank_transaction_identity_missing' : ($case === 'late' ? 'late_payment' : null);
    $countPayments = fn () => DB::table('payments')->whereIn('order_id', [$posQr->order_id, $onlineQr->order_id])->count();
    if ($case === 'online_first') {
        if ($countPayments() > 1) {
            $crossFailed = true;
            echo 'FAIL: confirmación online seguida de POS con identidad coincidente generó '.$countPayments()." pagos.\n";
        } else {
            echo "PASS: la secuencia online seguida de POS evitó dos pagos.\n";
        }

        continue;
    }
    $expectedPayments = in_array($case, ['independent', 'qr_policy_cross'], true) ? 2 : 1;
    if ($posStatus !== 'paid' || $onlineStatus !== $expectedOnline || $onlineQr->fresh()->issue_code !== $expectedIssue || $countPayments() !== $expectedPayments) {
        throw new RuntimeException("Resultado inesperado $case: POS=$posStatus online=$onlineStatus pagos=".$countPayments());
    }
    // Repeated checks cannot create another payment for either intention.
    $a = $worker(0, 'retry-a');
    $b = $worker(1, 'retry-b');
    $a->start();
    $b->start();
    $a->wait();
    $b->wait();
    if (! $a->isSuccessful() || ! $b->isSuccessful() || $countPayments() !== $expectedPayments) {
        throw new RuntimeException("El reintento $case alteró los pagos o falló.");
    }
    echo "PASS: $case, POS=$posStatus, online=$onlineStatus, pagos=$expectedPayments; reintento sin duplicados.\n";
    if ($case === 'late') {
        DB::table('dynamic_qr_intents')->where('id', $onlineQr->id)->update(['last_checked_at' => null]);
        $changedId = $reference.'-changed';
        $changed = new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($common + [
            'ready' => $barrier.'.changed', 'branch' => $collisionBranch->id, 'intent' => $onlineQr->id,
            'bank_id' => $onlineQr->bank_qr_id, 'transaction_id' => $changedId,
        ], JSON_THROW_ON_ERROR))]);
        $changed->run();
        if (! $changed->isSuccessful()) {
            throw new RuntimeException('Falló la segunda observación: '.$changed->getErrorOutput());
        }
        $evidence = DB::table('bank_qr_observations')->where('dynamic_qr_intent_id', $onlineQr->id)->orderBy('id')
            ->get()->map(fn ($row) => Crypt::decryptString($row->evidence))->implode(' ');
        if (! str_contains($evidence, $reference.'-1') || ! str_contains($evidence, $changedId)) {
            $crossFailed = true;
            echo "FAIL: la segunda respuesta reemplazó evidencia anterior de la intención tardía.\n";
        } else {
            echo "PASS: se conservaron ambas observaciones tardías.\n";
        }
        $beforeRepeatedObservation = DB::table('bank_qr_observations')->where('dynamic_qr_intent_id', $onlineQr->id)->where('kind', 'observation')->count();
        DB::table('dynamic_qr_intents')->where('id', $onlineQr->id)->update(['last_checked_at' => null]);
        $changed->run();
        if (! $changed->isSuccessful() || DB::table('bank_qr_observations')->where('dynamic_qr_intent_id', $onlineQr->id)->where('kind', 'observation')->count() !== $beforeRepeatedObservation + 1) {
            throw new RuntimeException('Una observación repetida se perdió o el reintento falló.');
        }
        echo "PASS: observación repetida conservada sin inferir identidad de evento.\n";
        $observation = DB::table('bank_qr_observations')->where('dynamic_qr_intent_id', $onlineQr->id)->first();
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('bank_qr_observations')->where('id', $observation->id);
                $operation === 'update' ? $query->update(['kind' => 'changed']) : $query->delete();
                throw new RuntimeException("La operación $operation de evidencia no fue rechazada.");
            } catch (QueryException) {
                if (! DB::table('bank_qr_observations')->where('id', $observation->id)->exists()) {
                    throw new RuntimeException('La evidencia dejó de existir.');
                }
            }
        }
        echo "PASS: InnoDB rechazó la actualización y eliminación de evidencia.\n";
    }
}
if ($crossFailed) {
    throw new RuntimeException('Una o más garantías de coordinación o conservación de evidencia fallaron.');
}
