<?php

namespace App\Enums;

enum OrderCancellationScope: string
{
    case PaidOrder = 'paid_order';
    case KitchenDispatch = 'kitchen_dispatch';
    case KitchenDispatchItem = 'kitchen_dispatch_item';
}
