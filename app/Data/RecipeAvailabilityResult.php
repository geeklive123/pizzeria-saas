<?php

namespace App\Data;

class RecipeAvailabilityResult
{
    /** @param list<array{ingredient:string, available:string, required:string, shortage_for_next:string}> $limitingIngredients */
    public function __construct(
        public readonly int $producibleQuantity,
        public readonly array $limitingIngredients,
    ) {}
}
