<?php

namespace App\Enums;

enum InternalConsumptionStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return $this === self::Confirmed ? 'Confirmado' : 'Anulado';
    }
}
