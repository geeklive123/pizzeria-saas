<?php

namespace App\Enums;

enum PrintAttemptStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Printed = 'printed';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Claimed => 'Enviando',
            self::Printed, self::Succeeded => 'Impresa',
            self::Failed => 'Error',
        };
    }

    public function isPrinted(): bool
    {
        return in_array($this, [self::Printed, self::Succeeded], true);
    }
}
