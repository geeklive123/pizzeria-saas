<?php

namespace App\Enums;

enum LogoutReason: string
{
    case Manual = 'manual';
    case CashClosed = 'cash_closed';
    case Timeout = 'timeout';
    case AdminForced = 'admin_forced';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::CashClosed => 'Cierre de caja',
            self::Timeout => 'Tiempo de espera',
            self::AdminForced => 'Cierre administrativo',
        };
    }
}
