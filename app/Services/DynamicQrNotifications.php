<?php

namespace App\Services;

use App\Enums\DynamicQrStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DynamicQrIntent;
use App\Models\DynamicQrNotification;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class DynamicQrNotifications
{
    public function watch(DynamicQrIntent $intent, User $user, \DateTimeInterface $startedAt): void
    {
        if ($intent->status === DynamicQrStatus::Paid && (! $intent->confirmed_at || $intent->confirmed_at->lt($startedAt))) {
            return;
        }

        DynamicQrNotification::query()->firstOrCreate(
            ['user_id' => $user->id, 'intent_id' => $intent->id],
            ['company_id' => $intent->company_id, 'branch_id' => $intent->branch_id],
        );
    }

    public function pending(Company $company, Branch $branch, User $user): array
    {
        $notices = DynamicQrNotification::query()->where('company_id', $company->id)
            ->where('branch_id', $branch->id)->where('user_id', $user->id)->whereNull('delivered_at')
            ->whereHas('intent', fn ($query) => $query->where('status', DynamicQrStatus::Paid)->where('bank_status', '1')
                ->whereNotNull('payment_id')->whereNotNull('confirmed_at')->whereColumn('confirmed_at', '<=', 'expires_at')
                ->whereHas('payment', fn ($payments) => $payments->where('status', PaymentStatus::Completed)->where('method', PaymentMethod::Qr)))
            ->with(['intent.order', 'intent.payment'])->orderBy('id')->get();

        $result = [];
        foreach ($notices as $notice) {
            $intent = $notice->intent;
            $payment = $intent->payment;
            if (! $payment || $payment->status !== PaymentStatus::Completed || $payment->method !== PaymentMethod::Qr
                || $intent->confirmed_at->gt($intent->expires_at) || ! Gate::forUser($user)->allows('view', $intent->order)) {
                continue;
            }
            $orderNumber = $intent->order->formattedOperationalNumber();
            if ($orderNumber === 'Sin comanda') {
                $orderNumber = 'Ref. interna #'.str_pad((string) $intent->order->order_number, 6, '0', STR_PAD_LEFT);
            }
            $result[] = [
                'id' => $intent->ulid,
                'order_number' => $orderNumber,
                'amount' => $payment->amount,
                'currency' => $intent->currency,
                'status' => 'Pago confirmado',
                'order_url' => route('orders.show', $intent->order->ulid),
            ];
        }

        return $result;
    }

    public function acknowledge(Company $company, Branch $branch, User $user, string $intentUlid): void
    {
        $notice = DynamicQrNotification::query()->where('company_id', $company->id)
            ->where('branch_id', $branch->id)->where('user_id', $user->id)
            ->whereHas('intent', fn ($query) => $query->where('ulid', $intentUlid))
            ->where(fn ($query) => $query->whereNotNull('delivered_at')->orWhereHas('intent', fn ($intent) => $intent
                ->where('status', DynamicQrStatus::Paid)->where('bank_status', '1')->whereNotNull('payment_id')
                ->whereNotNull('confirmed_at')->whereColumn('confirmed_at', '<=', 'expires_at')
                ->whereHas('payment', fn ($payments) => $payments->where('status', PaymentStatus::Completed)->where('method', PaymentMethod::Qr))))
            ->with('intent.order')->firstOrFail();

        Gate::forUser($user)->authorize('view', $notice->intent->order);
        DynamicQrNotification::query()->whereKey($notice->id)->whereNull('delivered_at')
            ->update(['delivered_at' => now()]);
    }
}
