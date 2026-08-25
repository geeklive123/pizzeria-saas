<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Recipe;
use Brick\Math\BigDecimal;

class RecipeCostService
{
    public function estimate(Recipe $recipe, Branch $branch): ?string
    {
        $total = BigDecimal::zero();

        foreach ($recipe->items as $recipeItem) {
            $inventoryItem = $recipeItem->ingredient->inventoryItem;
            if (! $inventoryItem) {
                return null;
            }

            $stock = $inventoryItem->inventoryStocks->firstWhere('branch_id', $branch->getKey());
            if (! $stock) {
                return null;
            }

            $total = $total->plus(BigDecimal::of($recipeItem->quantity)->multipliedBy($stock->average_cost));
        }

        return (string) $total;
    }
}
