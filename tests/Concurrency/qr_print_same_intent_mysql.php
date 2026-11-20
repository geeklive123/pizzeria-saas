<?php

use App\Actions\DynamicQrAction;
use App\Actions\OpenCashSessionAction;
use App\Enums\DynamicQrStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PrinterPurpose;
use App\Models\BankQrDiagnostic;
use App\Models\BankQrSetting;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\DynamicQrIntent;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PrinterSetting;
use App\Models\User;
use App\Services\BankQrHistory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') !== '--create-isolated-database') {
    throw new RuntimeException('Se requiere una base aislada nueva.');
}
$nonce = bin2hex(random_bytes(6));
$testDatabase = $database = 'pizzeria_qr_test_'.$nonce;
require __DIR__.'/qr_bootstrap.php';
$cfg = config('database.connections.mysql');
$admin = new PDO('mysql:host='.$cfg['host'].';port='.(int) $cfg['port'].';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$check = $admin->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
$check->execute([$database]);
if ((int) $check->fetchColumn() !== 0) {
    throw new RuntimeException('La base ya existe.');
}
$admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
useQrTestDatabase($database);
DB::statement('CREATE TABLE qr_concurrency_guard (nonce VARCHAR(12) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
DB::table('qr_concurrency_guard')->insert(['nonce' => $nonce]);
if (Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]) !== 0) {
    throw new RuntimeException('Fallaron las migraciones aisladas.');
}
$company = Company::factory()->create();
$branch = Branch::factory()->for($company)->create();
$user = User::factory()->create();
Membership::factory()->for($company)->for($user)->owner()->create();
$register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja de prueba', 'is_active' => true]);
$session = app(OpenCashSessionAction::class)->execute($register, '0.00', $user);
$order = Order::factory()->for($branch)->create(['company_id' => $company->id, 'created_by' => $user->id,
    'type' => OrderType::Takeaway, 'status' => OrderStatus::ReadyForPayment, 'subtotal' => '80.00', 'total' => '80.00']);
OrderItem::factory()->for($order)->create(['company_id' => $company->id, 'branch_id' => $branch->id,
    'status' => OrderItemStatus::Served, 'line_total' => '80.00', 'unit_price' => '80.00', 'created_by' => $user->id]);
$setting = BankQrSetting::query()->create(['company_id' => $company->id, 'active_scope' => $company->id.':company',
    'credentials' => ['username' => 'fake', 'password' => 'fake', 'aes_key' => 'fake', 'account_credit' => 'fake']]);
PrinterSetting::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
    'purpose' => PrinterPurpose::CustomerTicket, 'windows_printer_name' => 'Simulated printer', 'is_active' => true,
    'auto_print' => true, 'copies' => 1, 'paper_width_mm' => 80]);
$intent = DynamicQrIntent::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
    'order_id' => $order->id, 'active_order_id' => $order->id, 'cash_session_id' => $session->id,
    'user_id' => $user->id, 'bank_qr_setting_id' => $setting->id, 'amount' => '80.00', 'currency' => 'BOB',
    'commercial_reference' => 'print-'.$nonce, 'bank_qr_id' => 'test-print-'.$nonce, 'idempotency_key' => 'print-'.$nonce,
    'status' => DynamicQrStatus::Pending, 'expires_at' => now()->addDay()]);
$cashBefore = DB::table('cash_movements')->where('company_id', $company->id)->count();

foreach (['initial', 'retry'] as $case) {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qr-race-'.$nonce.'-print_'.$case;
    if (file_exists($directory) || ! mkdir($directory, 0700)) {
        throw new RuntimeException('No se pudo crear la barrera aislada.');
    }
    $workers = [];
    foreach (['a' => 'check', 'b' => 'job'] as $workerId => $operation) {
        $workers[] = new Process([PHP_BINARY, __DIR__.'/qr_worker.php', $database, $nonce, $directory, $workerId,
            $operation, base64_encode(json_encode(['intent' => $intent->id], JSON_THROW_ON_ERROR))], dirname(__DIR__, 2));
    }
    foreach ($workers as $worker) {
        $worker->setTimeout(120);
        $worker->start();
    }
    $deadline = microtime(true) + 90;
    while ((! is_file($directory.'/ready-a') || ! is_file($directory.'/ready-b')) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($directory.'/ready-a') || ! is_file($directory.'/ready-b')) {
        throw new RuntimeException('Los procesos de impresión no llegaron a la barrera.');
    }
    file_put_contents($directory.'/start', 'go');
    foreach ($workers as $worker) {
        $worker->wait();
        if (! $worker->isSuccessful()) {
            throw new RuntimeException('Falló un proceso de impresión: '.$worker->getErrorOutput());
        }
    }
    if (Payment::query()->where('order_id', $order->id)->count() !== 1
        || $intent->fresh()->status !== DynamicQrStatus::Paid
        || DB::table('cash_movements')->where('company_id', $company->id)->count() !== $cashBefore
        || DB::table('inventory_movements')->where('company_id', $company->id)->count() !== 0
        || DB::table('print_attempts')->where('order_id', $order->id)->where('purpose', PrinterPurpose::CustomerTicket->value)->where('is_reprint', false)->count() !== 1) {
        throw new RuntimeException('El pago o su impresión no fueron idempotentes: '.$case);
    }
}
echo "PASS: confirmación POS y reintento concurrentes: un pago, ningún movimiento de efectivo o inventario adicional y un ticket automático.\n";

$directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qr-race-'.$nonce.'-deposit';
if (file_exists($directory) || ! mkdir($directory, 0700)) {
    throw new RuntimeException('No se pudo crear la barrera de abono.');
}
$depositKey = (string) Str::ulid();
$args = base64_encode(json_encode(['intent' => $intent->id, 'user' => $user->id, 'key' => $depositKey, 'date' => now()->toDateString()], JSON_THROW_ON_ERROR));
$workers = [];
foreach (['a', 'b'] as $workerId) {
    $workers[] = new Process([PHP_BINARY, __DIR__.'/qr_worker.php', $database, $nonce, $directory, $workerId, 'deposit', $args], dirname(__DIR__, 2));
}
foreach ($workers as $worker) {
    $worker->setTimeout(120);
    $worker->start();
}
$deadline = microtime(true) + 90;
while ((! is_file($directory.'/ready-a') || ! is_file($directory.'/ready-b')) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($directory.'/ready-a') || ! is_file($directory.'/ready-b')) {
    throw new RuntimeException('Los procesos de abono no llegaron a la barrera.');
}
file_put_contents($directory.'/start', 'go');
foreach ($workers as $worker) {
    $worker->wait();
    if (! $worker->isSuccessful() || ! str_contains($worker->getOutput(), 'recorded')) {
        throw new RuntimeException('Falló un proceso de abono: '.$worker->getErrorOutput().' '.$worker->getOutput());
    }
}
if (DB::table('bank_qr_deposit_evidence')->where('request_key', $depositKey)->count() !== 1
    || Payment::query()->where('order_id', $order->id)->count() !== 1
    || DB::table('cash_movements')->where('company_id', $company->id)->count() !== $cashBefore
    || DB::table('print_attempts')->where('order_id', $order->id)->count() !== 1) {
    throw new RuntimeException('El abono concurrente duplicó evidencia o efectos financieros.');
}
echo "PASS: dos solicitudes concurrentes registraron una evidencia de abono sin duplicar efectos.\n";
$history = app(BankQrHistory::class);
$beforeReviews = $history->globalReviewCount($company, null, null);
$savedEvidence = DB::table('dynamic_qr_intents')->where('id', $intent->id)->value('reconciliation_data');
DB::table('dynamic_qr_intents')->where('id', $intent->id)->update(['reconciliation_data' => json_encode(['qrId' => 'invalid', 'amount' => '80.00', 'currency' => 'BOB'])]);
if ($history->globalReviewCount($company, null, null) !== $beforeReviews + 1) {
    throw new RuntimeException('La evidencia inválida no activó la alerta global InnoDB.');
}
BankQrDiagnostic::query()->create(['company_id' => $company->id, 'bank_qr_setting_id' => $setting->id,
    'user_id' => $user->id, 'amount' => '1.00', 'commercial_reference' => 'diagnostic-'.$nonce,
    'status' => 'failed', 'expires_at' => now()->addDay()]);
if ($history->globalReviewCount($company, null, null) !== $beforeReviews + 2) {
    throw new RuntimeException('El diagnóstico fallido no activó la alerta global InnoDB.');
}
DB::table('dynamic_qr_intents')->where('id', $intent->id)->update(['reconciliation_data' => $savedEvidence]);
echo "PASS: alerta global InnoDB incluye evidencia inválida y diagnóstico fallido.\n";

// Separate fresh intentions exercise failures on the same isolated InnoDB server.
$nextIntent = function (string $name) use ($company, $branch, $user, $session, $setting, $nonce): DynamicQrIntent {
    $order = Order::factory()->for($branch)->create(['company_id' => $company->id, 'created_by' => $user->id,
        'type' => OrderType::Takeaway, 'status' => OrderStatus::ReadyForPayment, 'subtotal' => '80.00', 'total' => '80.00']);
    OrderItem::factory()->for($order)->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'status' => OrderItemStatus::Served, 'line_total' => '80.00', 'unit_price' => '80.00', 'created_by' => $user->id]);

    return DynamicQrIntent::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'order_id' => $order->id, 'active_order_id' => $order->id, 'cash_session_id' => $session->id,
        'user_id' => $user->id, 'bank_qr_setting_id' => $setting->id, 'amount' => '80.00', 'currency' => 'BOB',
        'commercial_reference' => "$name-$nonce", 'bank_qr_id' => "$name-$nonce", 'idempotency_key' => "$name-$nonce",
        'status' => DynamicQrStatus::Pending, 'expires_at' => now()->addDay()]);
};
$bankError = false;
Http::fake(function ($request) use (&$bankError) {
    if (str_contains($request->url(), 'authentication/encrypt')) {
        return Http::response('"fake"');
    }
    if (str_contains($request->url(), 'authentication/authenticate')) {
        return Http::response(['responseCode' => 0, 'token' => 'fake']);
    }
    if (str_contains($request->url(), 'statusQR/')) {
        if ($bankError) {
            return Http::response(['responseCode' => 5]);
        }
        $qr = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));

        return Http::response(['responseCode' => 0, 'statusQrCode' => 1, 'payment' => ['qrId' => $qr, 'amount' => '80.00', 'currency' => 'BOB', 'transactionId' => 'simulated']]);
    }
    throw new RuntimeException('Solicitud bancaria no simulada.');
});
$logger = Log::getFacadeRoot();
config(['baneco.observations_enabled' => false]);
$absent = $nextIntent('absent');
DB::statement('RENAME TABLE bank_qr_observations TO bank_qr_observations_unavailable');
try {
    if (app(DynamicQrAction::class)->check($absent)->status !== DynamicQrStatus::Paid) {
        throw new RuntimeException('El historial desactivado bloqueó el POS.');
    }
} finally {
    DB::statement('RENAME TABLE bank_qr_observations_unavailable TO bank_qr_observations');
}
$logIntent = $nextIntent('log-paid');
Log::shouldReceive('info')->andThrow(new RuntimeException('logging unavailable'));
if (app(DynamicQrAction::class)->check($logIntent)->status !== DynamicQrStatus::Paid) {
    throw new RuntimeException('Un fallo de log bloqueó el pago válido.');
}
Log::swap($logger);
$errorIntent = $nextIntent('log-error');
$bankError = true;
Log::shouldReceive('warning')->andThrow(new RuntimeException('logging unavailable'));
$failed = app(DynamicQrAction::class)->check($errorIntent);
if ($failed->status !== DynamicQrStatus::Pending || $failed->issue_code !== 'bank_response_5'
    || Payment::query()->where('order_id', $errorIntent->order_id)->exists()) {
    throw new RuntimeException('El fallo de log ocultó el error bancario o creó un pago.');
}
Log::swap($logger);
$bankError = false;
config(['baneco.observations_enabled' => true]);
$writeIntent = $nextIntent('write-error');
DB::unprepared("CREATE TRIGGER qr_test_insert_failure BEFORE INSERT ON bank_qr_observations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated write failure'");
try {
    $failed = app(DynamicQrAction::class)->check($writeIntent);
    if ($failed->status !== DynamicQrStatus::Pending || Payment::query()->where('order_id', $writeIntent->order_id)->exists()) {
        throw new RuntimeException('La escritura fallida dejó un pago parcial.');
    }
} finally {
    DB::unprepared('DROP TRIGGER qr_test_insert_failure');
}
$encryptionIntent = $nextIntent('crypt-error');
$encrypter = Crypt::getFacadeRoot();
$mock = Mockery::mock($encrypter)->makePartial();
$mock->shouldReceive('encryptString')->andThrow(new RuntimeException('simulated encryption failure'));
Crypt::swap($mock);
try {
    $failed = app(DynamicQrAction::class)->check($encryptionIntent);
    if ($failed->status !== DynamicQrStatus::Pending || Payment::query()->where('order_id', $encryptionIntent->order_id)->exists()) {
        throw new RuntimeException('El cifrado fallido dejó un pago parcial.');
    }
} finally {
    Crypt::swap($encrypter);
}
echo "PASS: InnoDB: historial desactivado sin tabla, fallos de log, inserción y cifrado.\n";

$triggerNames = ['bank_qr_observations_no_update', 'bank_qr_observations_no_delete'];
$triggerCount = DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
    ->where('EVENT_OBJECT_TABLE', 'bank_qr_observations')->whereIn('TRIGGER_NAME', $triggerNames)->count();
if ($triggerCount !== 2 || strtolower((string) DB::table('information_schema.TABLES')
    ->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', 'bank_qr_observations')->value('ENGINE')) !== 'innodb') {
    throw new RuntimeException('Instalación incompleta del historial en InnoDB.');
}
$partialIntent = $nextIntent('partial-install');
DB::unprepared('DROP TRIGGER bank_qr_observations_no_delete');
try {
    $failed = app(DynamicQrAction::class)->check($partialIntent);
    if ($failed->status !== DynamicQrStatus::Pending || Payment::query()->where('order_id', $partialIntent->order_id)->exists()) {
        throw new RuntimeException('La instalación parcial permitió una confirmación financiera.');
    }
} finally {
    DB::unprepared("CREATE TRIGGER bank_qr_observations_no_delete BEFORE DELETE ON bank_qr_observations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las observaciones bancarias son inmutables'");
}
$partialIntent->refresh()->update(['last_checked_at' => null]);
$recovered = app(DynamicQrAction::class)->check($partialIntent->fresh());
$evidence = DB::table('bank_qr_observations')->where('dynamic_qr_intent_id', $partialIntent->id)->where('kind', 'observation')->first();
if ($recovered->status !== DynamicQrStatus::Paid || ! $evidence
    || Payment::query()->where('order_id', $partialIntent->order_id)->count() !== 1
    || str_contains($evidence->evidence, $partialIntent->bank_qr_id)
    || ! str_contains(Crypt::decryptString($evidence->evidence), $partialIntent->bank_qr_id)) {
    throw new RuntimeException('La recuperación del historial parcial no conservó pago y evidencia cifrada.');
}
$migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_18_200000_create_bank_qr_observations.php';
$refused = false;
try {
    $migration->down();
} catch (RuntimeException) {
    $refused = true;
}
if (! $refused || ! DB::table('bank_qr_observations')->where('id', $evidence->id)->exists()) {
    throw new RuntimeException('La reversión de migración no preservó la evidencia.');
}
config(['baneco.observations_enabled' => false]);
$rollbackIntent = $nextIntent('history-off');
if (app(DynamicQrAction::class)->check($rollbackIntent)->status !== DynamicQrStatus::Paid
    || ! DB::table('bank_qr_observations')->where('id', $evidence->id)->exists()) {
    throw new RuntimeException('La desactivación del historial no preservó la evidencia o bloqueó el POS.');
}
echo "PASS: instalación parcial, restauración de trigger, reintento cifrado, rechazo de rollback destructivo y desactivación conservando evidencia.\n";

if ($baseline = getenv('QR_BASELINE_ROOT')) {
    $baselineIntent = $nextIntent('version-rollback');
    $worker = new Process([PHP_BINARY, __DIR__.'/qr_baseline_rollback_worker.php', $baseline, $database, $nonce, (string) $baselineIntent->id],
        dirname(__DIR__, 2), ['APP_KEY' => config('app.key')]);
    $worker->setTimeout(120);
    $worker->run();
    if (! $worker->isSuccessful() || Payment::query()->where('order_id', $baselineIntent->order_id)->count() !== 1
        || ! DB::table('bank_qr_observations')->where('id', $evidence->id)->exists()
        || ! str_contains(Crypt::decryptString(DB::table('bank_qr_observations')->where('id', $evidence->id)->value('evidence')), $partialIntent->bank_qr_id)) {
        throw new RuntimeException('Falló la reversión aislada de aplicación: '.$worker->getErrorOutput());
    }
    echo "PASS: aplicación base 94e550d confirma un POS y conserva la evidencia del candidato.\n";
}
