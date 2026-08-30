<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\CashSession;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrderPaymentService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class RegisterMixedPaymentAction
{
    public function __construct(
        private readonly RegisterPaymentAction $payments,
        private readonly OrderPaymentService $balances,
    ) {}

    public function execute(
        Order $order,
        CashSession $session,
        int|string $cashAmount,
        User $user,
        string $idempotencyKey,
        ?string $receivedAmount = null,
        ?string $reference = null,
        ?KitchenDispatch $dispatch = null,
    ): Payment {
        return DB::transaction(function () use ($order, $session, $cashAmount, $user, $idempotencyKey, $receivedAmount, $reference, $dispatch): Payment {
            $order = Order::query()->with('company')->lockForUpdate()->findOrFail($order->id);
            $dispatch = $dispatch ? KitchenDispatch::query()->lockForUpdate()->findOrFail($dispatch->id) : null;
            $cashAmount = BigDecimal::of($cashAmount)->toScale(2, RoundingMode::HalfUp);
            $cashKey = hash('sha256', $idempotencyKey.':cash');
            $qrKey = hash('sha256', $idempotencyKey.':qr');
            $existing = Payment::query()->forCompany($order->company_id)
                ->whereIn('idempotency_key', [$cashKey, $qrKey])->get()->keyBy('idempotency_key');

            if ($existing->isNotEmpty()) {
                $cash = $existing->get($cashKey);
                if (($cashAmount->isZero() && $cash)
                    || (! $cashAmount->isZero() && (! $cash || ! BigDecimal::of($cash->amount)->isEqualTo($cashAmount)))) {
                    throw new DomainException('La operación de pago ya fue utilizada con datos diferentes.');
                }
                foreach ($existing as $payment) {
                    if ((int) $payment->order_id !== (int) $order->id
                        || (int) ($payment->kitchen_dispatch_id ?? 0) !== (int) ($dispatch?->id ?? 0)) {
                        throw new DomainException('La operación de pago ya fue utilizada con datos diferentes.');
                    }
                }
                $balance = $dispatch ? $this->balances->dispatchBalance($dispatch) : $this->balances->balance($order);
                if ($balance !== '0.00') {
                    throw new DomainException('El pago mixto anterior no quedó completo.');
                }

                return $existing->sortByDesc('id')->first();
            }

            $balance = BigDecimal::of($dispatch ? $this->balances->dispatchBalance($dispatch) : $this->balances->balance($order))
                ->toScale(2, RoundingMode::HalfUp);
            if ($balance->isZero()) {
                throw new DomainException('La tanda ya no tiene saldo pendiente.');
            }
            if ($cashAmount->isNegative() || $cashAmount->isGreaterThan($balance)) {
                throw new DomainException('El efectivo debe estar entre cero y el saldo pendiente.');
            }

            $received = BigDecimal::of($receivedAmount ?? (string) $cashAmount)->toScale(2, RoundingMode::HalfUp);
            if ($received->isLessThan($cashAmount)) {
                throw new DomainException('El efectivo recibido no puede ser menor que el efectivo aplicado.');
            }

            $payment = null;
            if (! $cashAmount->isZero()) {
                $payment = $this->payments->execute(
                    $order,
                    $session,
                    PaymentMethod::Cash,
                    (string) $cashAmount,
                    $user,
                    $cashKey,
                    (string) $received,
                    dispatch: $dispatch,
                );
            }

            $qrAmount = $balance->minus($cashAmount)->toScale(2, RoundingMode::HalfUp);
            if (! $qrAmount->isZero()) {
                $payment = $this->payments->execute(
                    $order->refresh(),
                    $session,
                    PaymentMethod::Qr,
                    (string) $qrAmount,
                    $user,
                    $qrKey,
                    reference: $reference,
                    dispatch: $dispatch?->refresh(),
                );
            }

            return $payment ?? throw new DomainException('El pago mixto no contiene importes válidos.');
        }, 3);
    }
}
