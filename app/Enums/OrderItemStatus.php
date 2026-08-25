<?php

namespace App\Enums;

enum OrderItemStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Cancelled = 'cancelled';
}
