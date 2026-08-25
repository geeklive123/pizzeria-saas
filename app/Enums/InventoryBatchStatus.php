<?php

namespace App\Enums;

enum InventoryBatchStatus: string
{
    case Expired = 'expired';
    case ExpiringSoon = 'expiring_soon';
    case Ok = 'ok';
    case NoExpiration = 'no_expiration';
}
