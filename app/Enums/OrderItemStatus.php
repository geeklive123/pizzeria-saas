<?php

namespace App\Enums;

enum OrderItemStatus: string
{
    case Draft = 'draft';
    case PendingPayment = 'pending_payment';
    case Sent = 'sent';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Cancelled = 'cancelled';
}
