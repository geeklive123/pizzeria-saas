<?php

// Uses only a new, explicitly identified local test server and database.
use App\Actions\ConvertOnlineOrderRequestAction;
use App\Actions\UpdateRecipeAction;
use App\Enums\ProductType;
use App\Http\Controllers\OnlineOrderRequestController;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\OnlineOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
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
    config()->set('storefront.online_pos_conversion_enabled', true);
    $payload = $data['status'] === 'legacy_review'
        ? ['note' => 'Cliente informado en prueba', 'contacted' => '1']
        : ['status' => $data['status']];
    $request = Request::create('/online-orders/'.$data['order'].'/transition', 'POST', $payload);
    $request->setUserResolver(fn () => $user);
    app('session')->start();
    file_put_contents($data['ready'], 'ready');
    while (! is_file($data['barrier'])) {
        usleep(1000);
    }
    $controller = app(OnlineOrderRequestController::class);
    $response = $data['status'] === 'legacy_review'
        ? $controller->resolveLegacy($request, (string) $data['order'])
        : $controller->transition($request, (string) $data['order'], app(ConvertOnlineOrderRequestAction::class));
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
$category = Category::factory()->for($company)->create();
$product = Product::factory()->for($category)->create(['type' => ProductType::Beverage, 'is_active' => true]);
$variant = ProductVariant::factory()->for($product)->create(['price' => '12.00', 'is_active' => true, 'requires_preparation' => false]);
$users = [User::factory()->create(), User::factory()->create()];
foreach ($users as $user) {
    Membership::factory()->for($company)->for($user)->owner()->create();
}
$barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masa-online-transition-'.bin2hex(random_bytes(12));
foreach ([['confirmed', 'confirmed'], ['cancelled', 'cancelled'], ['confirmed', 'cancelled'], ['cancelled', 'confirmed']] as [$first, $second]) {
    for ($i = 0; $i < 3; $i++) {
        $ordersBefore = Order::query()->count();
        $itemsBefore = OrderItem::query()->count();
        $order = OnlineOrderRequest::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'customer_name' => 'Cliente prueba',
            'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'status' => 'pending_confirmation', 'subtotal' => '12.00', 'total' => '12.00']);
        $order->items()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'product_variant_id' => $variant->id,
            'product_name' => $product->name, 'variant_name' => $variant->name, 'quantity' => 1, 'unit_price' => '12.00', 'subtotal' => '12.00', 'was_promotion' => false]);
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
        if ($expectedStatus === 'confirmed') {
            verifyTransition($order->pos_order_id !== null && $order->posOrder->items()->count() === 1, 'Orden POS ausente o líneas duplicadas.');
            verifyTransition($order->posOrder->payments()->count() === 0 && $order->posOrder->kitchenDispatches()->count() === 0, 'Pago o despacho inesperado.');
            verifyTransition(Order::query()->count() === $ordersBefore + 1 && OrderItem::query()->count() === $itemsBefore + 1, 'Orden o línea duplicada o huérfana.');
            if ($i === 0) {
                file_put_contents($barrier, 'go');
                $retry = $make($users[0], 'confirmed', $barrier.'.retry');
                $retry->run();
                unlink($barrier);
                if (is_file($barrier.'.retry')) {
                    unlink($barrier.'.retry');
                }
                verifyTransition($retry->isSuccessful(), 'Error de reintento tras respuesta perdida: '.$retry->getErrorOutput());
                $retryResult = json_decode($retry->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                verifyTransition($retryResult['notice'] === 'Esta solicitud ya fue atendida. Se conserva el estado actual.'
                    && Order::query()->count() === $ordersBefore + 1 && OrderItem::query()->count() === $itemsBefore + 1,
                    'Reintento incierto duplicó la conversión.');
            }
        } else {
            verifyTransition($order->pos_order_id === null, 'Solicitud cancelada con orden POS.');
            verifyTransition(Order::query()->count() === $ordersBefore && OrderItem::query()->count() === $itemsBefore, 'La cancelación dejó registros POS.');
        }
    }
}
// Different requests compete for the same recipe ingredient; only one may reserve it.
$unit = Unit::factory()->for($company)->create();
$ingredient = Ingredient::factory()->for($company)->for($unit)->create();
$stockItem = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id,
    'ingredient_id' => $ingredient->id, 'name' => 'Ingrediente compartido de prueba', 'is_active' => true]);
InventoryStock::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
    'inventory_item_id' => $stockItem->id, 'quantity' => '1.000', 'average_cost' => '1.000000']);
InventoryBatch::factory()->for($company)->for($branch)->for($stockItem)
    ->create(['quantity_received' => '1.000', 'quantity_remaining' => '1.000']);
$stockVariants = [];
foreach (range(1, 2) as $i) {
    $stockProduct = Product::factory()->for($category)->create(['type' => ProductType::Beverage, 'is_active' => true]);
    $stockVariant = ProductVariant::factory()->for($stockProduct)->create(['price' => '12.00', 'requires_preparation' => true]);
    app(UpdateRecipeAction::class)->execute($company, $stockVariant,
        [['ingredient_id' => $ingredient->id, 'quantity' => '1.000']], 'Receta prueba');
    $stockVariants[] = $stockVariant;
}
$stockRequests = [];
foreach ($stockVariants as $stockVariant) {
    $request = OnlineOrderRequest::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'customer_name' => 'Cliente prueba',
        'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'status' => 'pending_confirmation', 'subtotal' => '12.00', 'total' => '12.00']);
    $request->items()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'product_variant_id' => $stockVariant->id,
        'product_name' => $stockVariant->product->name, 'variant_name' => $stockVariant->name, 'quantity' => 1, 'unit_price' => '12.00', 'subtotal' => '12.00', 'was_promotion' => false]);
    $stockRequests[] = $request;
}
$ordersBefore = Order::query()->count();
$itemsBefore = OrderItem::query()->count();
$workers = [];
foreach ($stockRequests as $index => $request) {
    $payload = ['company' => $company->id, 'branch' => $branch->id, 'order' => $request->id,
        'barrier' => $barrier, 'user' => $users[$index]->id, 'status' => 'confirmed', 'ready' => $barrier.'.'.$index];
    $workers[$index] = new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))]);
    $workers[$index]->start();
}
$deadline = microtime(true) + 30;
while ((! is_file($barrier.'.0') || ! is_file($barrier.'.1')) && microtime(true) < $deadline) {
    usleep(10000);
}
verifyTransition(is_file($barrier.'.0') && is_file($barrier.'.1'), 'Workers de inventario no preparados.');
file_put_contents($barrier, 'go');
foreach ($workers as $worker) {
    $worker->wait();
    verifyTransition($worker->isSuccessful(), 'Error de worker de inventario: '.$worker->getErrorOutput().' '.$worker->getOutput());
}
unlink($barrier);
unlink($barrier.'.0');
unlink($barrier.'.1');
$states = collect($stockRequests)->map(fn ($request) => $request->fresh()->status)->countBy();
verifyTransition(($states['confirmed'] ?? 0) === 1 && ($states['pending_confirmation'] ?? 0) === 1, 'Resultados incorrectos para ingrediente compartido.');
verifyTransition(Order::query()->count() === $ordersBefore + 1 && OrderItem::query()->count() === $itemsBefore + 1, 'Conversión duplicada o parcial en carrera de inventario.');
verifyTransition(InventoryReservation::query()->where('inventory_item_id', $stockItem->id)->where('status', 'reserved')->count() === 1, 'Reservas incorrectas en carrera de inventario.');
// A historical cancellation races with a conversion attempt and cannot create a POS order.
$legacy = OnlineOrderRequest::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
    'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'customer_name' => 'Cliente prueba',
    'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'status' => 'pending_confirmation', 'subtotal' => '12.00', 'total' => '12.00']);
$legacy->items()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'product_variant_id' => $variant->id,
    'product_name' => $product->name, 'variant_name' => $variant->name, 'quantity' => 1, 'unit_price' => '12.00', 'subtotal' => '12.00', 'was_promotion' => null]);
$ordersBefore = Order::query()->count();
$workers = [];
foreach (['confirmed', 'legacy_review'] as $index => $status) {
    $payload = ['company' => $company->id, 'branch' => $branch->id, 'order' => $legacy->id,
        'barrier' => $barrier, 'user' => $users[$index]->id, 'status' => $status, 'ready' => $barrier.'.'.$index];
    $workers[$index] = new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))]);
    $workers[$index]->start();
}
$deadline = microtime(true) + 30;
while ((! is_file($barrier.'.0') || ! is_file($barrier.'.1')) && microtime(true) < $deadline) {
    usleep(10000);
}
verifyTransition(is_file($barrier.'.0') && is_file($barrier.'.1'), 'Workers de revisión histórica no preparados.');
file_put_contents($barrier, 'go');
foreach ($workers as $worker) {
    $worker->wait();
    verifyTransition($worker->isSuccessful(), 'Error de worker histórico: '.$worker->getErrorOutput().' '.$worker->getOutput());
}
unlink($barrier);
unlink($barrier.'.0');
unlink($barrier.'.1');
$legacy->refresh();
verifyTransition($legacy->status === 'cancelled' && $legacy->legacy_reviewed_by === $users[1]->id
    && $legacy->pos_order_id === null && Order::query()->count() === $ordersBefore, 'La revisión histórica creó una orden o perdió su auditoría.');
echo "PASS: 12 carreras entre dos administradores; estado, aviso y auditoria conservados.\n";
echo "PASS: dos solicitudes compitieron por un ingrediente; una reserva y una orden.\n";
echo "PASS: revisión histórica concurrente con confirmación sin crear orden.\n";
