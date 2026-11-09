<?php

namespace App\Services;

use App\Exceptions\FinancialVersionConflict;
use App\Models\Order;

class OrderFinancialVersion
{
    public function assertCurrent(Order $locked, mixed $expected): void
    {
        if (! is_int($expected) || $expected < 0 || $locked->financial_version !== $expected) {
            throw new FinancialVersionConflict;
        }
    }

    public function advance(Order $locked): void
    {
        $locked->increment('financial_version');
    }
}
