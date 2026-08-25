<?php

namespace App\Services;

use App\Enums\OrderItemStatus;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class OrderTotalsService
{
    public function recalculate(Order $order): Order
    {
        $subtotal = $order->items()->where('status', '!=', OrderItemStatus::Cancelled->value)->get()
            ->reduce(fn (BigDecimal $total, $item): BigDecimal => $total->plus($item->line_total), BigDecimal::zero())
            ->toScale(2, RoundingMode::HalfUp);

        $order->forceFill([
            'subtotal' => (string) $subtotal,
            'discount_total' => '0.00',
            'total' => (string) $subtotal,
        ])->save();

        return $order->refresh();
    }
}
