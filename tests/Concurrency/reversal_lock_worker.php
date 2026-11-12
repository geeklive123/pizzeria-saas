<?php

use App\Models\Order;
use Illuminate\Support\Facades\DB;

[$script, $testDatabase, $nonce, $orderId, $marker] = $argv;
if (! preg_match('/^[a-f0-9]{12}$/D', $nonce) || ! ctype_digit($orderId)
    || basename($marker) !== 'reversal-lock-'.$nonce) {
    throw new RuntimeException('Parámetros de bloqueo inválidos.');
}
require __DIR__.'/qr_bootstrap.php';
useQrTestDatabase($testDatabase, $nonce);
DB::transaction(function () use ($orderId, $marker): void {
    Order::query()->whereKey((int) $orderId)->lockForUpdate()->firstOrFail();
    file_put_contents($marker, 'locked');
    usleep(2000000);
});
