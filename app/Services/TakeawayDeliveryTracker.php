<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\TakeawayDelivery;

class TakeawayDeliveryTracker
{
    public function start(Order $order): void
    {
        if ($order->type === OrderType::Takeaway) {
            TakeawayDelivery::query()->firstOrCreate(['order_id' => $order->id], [
                'company_id' => $order->company_id, 'branch_id' => $order->branch_id,
            ]);
        }
    }
}
