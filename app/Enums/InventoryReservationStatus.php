<?php

namespace App\Enums;

enum InventoryReservationStatus: string
{
    case Reserved = 'reserved';
    case Released = 'released';
    case Consumed = 'consumed';
}
