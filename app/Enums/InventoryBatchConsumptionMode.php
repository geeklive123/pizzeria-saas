<?php

namespace App\Enums;

enum InventoryBatchConsumptionMode: string
{
    case Usable = 'usable';
    case Expired = 'expired';
    case Any = 'any';
}
