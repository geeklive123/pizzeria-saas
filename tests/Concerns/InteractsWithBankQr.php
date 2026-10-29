<?php

namespace Tests\Concerns;

use App\Actions\DynamicQrAction;
use App\Enums\DynamicQrStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\BankQrSetting;
use App\Models\CashSession;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait InteractsWithBankQr
{
    private bool $qrFakeRegistered = false;

    private string $simulatedQrId = '';

    private ?string $simulatedQrAmount = null;

    /** Historical data only: this deliberately does not settle an order or dispatch. */
    protected function historicalQrPayment(Order $order, CashSession $session, User $user, string $amount, string $key, ?KitchenDispatch $dispatch = null, ?string $reference = null): Payment
    {
        return Payment::query()->create([
            'company_id' => $order->company_id, 'branch_id' => $order->branch_id,
            'order_id' => $order->id, 'cash_session_id' => $session->id,
            'kitchen_dispatch_id' => $dispatch?->id, 'received_by' => $user->id,
            'method' => PaymentMethod::Qr, 'status' => PaymentStatus::Completed,
            'amount' => $amount, 'paid_at' => now(), 'idempotency_key' => $key,
            'reference' => $reference,
        ]);
    }

    /** Exercise generation and confirmation without allowing network traffic. */
    protected function confirmBankQr(Order $order, CashSession $session, User $user, string $key, ?KitchenDispatch $dispatch = null, string $cash = '0.00'): Payment
    {
        config(['baneco.enabled' => true]);
        Http::preventStrayRequests();
        if (! BankQrSetting::forOrder($order)) {
            BankQrSetting::query()->create([
                'company_id' => $order->company_id, 'active_scope' => $order->company_id.':company',
                'credentials' => ['username' => 'fixture', 'password' => 'fixture', 'aes_key' => 'fixture', 'account_credit' => 'fixture'],
            ]);
        }
        $this->simulatedQrId = 'fixture-'.Str::lower((string) Str::ulid());
        $this->simulatedQrAmount = null;
        if (! $this->qrFakeRegistered) {
            Http::fake(function ($request) {
                if (str_contains($request->url(), '/encrypt')) {
                    return Http::response('encrypted');
                }
                if (str_contains($request->url(), '/authenticate')) {
                    return Http::response(['responseCode' => 0, 'token' => 'fixture-token']);
                }
                if (str_contains($request->url(), '/generateQR')) {
                    preg_match('/"amount":(\d+(?:\.\d{1,2})?)/', $request->body(), $matches);
                    $this->simulatedQrAmount = $matches[1] ?? null;

                    return Http::response(['responseCode' => 0, 'qrId' => $this->simulatedQrId, 'qrImage' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF/8AAAAASUVORK5CYII=']);
                }
                if (str_contains($request->url(), '/statusQR/')) {
                    return Http::response(['responseCode' => 0, 'statusQrCode' => 1, 'payment' => ['qrId' => $this->simulatedQrId, 'currency' => 'BOB', 'amount' => $this->simulatedQrAmount, 'transactionId' => 'fixture-payment']]);
                }
                throw new \LogicException('Unexpected simulated bank request.');
            });
            $this->qrFakeRegistered = true;
        }
        $intent = app(DynamicQrAction::class)->generate($order, $session, $user, $key, $dispatch, $cash);
        $intent = app(DynamicQrAction::class)->check($intent);
        if ($intent->status !== DynamicQrStatus::Paid || ! $intent->payment_id) {
            throw new \LogicException('Simulated bank confirmation did not produce a payment: '.$intent->issue_code);
        }

        return Payment::query()->findOrFail($intent->payment_id);
    }
}
