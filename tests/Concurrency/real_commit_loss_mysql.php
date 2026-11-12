<?php

use App\Actions\OpenCashSessionAction;
use App\Actions\RegisterPaymentAction;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Models\BankQrSetting;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\FinancialPaymentAttempt;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\ThermalPrintingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') !== '--create-isolated-database') {
    throw new RuntimeException('Se requiere --create-isolated-database.');
}
$nonce = bin2hex(random_bytes(6));
$testDatabase = 'pizzeria_qr_test_'.$nonce;
require __DIR__.'/qr_bootstrap.php';
$cfg = config('database.connections.mysql');
$dsn = 'mysql:host='.$cfg['host'].';port='.(int) $cfg['port'].';charset=utf8mb4';
$admin = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$check = $admin->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
$check->execute([$testDatabase]);
if ((int) $check->fetchColumn() !== 0) {
    throw new RuntimeException('La base de prueba ya existe.');
}
$admin->exec('CREATE DATABASE `'.$testDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
useQrTestDatabase($testDatabase);
if ((int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()')->n !== 0) {
    throw new RuntimeException('La base no está vacía.');
}
DB::statement('CREATE TABLE qr_concurrency_guard (nonce VARCHAR(12) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
DB::table('qr_concurrency_guard')->insert(['nonce' => $nonce]);
if (Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]) !== 0) {
    throw new RuntimeException('No se pudieron aplicar migraciones en la base aislada.');
}
if (DB::select('SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND ENGINE <> ?', ['InnoDB'])) {
    throw new RuntimeException('Se requiere InnoDB.');
}
// Una conexión física distinta verifica únicamente la base aislada recién creada.
$observer = new PDO($dsn.';dbname='.$testDatabase, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($observer->query('SELECT DATABASE()')->fetchColumn() !== $testDatabase || (int) $observer->query('SELECT COUNT(*) FROM qr_concurrency_guard')->fetchColumn() !== 1) {
    throw new RuntimeException('Conexión observadora incorrecta.');
}

function ensureResult(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function fixtureCommit(): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create();
    Membership::factory()->for($company)->for($user)->owner()->create();
    $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja', 'is_active' => true]);
    $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $user);
    $order = Order::factory()->for($branch)->create(['company_id' => $company->id, 'created_by' => $user->id, 'type' => OrderType::Takeaway, 'status' => OrderStatus::ReadyForPayment, 'subtotal' => '80.00', 'total' => '80.00']);
    OrderItem::factory()->for($order)->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => OrderItemStatus::Served, 'line_total' => '80.00', 'unit_price' => '80.00', 'created_by' => $user->id]);
    BankQrSetting::query()->create(['company_id' => $company->id, 'active_scope' => $company->id.':company', 'credentials' => ['username' => 'fake', 'password' => 'fake', 'aes_key' => 'fake', 'account_credit' => 'fake']]);

    return compact('company', 'branch', 'user', 'session', 'order');
}

function observe(PDO $observer, array $f): array
{
    $read = function (string $sql, int $id) use ($observer) {
        $q = $observer->prepare($sql);
        $q->execute([$id]);

        return $q->fetchColumn();
    };
    $order = $f['order']->id;
    $company = $f['company']->id;

    return ['payments' => (int) $read('SELECT COUNT(*) FROM payments WHERE order_id = ?', $order),
        'paid' => (string) $read("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id = ? AND status = 'completed'", $order),
        'balance' => (string) $read("SELECT total - (SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id = orders.id AND status = 'completed') FROM orders WHERE id = ?", $order),
        'order_status' => (string) $read('SELECT status FROM orders WHERE id = ?', $order),
        'cash_movements' => (int) $read("SELECT COUNT(*) FROM cash_movements WHERE company_id = ? AND type = 'sale_cash'", $company),
        'intents' => (int) $read('SELECT COUNT(*) FROM dynamic_qr_intents WHERE order_id = ?', $order),
        'inventory_movements' => (int) $read('SELECT COUNT(*) FROM inventory_movements WHERE company_id = ?', $company),
        'print_attempts' => (int) $read('SELECT COUNT(*) FROM print_attempts WHERE company_id = ?', $company)];
}

$f = fixtureCommit();
$f['order']->update(['subtotal' => '65.00', 'total' => '65.00']);
$directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cash-commit-proxy-'.$nonce;
if (! mkdir($directory, 0700)) {
    throw new RuntimeException('No se pudo crear el directorio de prueba.');
}
$portFile = $directory.'/port';
$droppedFile = $directory.'/dropped';
$proxy = new Process(['python', __DIR__.'/mysql_commit_proxy.py', $portFile, $droppedFile], dirname(__DIR__, 2));
$proxy->setTimeout(null);
$proxy->start();
try {
    $deadline = microtime(true) + 15;
    while (! is_file($portFile) && microtime(true) < $deadline && $proxy->isRunning()) {
        usleep(10000);
    }
    if (! is_file($portFile)) {
        throw new RuntimeException('El proxy no pudo iniciarse: '.$proxy->getErrorOutput());
    }
    $port = (int) file_get_contents($portFile);
    if ($port < 1024 || $port > 65535) {
        throw new RuntimeException('Puerto del proxy inv??lido.');
    }
    config(['database.connections.mysql.port' => $port]);
    DB::purge('mysql');
    $payment = app(RegisterPaymentAction::class)->execute($f['order']->fresh(), $f['session'], PaymentMethod::Cash, '65.00', $f['user'], 'proxy-commit-cash', '70.00');
    ensureResult(is_file($droppedFile) && file_get_contents($droppedFile) === 'mysql_ok_received_response_dropped', 'No se interrumpi?? la respuesta real de COMMIT.');
    $attempt = FinancialPaymentAttempt::query()->where('idempotency_key', 'proxy-commit-cash')->sole();
    ensureResult($attempt->status === 'confirmed' && $attempt->payment_id === $payment->id && $attempt->active_order_id === null, 'Intento no recuperado.');
    $retry = app(RegisterPaymentAction::class)->execute($f['order']->fresh(), $f['session'], PaymentMethod::Cash, '65.00', $f['user'], 'proxy-commit-cash', '70.00');
    ensureResult($retry->id === $payment->id, 'El reintento cre?? otro pago.');
    $secondRejected = false;
    try {
        app(RegisterPaymentAction::class)->execute($f['order']->fresh(), $f['session'], PaymentMethod::Cash, '65.00', $f['user'], 'another-cash-key', '70.00');
    } catch (DomainException $exception) {
        $secondRejected = true;
    }
    ensureResult($secondRejected, 'Se admitio un segundo cobro sobre el pedido ya pagado.');
    $printing = app(ThermalPrintingService::class);
    $ticket = $printing->ticket($f['order']->fresh(), $f['user'], false);
    $ticketAgain = $printing->ticket($f['order']->fresh(), $f['user'], false);
    ensureResult($ticket->id === $ticketAgain->id, 'Se duplic?? el ticket.');
    $snapshot = observe($observer, $f);
    ensureResult($snapshot['payments'] === 1 && $snapshot['paid'] === '65.00' && $snapshot['balance'] === '0.00' && $snapshot['cash_movements'] === 1 && $snapshot['print_attempts'] === 1, 'Estado financiero o de impresi??n incorrecto.');
    ensureResult($payment->received_amount === '70.00' && $payment->change_amount === '5.00', 'Cambio incorrecto.');
    echo json_encode(['database' => $testDatabase, 'engine' => 'InnoDB', 'commit_response' => 'mysql_ok_received_then_connection_closed', 'attempt' => $attempt->status, 'payment_id' => $payment->id, 'retry_payment_id' => $retry->id, 'received' => $payment->received_amount, 'change' => $payment->change_amount, 'snapshot' => $snapshot, 'preserved' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    file_put_contents($directory.'/stop', 'stop');
    $proxy->wait();
}
