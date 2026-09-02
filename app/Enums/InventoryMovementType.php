<?php

namespace App\Enums;

use DomainException;

enum InventoryMovementType: string
{
    case Opening = 'opening';
    case Purchase = 'purchase';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case Waste = 'waste';
    case ManualIn = 'manual_in';
    case ManualOut = 'manual_out';
    case OrderConsumption = 'order_consumption';
    case ProductionConsumption = 'production_consumption';
    case ProductionOutput = 'production_output';
    case Reversal = 'reversal';

    public function direction(): int
    {
        return match ($this) {
            self::Opening, self::Purchase, self::AdjustmentIn, self::ManualIn, self::ProductionOutput => 1,
            self::AdjustmentOut, self::Waste, self::ManualOut, self::OrderConsumption,
            self::ProductionConsumption => -1,
            self::Reversal => throw new DomainException('A reversal derives its direction from the original movement.'),
        };
    }
}
