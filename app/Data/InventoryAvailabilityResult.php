<?php

namespace App\Data;

class InventoryAvailabilityResult
{
    public function __construct(
        public readonly string $physicalQuantity,
        public readonly string $expiredQuantity,
        public readonly string $reservedQuantity,
        public readonly string $availableQuantity,
    ) {}
}
