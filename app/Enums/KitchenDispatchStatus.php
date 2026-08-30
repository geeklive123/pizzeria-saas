<?php

namespace App\Enums;

enum KitchenDispatchStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Released = 'released';
    case Settled = 'settled';
    case Cancelled = 'cancelled';
}
