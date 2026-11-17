<?php

use App\Actions\DynamicQrAction;
use App\Models\DynamicQrIntent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/build/') && is_file(dirname(__DIR__, 2).'/public'.$path)) {
    return false;
}
$app = require __DIR__.'/online-demo-bootstrap.php';
$dir = realpath(getenv('ONLINE_QR_DEMO_DIR'));
$info = json_decode(file_get_contents($dir.'/info.json'), true, flags: JSON_THROW_ON_ERROR);
// Test-only switch, stored inside this disposable demo directory.
if (is_file($dir.'/stop-online-emissions')) {
    config(['storefront.online_dynamic_qr_enabled' => false, 'storefront.delivery_payment_preparation_enabled' => false]);
}
if (in_array($path, ['/__demo/paid', '/__demo/state'], true)) {
    if (! hash_equals($info['control_token'], $_SERVER['HTTP_X_DEMO_TOKEN'] ?? '')) {
        http_response_code(403);
        exit;
    }
    header('Content-Type: application/json');
    if ($path === '/__demo/paid' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        DB::table('demo_bank_state')->where('id', 1)->update(['status' => 'paid']);
        echo '{"ok":true}';
    } elseif ($path === '/__demo/state' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $intent = DB::table('dynamic_qr_intents')->first(['id', 'bank_qr_id', 'status', 'amount', 'payment_id', 'qr_image']);
        if ($intent) {
            $intent->has_image = (bool) $intent->qr_image;
            unset($intent->qr_image);
        }
        echo json_encode(['request' => DB::table('online_order_requests')->first(['id', 'status', 'pos_order_id']),
            'intent' => $intent,
            'orders' => DB::table('orders')->count(), 'intents' => DB::table('dynamic_qr_intents')->count(), 'payments' => DB::table('payments')->count(),
            'reservations' => DB::table('inventory_reservations')->get(['status']),
            'consumptions' => DB::table('inventory_movements')->where('type', 'order_consumption')->count(),
            'prints' => DB::table('print_attempts')->count()], JSON_THROW_ON_ERROR);
    } else {
        http_response_code(405);
    }
    exit;
}
Http::allowStrayRequests([]);
Http::preventStrayRequests();
Http::fake(function ($request) {
    $url = $request->url();
    if (str_contains($url, 'authentication/encrypt')) {
        return Http::response('"encrypted"');
    }
    if (str_contains($url, 'authentication/authenticate')) {
        return Http::response(['responseCode' => 0, 'token' => 'demo-token']);
    }
    if (str_contains($url, 'generateQR')) {
        $im = imagecreatetruecolor(260, 260);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 15, 15, 15);
        imagefill($im, 0, 0, $white);
        imagerectangle($im, 8, 8, 251, 251, $black);
        imagestring($im, 5, 35, 95, 'QR DE PRUEBA', $black);
        imagestring($im, 5, 55, 125, 'NO PAGABLE', $black);
        imagestring($im, 3, 52, 155, 'SIMULADOR LOCAL', $black);
        ob_start();
        imagepng($im);
        $image = base64_encode(ob_get_clean());
        imagedestroy($im);

        return Http::response(['responseCode' => 0, 'qrId' => 'demo-'.bin2hex(random_bytes(8)), 'qrImage' => $image]);
    }
    if (str_contains($url, 'statusQR')) {
        $id = rawurldecode(basename(parse_url($url, PHP_URL_PATH)));
        $intent = DB::table('dynamic_qr_intents')->where('bank_qr_id', $id)->first();
        if (! $intent) {
            throw new RuntimeException('Unknown fake intent');
        }
        $paid = DB::table('demo_bank_state')->where('id', 1)->value('status') === 'paid';

        return Http::response(['responseCode' => 0, 'statusQrCode' => $paid ? 1 : 0,
            'payment' => ['qrId' => $id, 'amount' => (string) $intent->amount, 'currency' => 'BOB', 'transactionId' => 'demo-payment-1']]);
    }
    throw new RuntimeException('Unexpected outbound request blocked');
});
if ($path === '/__demo/reconcile') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! hash_equals($info['control_token'], $_SERVER['HTTP_X_DEMO_TOKEN'] ?? '')) {
        http_response_code(403);
        exit;
    }
    app(DynamicQrAction::class)->check(DynamicQrIntent::query()->sole());
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;
}
$app->handleRequest(Request::capture());
