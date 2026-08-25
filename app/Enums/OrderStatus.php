<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Open = 'open';
    case ReadyForPayment = 'ready_for_payment';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
