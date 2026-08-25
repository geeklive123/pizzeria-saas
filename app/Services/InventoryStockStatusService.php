<?php

namespace App\Services;

use App\Enums\InventoryStockStatus;
use Brick\Math\BigDecimal;

class InventoryStockStatusService
{
    public function __construct(private readonly InventoryAvailabilityService $availability) {}

    public function availableQuantity(
        int|string $physicalQuantity,
        int|string $reservedQuantity = '0',
        int|string $expiredQuantity = '0',
    ): string {
        return $this->availability
            ->calculate($physicalQuantity, $expiredQuantity, $reservedQuantity)
            ->availableQuantity;
    }

    public function status(
        int|string $physicalQuantity,
        int|string|null $minimumQuantity,
        int|string $reservedQuantity = '0',
        int|string $expiredQuantity = '0',
    ): InventoryStockStatus {
        $available = BigDecimal::of($this->availableQuantity(
            $physicalQuantity,
            $reservedQuantity,
            $expiredQuantity,
        ));

        if ($available->isLessThanOrEqualTo(0)) {
            return InventoryStockStatus::Out;
        }

        if ($minimumQuantity !== null && $available->isLessThanOrEqualTo($minimumQuantity)) {
            return InventoryStockStatus::Low;
        }

        return InventoryStockStatus::Normal;
    }
}
