<?php

namespace App\Services;

use App\Models\OnlineOrderRequest;
use App\Models\Order;
use DomainException;

class OnlineOrderCommercialGuard
{
    public function assertEditable(Order $order): void
    {
        if (OnlineOrderRequest::query()->where('pos_order_id', $order->id)
            ->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->exists()) {
            throw new DomainException('Este pedido proviene de una solicitud online. No se puede modificar su importe acordado.');
        }
    }
}
