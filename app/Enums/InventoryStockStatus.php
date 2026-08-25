<?php

namespace App\Enums;

enum InventoryStockStatus: string
{
    case Normal = 'normal';
    case Low = 'low';
    case Out = 'out';
}
