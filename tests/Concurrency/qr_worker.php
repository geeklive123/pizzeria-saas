<?php

use App\Actions\ClaimPreparedDynamicQrAction;
use App\Actions\DynamicQrAction;
use App\Actions\RegisterPaymentAction;
use App\Enums\PaymentMethod;
use App\Jobs\ReconcileDynamicQr;
use App\Models\CashSession;
use App\Models\DynamicQrIntent;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ClaimCommitFaultPdo extends PDO
{
    public int $commits = 0;

    public string $fault = '';

    public function commit(): bool
    {
        if (++$this->commits === 2 && $this->fault !== '') {
            if ($this->fault === 'after') {
                parent::commit();
            } else {
                parent::rollBack();
            }
            throw new PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away (injected)');
        }

        return parent::commit();
    }
}

[$script, $database, $nonce, $directory, $workerId, $operation, $payload] = $argv;
$testDatabase = $database;
require __DIR__.'/qr_bootstrap.php';
useQrTestDatabase($database, $nonce);
$args = json_decode(base64_decode($payload, true), true, 512, JSON_THROW_ON_ERROR);
if (! preg_match('/^[a-z0-9_-]+$/D', $workerId) || ! is_dir($directory) || ! preg_match('/^qr-race-'.preg_quote($nonce, '/').'-[a-z0-9_]+$/D', basename($directory))) {
    throw new RuntimeException('Barrera de prueba inválida.');
}
Http::fake(function ($request) use ($directory) {
    $url = $request->url();
    if (str_contains($url, '/encrypt')) {
        return Http::response('"fake"');
    }
    if (str_contains($url, '/authenticate')) {
        return Http::response(['responseCode' => 0, 'token' => 'fake']);
    }
    if (str_contains($url, '/generateQR')) {
        $intent = DynamicQrIntent::query()->where('commercial_reference', $request['transactionId'])->firstOrFail();
        if (DB::transactionLevel() !== 0 || $intent->generation_phase !== 'send_armed') {
            throw new RuntimeException('La solicitud bancaria comenzó sin autorización confirmada.');
        }
        file_put_contents($directory.'/bank_calls', "generate\n", FILE_APPEND | LOCK_EX);
        usleep(600000);

        return Http::response(['responseCode' => 0, 'qrId' => 'test-'.substr($request['transactionId'], -12), 'qrImage' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=']);
    }
    if (str_contains($url, '/statusQR/')) {
        $qr = rawurldecode(basename(parse_url($url, PHP_URL_PATH)));
        $intent = DynamicQrIntent::query()->where('bank_qr_id', $qr)->firstOrFail();
        file_put_contents($directory.'/bank_calls', "status\n", FILE_APPEND | LOCK_EX);
        usleep(600000);

        return Http::response(['responseCode' => 0, 'statusQrCode' => 1, 'payment' => ['qrId' => $qr, 'amount' => $intent->amount, 'currency' => 'BOB', 'transactionId' => 'test-'.$intent->id]]);
    }
    if (str_contains($url, '/cancelQR')) {
        file_put_contents($directory.'/bank_calls', "cancel\n", FILE_APPEND | LOCK_EX);

        return Http::response(['responseCode' => 0]);
    }
    throw new RuntimeException('Solicitud HTTP no prevista.');
});
file_put_contents($directory.'/ready-'.$workerId, 'ready');
$deadline = microtime(true) + 30;
while (! is_file($directory.'/start') && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($directory.'/start')) {
    throw new RuntimeException('Tiempo de espera de barrera agotado.');
}
$start = microtime(true);
try {
    if ($operation === 'claim_commit_fault') {
        $cfg = config('database.connections.mysql');
        $pdo = new ClaimCommitFaultPdo('mysql:host='.$cfg['host'].';port='.(int) $cfg['port'].';dbname='.$database.';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        DB::connection()->setPdo($pdo);
        $pdo->fault = $args['fault'];
        try {
            app(DynamicQrAction::class)->generate(Order::findOrFail($args['order']), CashSession::findOrFail($args['session']), User::findOrFail($args['user']), $args['key']);
            throw new RuntimeException('No se inyectó la excepción esperada.');
        } catch (PDOException $e) {
            $value = ['fault_observed' => true, 'commits' => $pdo->commits];
        }
    } elseif ($operation === 'claim_prepared') {
        $claimed = app(ClaimPreparedDynamicQrAction::class)->execute(DynamicQrIntent::findOrFail($args['intent']));
        usleep(600000);
        $value = ['claimed' => $claimed];
    } elseif ($operation === 'generate' || $operation === 'generate_mixed') {
        $dispatch = isset($args['dispatch']) ? KitchenDispatch::findOrFail($args['dispatch']) : null;
        $result = app(DynamicQrAction::class)->generate(Order::findOrFail($args['order']), CashSession::findOrFail($args['session']), User::findOrFail($args['user']), $args['key'], dispatch: $dispatch, cash: $operation === 'generate_mixed' ? '30.00' : '0.00', received: $operation === 'generate_mixed' ? '40.00' : null);
        $value = ['id' => $result->id, 'status' => $result->status->value];
    } elseif ($operation === 'cash') {
        app(RegisterPaymentAction::class)->execute(Order::findOrFail($args['order']), CashSession::findOrFail($args['session']), PaymentMethod::Cash, '80.00', User::findOrFail($args['user']), 'race-cash');
        $value = ['cash' => 'accepted'];
    } elseif ($operation === 'check' || $operation === 'cancel') {
        $result = app(DynamicQrAction::class)->check(DynamicQrIntent::findOrFail($args['intent']), $operation === 'cancel');
        $value = ['id' => $result->id, 'status' => $result->status->value];
    } elseif ($operation === 'job') {
        (new ReconcileDynamicQr($args['intent']))->handle(app(DynamicQrAction::class));
        $value = ['job' => 'finished'];
    } elseif ($operation === 'scheduler') {
        Artisan::call('baneco:reconcile', ['--inline' => true]);
        $value = ['scheduler' => 'finished'];
    } elseif ($operation === 'reconnect') {
        DB::disconnect('mysql');
        $result = app(DynamicQrAction::class)->check(DynamicQrIntent::findOrFail($args['intent']));
        $value = ['status' => $result->status->value];
    } elseif ($operation === 'idle') {
        usleep(1200000);
        $value = ['idle' => true];
    } elseif ($operation === 'deadlock') {
        $attempts = 0;
        DB::transaction(function () use ($args, $directory, $workerId, &$attempts): void {
            $attempts++;
            Order::query()->whereKey($args['first'])->lockForUpdate()->firstOrFail();
            if ($attempts === 1) {
                file_put_contents($directory.'/deadlock-'.$workerId, 'locked');
                $limit = microtime(true) + 15;
                while ((! is_file($directory.'/deadlock-a') || ! is_file($directory.'/deadlock-b')) && microtime(true) < $limit) {
                    usleep(10000);
                }
                if (! is_file($directory.'/deadlock-a') || ! is_file($directory.'/deadlock-b')) {
                    throw new RuntimeException('Barrera de deadlock agotada.');
                }
            }
            Order::query()->whereKey($args['second'])->lockForUpdate()->firstOrFail();
        }, 3);
        $value = ['attempts' => $attempts];
    } elseif ($operation === 'deadlock_cash') {
        $attempts = 0;
        $payment = DB::transaction(function () use ($args, $directory, $workerId, &$attempts) {
            $attempts++;
            if ($workerId === 'a') {
                Order::query()->whereKey($args['order'])->lockForUpdate()->firstOrFail();
            } else {
                CashSession::query()->whereKey($args['session'])->lockForUpdate()->firstOrFail();
            }
            if ($attempts === 1) {
                file_put_contents($directory.'/cashlock-'.$workerId, 'locked');
                $limit = microtime(true) + 15;
                while ((! is_file($directory.'/cashlock-a') || ! is_file($directory.'/cashlock-b')) && microtime(true) < $limit) {
                    usleep(10000);
                }
                if (! is_file($directory.'/cashlock-a') || ! is_file($directory.'/cashlock-b')) {
                    throw new RuntimeException('Barrera de cobro agotada.');
                }
            }

            return app(RegisterPaymentAction::class)->execute(Order::findOrFail($args['order']), CashSession::findOrFail($args['session']), PaymentMethod::Cash, '80.00', User::findOrFail($args['user']), 'deadlock-payment', '80.00');
        }, 3);
        $value = ['attempts' => $attempts, 'payment_id' => $payment->id];
    } else {
        throw new RuntimeException('Operación desconocida.');
    }
} catch (DomainException $exception) {
    $value = ['rejected' => true, 'reason' => $exception->getMessage()];
}
$end = microtime(true);
echo json_encode(['worker' => $workerId, 'operation' => $operation, 'started' => $start, 'ended' => $end, 'duration_ms' => (int) (($end - $start) * 1000), 'result' => $value], JSON_THROW_ON_ERROR), PHP_EOL;
