<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileDynamicQr;
use App\Models\DynamicQrIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BancoEconomicoWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(config('baneco.webhook_enabled'), 404);
        abort_if(strlen($request->getContent()) > 8192, 413);
        $data = $request->validate(['payment' => ['required', 'array'], 'payment.qrId' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9_-]+$/']]);
        $intent = DynamicQrIntent::query()->where('bank_qr_id', $data['payment']['qrId'])->first();
        if ($intent) {
            ReconcileDynamicQr::dispatch($intent->id);
        }

        // Unauthenticated signal only. Do not reveal existence or trust any payment fields.
        return response()->json(['responseCode' => 0, 'message' => '']);
    }
}
