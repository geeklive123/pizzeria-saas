<?php

namespace App\Services;

use App\Models\Order;

class OrderTotalsService
{
    public function __construct(private readonly OrderFinancialService $financials) {}

    public function recalculate(Order $order): Order
    {
        return $this->financials->recalculateOrder($order);
    }
}
