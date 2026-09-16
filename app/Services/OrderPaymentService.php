<?php

namespace App\Services;

use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\KitchenDispatch;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class OrderPaymentService
{
    public function paid(Order $order): string
    {
        $paid = BigDecimal::zero();
        foreach ($order->payments()->where('status', PaymentStatus::Completed->value)->pluck('amount') as $amount) {
            $paid = $paid->plus((string) $amount);
        }

        return (string) $paid->toScale(2, RoundingMode::HalfUp);
    }

    public function balance(Order $order): string
    {
        if ($order->status === OrderStatus::Cancelled) {
            return '0.00';
        }

        $balance = BigDecimal::of($order->total)->minus($this->paid($order));

        return (string) ($balance->isNegative() ? BigDecimal::zero() : $balance)->toScale(2, RoundingMode::HalfUp);
    }

    public function dispatchPaid(KitchenDispatch $dispatch): string
    {
        $paid = BigDecimal::zero();
        foreach ($dispatch->payments()->where('status', PaymentStatus::Completed->value)->pluck('amount') as $amount) {
            $paid = $paid->plus((string) $amount);
        }

        return (string) $paid->toScale(2, RoundingMode::HalfUp);
    }

    public function dispatchBalance(KitchenDispatch $dispatch): string
    {
        if ($dispatch->status === KitchenDispatchStatus::Cancelled) {
            return '0.00';
        }

        $balance = BigDecimal::of($dispatch->total)->minus($this->dispatchPaid($dispatch));

        return (string) ($balance->isNegative() ? BigDecimal::zero() : $balance)->toScale(2, RoundingMode::HalfUp);
    }
}
