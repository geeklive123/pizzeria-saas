<?php

namespace App\Enums;

enum TableChargeMode: string
{
    case PerBatch = 'per_batch';
    case AtEnd = 'at_end';

    public function label(): string
    {
        return match ($this) {
            self::PerBatch => 'Cobrar cada tanda',
            self::AtEnd => 'Cobrar al final',
        };
    }
}
