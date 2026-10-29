<?php

use App\Actions\DynamicQrAction;
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
use App\Models\DynamicQrIntent;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

$bankCalls = 0;
Http::fake(function ($request) use (&$bankCalls) {
    $url = $request->url();
    if (str_contains($url, '/encrypt')) {
        return Http::response('"fake"');
    }
    if (str_contains($url, '/authenticate')) {
        return Http::response(['responseCode' => 0, 'token' => 'fake']);
    }
    if (str_contains($url, '/generateQR')) {
        $bankCalls++;

        return Http::response(['responseCode' => 0, 'qrId' => 'test-'.substr($request['transactionId'], -12), 'qrImage' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=']);
    }
    if (str_contains($url, '/statusQR/')) {
        $qr = rawurldecode(basename(parse_url($url, PHP_URL_PATH)));
        $intent = DynamicQrIntent::query()->where('bank_qr_id', $qr)->firstOrFail();

        return Http::response(['responseCode' => 0, 'statusQrCode' => 1, 'payment' => ['qrId' => $qr, 'amount' => $intent->amount, 'currency' => 'BOB', 'transactionId' => 'test-'.$intent->id]]);
    }
    throw new RuntimeException('Solicitud no simulada.');
});

$results = [];
$f = fixtureCommit();
try {
    DB::transaction(function () use ($f): void {
        app(RegisterPaymentAction::class)->execute($f['order'], $f['session'], PaymentMethod::Cash, '30.00', $f['user'], 'rollback-cash', '30.00');
        throw new RuntimeException('fallo previo al commit');
    });
} catch (RuntimeException $e) {
    ensureResult($e->getMessage() === 'fallo previo al commit', 'Fallo inesperado.');
}
$snapshot = observe($observer, $f);
ensureResult($snapshot['payments'] === 0 && $snapshot['cash_movements'] === 0 && $snapshot['intents'] === 0, 'Rollback no confirmado.');
$results['rollback_antes_commit'] = $snapshot;
app(RegisterPaymentAction::class)->execute($f['order'], $f['session'], PaymentMethod::Cash, '30.00', $f['user'], 'rollback-cash', '30.00');
$retry = observe($observer, $f);
ensureResult($retry['payments'] === 1 && $retry['cash_movements'] === 1 && $retry['balance'] === '50.00', 'No se recuperó el rollback.');
$results['reintento_tras_rollback'] = $retry;

$f = fixtureCommit();
$action = app(DynamicQrAction::class);
$intent = $action->generate($f['order'], $f['session'], $f['user'], 'mixed-lost-response', cash: '30.00', received: '40.00');
$before = observe($observer, $f);
// La excepción se inyecta después de que la acción terminó: no es un corte de red en COMMIT.
try {
    throw new RuntimeException('respuesta simulada perdida después del commit');
} catch (RuntimeException $e) {
    DB::disconnect('mysql');
}
$again = $action->generate($f['order'], $f['session'], $f['user'], 'mixed-lost-response', cash: '30.00', received: '40.00');
$after = observe($observer, $f);
ensureResult($before === $after && $after['payments'] === 1 && $after['cash_movements'] === 1 && $after['intents'] === 1 && $after['paid'] === '30.00' && $after['balance'] === '50.00' && $again->id === $intent->id && $bankCalls === 1, 'Reintento mixto no idempotente.');
$results['mixto_respuesta_simulada_perdida'] = $after + ['bank_generate_calls' => $bankCalls];

$paid = $action->check($again);
$before = observe($observer, $f);
try {
    throw new RuntimeException('respuesta simulada perdida después de confirmación');
} catch (RuntimeException $e) {
    DB::disconnect('mysql');
}
$recovered = $action->check(DynamicQrIntent::findOrFail($paid->id));
$after = observe($observer, $f);
ensureResult($before === $after && $after['payments'] === 2 && $after['cash_movements'] === 1 && $after['paid'] === '80.00' && $after['balance'] === '0.00' && $recovered->status->value === 'paid' && $bankCalls === 1, 'Confirmación repetida no idempotente.');
$results['qr_respuesta_simulada_perdida'] = $after + ['bank_generate_calls' => $bankCalls];

// Inyecta la misma excepción desde PDO::commit, tras commit o rollback real.
// No simula una interrupción física de red ni sustituye una prueba con proxy.
class CommitFaultPdo extends PDO
{
    public string $fault = '';

    public function commit(): bool
    {
        if ($this->fault === '') {
            return parent::commit();
        }
        $fault = $this->fault;
        $this->fault = '';
        if ($fault === 'commit') {
            parent::commit();
        } else {
            parent::rollBack();
        }
        throw new PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away (injected)');
    }
}

foreach (['commit', 'rollback'] as $fault) {
    $f = fixtureCommit();
    $callsBefore = $bankCalls;
    $pdo = new CommitFaultPdo($dsn.';dbname='.$testDatabase, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    DB::connection()->setPdo($pdo);
    $pdo->fault = $fault;
    $caught = false;
    $returned = null;
    try {
        $returned = $action->generate($f['order'], $f['session'], $f['user'], 'boundary-'.$fault, cash: '30.00', received: '40.00');
    } catch (DomainException $e) {
        $caught = true;
    }
    ensureResult($fault === 'commit' ? ($returned !== null && ! $caught) : ($returned === null && $caught), 'Resultado de recuperación inesperado.');
    $snapshot = observe($observer, $f);
    ensureResult($snapshot['payments'] === ($fault === 'commit' ? 1 : 0)
        && $snapshot['cash_movements'] === ($fault === 'commit' ? 1 : 0)
        && $snapshot['intents'] === ($fault === 'commit' ? 1 : 0)
        && $bankCalls === $callsBefore, 'Estado inesperado tras fallo en commit.');
    DB::purge('mysql');
    $recovered = $action->generate($f['order'], $f['session'], $f['user'], 'boundary-'.$fault, cash: '30.00', received: '40.00');
    $after = observe($observer, $f);
    ensureResult($after['payments'] === 1 && $after['cash_movements'] === 1 && $after['balance'] === '50.00'
        && $bankCalls === $callsBefore + ($fault === 'rollback' ? 1 : 0), 'Reintento inseguro en límite de commit.');
    $results['limite_commit_'.$fault] = ['after_fault' => $snapshot, 'after_same_key_retry' => $after,
        'intent_status' => $recovered->status->value, 'new_bank_generations' => $bankCalls - $callsBefore];
}

echo json_encode(['database' => $testDatabase, 'engine' => 'InnoDB', 'results' => $results, 'real_connection_loss_during_commit' => 'NOT TESTED', 'preserved' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
