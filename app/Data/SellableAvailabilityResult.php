<?php

namespace App\Data;

class SellableAvailabilityResult
{
    /** @param list<array{ingredient:string, available:string, required:string, shortage_for_next:string}> $limitingIngredients */
    public function __construct(
        public readonly string $availableQuantity,
        public readonly string $mode,
        public readonly array $limitingIngredients = [],
    ) {}
}
