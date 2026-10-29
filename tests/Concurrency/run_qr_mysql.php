<?php

// Crea exclusivamente una base nueva y no la borra. Nunca usa RefreshDatabase.
use App\Actions\OpenCashSessionAction;
use App\Enums\DynamicQrStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TableChargeMode;
use App\Models\BankQrSetting;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\DynamicQrIntent;
use App\Models\KitchenDispatch;
use App\Models\KitchenDispatchItem;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

if (($argv[1] ?? null) !== '--create-isolated-database') {
    throw new RuntimeException('Se requiere --create-isolated-database; no se reutilizan bases existentes.');
}
$nonce = bin2hex(random_bytes(6));
$database = 'pizzeria_qr_test_'.$nonce;
$testDatabase = $database;
require __DIR__.'/qr_bootstrap.php';
$cfg = config('database.connections.mysql');
// PDO administrativo sin base seleccionada: nunca abre pizzeria_saas.
$admin = new PDO('mysql:host='.$cfg['host'].';port='.(int) $cfg['port'].';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$check = $admin->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
$check->execute([$database]);
if ((int) $check->fetchColumn() !== 0) {
    throw new RuntimeException('La base ya existe; se cancela la ejecución.');
}
$admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
useQrTestDatabase($database);
if (DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()')->n !== 0) {
    throw new RuntimeException('La base no está vacía; no se ejecutarán migraciones.');
}
DB::statement('CREATE TABLE qr_concurrency_guard (nonce VARCHAR(12) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
DB::table('qr_concurrency_guard')->insert(['nonce' => $nonce]);
if (Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]) !== 0) {
    throw new RuntimeException('Fallaron migraciones en la base aislada: '.Artisan::output());
}
$badEngines = DB::select('SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND ENGINE <> ?', ['InnoDB']);
if ($badEngines) {
    throw new RuntimeException('Hay tablas que no usan InnoDB.');
}

function fixture(): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $users = $sessions = [];
    for ($i = 0; $i < 2; $i++) {
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->owner()->create();
        $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja '.$i, 'is_active' => true]);
        $users[] = $user;
        $sessions[] = app(OpenCashSessionAction::class)->execute($register, '0.00', $user);
    }
    $order = Order::factory()->for($branch)->create(['company_id' => $company->id, 'created_by' => $users[0]->id,
        'type' => OrderType::Takeaway, 'status' => OrderStatus::ReadyForPayment, 'subtotal' => '80.00', 'total' => '80.00']);
    OrderItem::factory()->for($order)->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'status' => OrderItemStatus::Served, 'line_total' => '80.00', 'unit_price' => '80.00', 'created_by' => $users[0]->id]);
    $setting = BankQrSetting::query()->create(['company_id' => $company->id, 'active_scope' => $company->id.':company',
        'credentials' => ['username' => 'fake', 'password' => 'fake', 'aes_key' => 'fake', 'account_credit' => 'fake']]);

    return compact('company', 'branch', 'users', 'sessions', 'order', 'setting');
}

function pending(array $fixture, string $tag): DynamicQrIntent
{
    return DynamicQrIntent::query()->create(['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
        'order_id' => $fixture['order']->id, 'active_order_id' => $fixture['order']->id, 'cash_session_id' => $fixture['sessions'][0]->id,
        'user_id' => $fixture['users'][0]->id, 'bank_qr_setting_id' => $fixture['setting']->id, 'amount' => '80.00', 'currency' => 'BOB',
        'commercial_reference' => 'race-'.$tag, 'bank_qr_id' => 'test-'.$tag, 'idempotency_key' => 'race-'.$tag,
        'status' => DynamicQrStatus::Pending, 'expires_at' => now()->addDay()]);
}

function race(string $case, string $database, string $nonce, array $operations): array
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qr-race-'.$nonce.'-'.$case;
    if (file_exists($directory) || ! mkdir($directory, 0700)) {
        throw new RuntimeException('No se pudo crear barrera aislada.');
    }
    $workers = [];
    foreach ($operations as $id => [$operation, $args]) {
        $worker = new Process([PHP_BINARY, __DIR__.'/qr_worker.php', $database, $nonce, $directory, $id, $operation, base64_encode(json_encode($args, JSON_THROW_ON_ERROR))], dirname(__DIR__, 2));
        $worker->setTimeout(45);
        $worker->start();
        $workers[] = $worker;
    }
    $deadline = microtime(true) + 35;
    while ((! is_file($directory.'/ready-a') || ! is_file($directory.'/ready-b')) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($directory.'/ready-a') || ! is_file($directory.'/ready-b')) {
        throw new RuntimeException('No se sincronizaron los dos procesos en '.$case);
    }
    file_put_contents($directory.'/start', 'go');
    $results = [];
    foreach ($workers as $worker) {
        $worker->wait();
        if (! $worker->isSuccessful()) {
            throw new RuntimeException('Falló proceso '.$case.': '.$worker->getErrorOutput().' '.$worker->getOutput());
        }
        $results[] = json_decode(trim($worker->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    }
    if (max(array_column($results, 'started')) >= min(array_column($results, 'ended'))) {
        throw new RuntimeException('Los procesos no se solaparon en '.$case);
    }

    return ['workers' => $results, 'bank_calls' => is_file($directory.'/bank_calls') ? file($directory.'/bank_calls', FILE_IGNORE_NEW_LINES) : []];
}

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$results = [];
$f = fixture();
$prepared = pending($f, 'claim_prepared');
$prepared->update(['status' => DynamicQrStatus::Creating, 'generation_phase' => 'prepared', 'bank_qr_id' => null]);
$expiryBeforeClaim = $prepared->fresh()->expires_at->toISOString();
$claim = race('claim_prepared', $database, $nonce, ['a' => ['claim_prepared', ['intent' => $prepared->id]], 'b' => ['claim_prepared', ['intent' => $prepared->id]]]);
verify(count(array_filter($claim['workers'], fn ($worker) => $worker['result']['claimed'] ?? false)) === 1, 'La reclamación no fue exclusiva.');
verify($prepared->refresh()->generation_phase === 'send_armed', 'No se confirmó la autorización.');
verify($prepared->expires_at->toISOString() === $expiryBeforeClaim, 'La reclamación modificó el vencimiento.');
verify($claim['bank_calls'] === [] && Payment::query()->where('order_id', $f['order']->id)->count() === 0, 'La reclamación produjo efectos inesperados.');
$results['claim_prepared'] = $claim;
foreach (['before', 'after'] as $fault) {
    $f = fixture();
    $args = ['order' => $f['order']->id, 'session' => $f['sessions'][0]->id, 'user' => $f['users'][0]->id, 'key' => 'claim-fault-'.$fault, 'fault' => $fault];
    $faultResult = race('claim_fault_'.$fault, $database, $nonce, ['a' => ['claim_commit_fault', $args], 'b' => ['idle', []]]);
    $intent = DynamicQrIntent::query()->where('order_id', $f['order']->id)->sole();
    verify($intent->generation_phase === ($fault === 'before' ? 'prepared' : 'send_armed'), 'Estado incorrecto tras fallo de reclamación.');
    verify($faultResult['bank_calls'] === [] && Payment::query()->where('order_id', $f['order']->id)->count() === 0, 'Se llamó al banco tras fallo de commit.');
    $retry = race('claim_retry_'.$fault, $database, $nonce, ['a' => ['generate', $args], 'b' => ['idle', []]]);
    verify($retry['bank_calls'] === [] && $intent->refresh()->generation_phase === ($fault === 'before' ? 'prepared' : 'send_armed'), 'El reintento repitió el envío.');
    $results['claim_fault_'.$fault] = $faultResult;
    $results['claim_retry_'.$fault] = $retry;
}
foreach (['confirm_both', 'browser_job', 'confirm_cash', 'cancel_confirm', 'scheduler_both', 'reconnect'] as $case) {
    $f = fixture();
    $intent = pending($f, $case);
    $cashBefore = DB::table('cash_movements')->where('company_id', $f['company']->id)->count();
    $args = ['intent' => $intent->id];
    $operations = match ($case) {
        'confirm_both' => ['a' => ['check', $args], 'b' => ['check', $args]],
        'browser_job' => ['a' => ['check', $args], 'b' => ['job', $args]],
        'confirm_cash' => ['a' => ['check', $args], 'b' => ['cash', ['order' => $f['order']->id, 'session' => $f['sessions'][0]->id, 'user' => $f['users'][0]->id]]],
        'cancel_confirm' => ['a' => ['cancel', $args], 'b' => ['check', $args]],
        'scheduler_both' => ['a' => ['scheduler', $args], 'b' => ['scheduler', $args]],
        'reconnect' => ['a' => ['reconnect', $args], 'b' => ['idle', []]],
    };
    $result = race($case, $database, $nonce, $operations);
    verify(Payment::query()->where('order_id', $f['order']->id)->count() === 1, 'Número incorrecto de pagos: '.$case);
    verify($intent->refresh()->status === DynamicQrStatus::Paid, 'QR no confirmado: '.$case);
    verify(DB::table('cash_movements')->where('company_id', $f['company']->id)->count() === $cashBefore, 'Movimiento de efectivo inesperado: '.$case);
    verify(DB::table('inventory_movements')->where('company_id', $f['company']->id)->count() === 0, 'Movimiento de inventario inesperado: '.$case);
    verify(DB::table('print_attempts')->where('company_id', $f['company']->id)->count() <= 1, 'Impresión duplicada: '.$case);
    verify(count(array_filter($result['bank_calls'], fn ($call) => $call === 'status')) === 1, 'Número incorrecto de consultas bancarias: '.$case);
    if ($case === 'cancel_confirm') {
        verify(! in_array('cancel', $result['bank_calls'], true), 'Se canceló después de pago confirmado.');
    }
    $results[$case] = $result;
}
$f = fixture();
$race = race('generation', $database, $nonce, [
    'a' => ['generate', ['order' => $f['order']->id, 'session' => $f['sessions'][0]->id, 'user' => $f['users'][0]->id, 'key' => 'race-user-a']],
    'b' => ['generate', ['order' => $f['order']->id, 'session' => $f['sessions'][1]->id, 'user' => $f['users'][1]->id, 'key' => 'race-user-b']],
]);
verify(DynamicQrIntent::query()->where('order_id', $f['order']->id)->count() === 1, 'Se creó más de un intento.');
verify(count(array_filter($race['bank_calls'], fn ($call) => $call === 'generate')) === 1, 'Se solicitó más de un QR.');
verify(Payment::query()->where('order_id', $f['order']->id)->count() === 0, 'Se registró un pago antes de confirmación.');
$results['generation'] = $race;
$f = fixture();
$mixedArgs = ['order' => $f['order']->id, 'session' => $f['sessions'][0]->id, 'user' => $f['users'][0]->id, 'key' => 'mixed-same-key'];
$mixed = race('mixed_same', $database, $nonce, ['a' => ['generate_mixed', $mixedArgs], 'b' => ['generate_mixed', $mixedArgs]]);
$mixedIntent = DynamicQrIntent::query()->where('order_id', $f['order']->id)->sole();
verify($mixedIntent->amount === '50.00', 'Monto QR mixto incorrecto.');
verify(Payment::query()->where('order_id', $f['order']->id)->count() === 1, 'El efectivo mixto se duplicó.');
verify(count(array_filter($mixed['bank_calls'], fn ($call) => $call === 'generate')) === 1, 'El QR mixto se generó más de una vez.');
$confirmation = race('mixed_confirm', $database, $nonce, ['a' => ['check', ['intent' => $mixedIntent->id]], 'b' => ['job', ['intent' => $mixedIntent->id]]]);
verify(Payment::query()->where('order_id', $f['order']->id)->count() === 2, 'El mixto no terminó con dos pagos.');
verify(Payment::query()->where('order_id', $f['order']->id)->where('method', 'cash')->sole()->amount === '30.00', 'Efectivo mixto incorrecto.');
verify(Payment::query()->where('order_id', $f['order']->id)->where('method', 'qr')->sole()->amount === '50.00', 'Pago QR mixto incorrecto.');
verify(DB::table('cash_movements')->where('company_id', $f['company']->id)->where('type', 'sale_cash')->count() === 1, 'Movimiento mixto de caja duplicado.');
$results['mixed_same'] = $mixed;
$results['mixed_confirm'] = $confirmation;
$f = fixture();
$f['order']->update(['type' => OrderType::DineIn, 'charge_mode' => TableChargeMode::PerBatch, 'status' => OrderStatus::Open]);
$dispatch = KitchenDispatch::query()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'order_id' => $f['order']->id,
    'sequence_number' => 1, 'status' => KitchenDispatchStatus::AwaitingPayment, 'dispatched_at' => now(), 'dispatched_by' => $f['users'][0]->id, 'total' => '80.00', 'gross_subtotal' => '80.00']);
$item = $f['order']->items()->firstOrFail();
$item->update(['status' => OrderItemStatus::PendingPayment, 'requires_preparation' => true]);
KitchenDispatchItem::query()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'kitchen_dispatch_id' => $dispatch->id,
    'order_item_id' => $item->id, 'gross_total' => '80.00', 'net_total' => '80.00']);
$batchArgs = ['order' => $f['order']->id, 'dispatch' => $dispatch->id, 'session' => $f['sessions'][0]->id, 'user' => $f['users'][0]->id, 'key' => 'mixed-batch-key'];
$batch = race('mixed_batch', $database, $nonce, ['a' => ['generate_mixed', $batchArgs], 'b' => ['generate_mixed', $batchArgs]]);
$batchIntent = DynamicQrIntent::query()->where('order_id', $f['order']->id)->sole();
verify($dispatch->refresh()->status === KitchenDispatchStatus::AwaitingPayment && $item->refresh()->status === OrderItemStatus::PendingPayment, 'Tanda liberada antes de pagar.');
$batchConfirm = race('batch_confirm', $database, $nonce, ['a' => ['check', ['intent' => $batchIntent->id]], 'b' => ['job', ['intent' => $batchIntent->id]]]);
verify(Payment::query()->where('order_id', $f['order']->id)->count() === 2, 'Tanda mixta no tiene dos pagos.');
verify($dispatch->refresh()->status === KitchenDispatchStatus::Settled && $item->refresh()->status === OrderItemStatus::Sent, 'Tanda no liberada correctamente.');
verify($f['order']->refresh()->status === OrderStatus::Open, 'Se cerró la mesa anticipadamente.');
verify(DB::table('inventory_movements')->where('company_id', $f['company']->id)->count() === 0, 'Inventario duplicado en tanda.');
verify(DB::table('print_attempts')->where('company_id', $f['company']->id)->count() <= 1, 'Impresión duplicada de tanda.');
$results['mixed_batch'] = $batch;
$results['batch_confirm'] = $batchConfirm;
$f = fixture();
$mixedDifferent = race('mixed_two_cashiers', $database, $nonce, [
    'a' => ['generate_mixed', ['order' => $f['order']->id, 'session' => $f['sessions'][0]->id, 'user' => $f['users'][0]->id, 'key' => 'mixed-a']],
    'b' => ['generate_mixed', ['order' => $f['order']->id, 'session' => $f['sessions'][1]->id, 'user' => $f['users'][1]->id, 'key' => 'mixed-b']],
]);
verify(DynamicQrIntent::query()->where('order_id', $f['order']->id)->count() === 1, 'Dos cajeros crearon dos QR.');
verify(Payment::query()->where('order_id', $f['order']->id)->count() === 1, 'Dos cajeros registraron efectivo duplicado.');
verify(count(array_filter($mixedDifferent['workers'], fn ($worker) => $worker['result']['rejected'] ?? false)) === 1, 'No se rechazó el segundo efectivo.');
$results['mixed_two_cashiers'] = $mixedDifferent;
$f = fixture();
$second = Order::factory()->for($f['branch'])->create(['company_id' => $f['company']->id, 'created_by' => $f['users'][0]->id,
    'type' => OrderType::Takeaway, 'status' => OrderStatus::ReadyForPayment, 'subtotal' => '1.00', 'total' => '1.00']);
$deadlock = race('deadlock', $database, $nonce, [
    'a' => ['deadlock', ['first' => $f['order']->id, 'second' => $second->id]],
    'b' => ['deadlock', ['first' => $second->id, 'second' => $f['order']->id]],
]);
verify(max(array_map(fn ($worker) => $worker['result']['attempts'] ?? 0, $deadlock['workers'])) > 1, 'No se observó un reintento por deadlock real.');
$results['deadlock'] = $deadlock;
$f = fixture();
$cashArgs = ['order' => $f['order']->id, 'session' => $f['sessions'][0]->id, 'user' => $f['users'][0]->id];
$cashDeadlock = race('deadlock_cash', $database, $nonce, ['a' => ['deadlock_cash', $cashArgs], 'b' => ['deadlock_cash', $cashArgs]]);
verify(max(array_map(fn ($worker) => $worker['result']['attempts'] ?? 0, $cashDeadlock['workers'])) > 1, 'No se observó reintento del pago tras deadlock.');
verify(Payment::query()->where('order_id', $f['order']->id)->count() === 1, 'Pago de efectivo duplicado después de deadlock.');
verify(DB::table('cash_movements')->where('company_id', $f['company']->id)->where('type', 'sale_cash')->count() === 1, 'Caja duplicada después de deadlock.');
$results['deadlock_cash'] = $cashDeadlock;
echo json_encode(['database' => $database, 'engine' => 'InnoDB', 'results' => $results, 'preserved' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
