<?php

namespace App\Services;

use App\Data\RecipeAvailabilityResult;
use App\Enums\InventoryReservationStatus;
use App\Models\Branch;
use App\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class RecipeAvailabilityService
{
    public function __construct(private readonly InventoryAvailabilityService $availability) {}

    public function calculate(ProductVariant $variant, Branch $branch): RecipeAvailabilityResult
    {
        if ((int) $variant->company_id !== (int) $branch->company_id) {
            throw new DomainException('Recipe availability cannot mix companies.');
        }

        $variant->loadMissing([
            'recipe.items.ingredient.inventoryItem.inventoryStocks' => fn ($query) => $query
                ->where('branch_id', $branch->getKey()),
            'recipe.items.ingredient.inventoryItem.inventoryBatches' => fn ($query) => $query
                ->where('branch_id', $branch->getKey())
                ->where('quantity_remaining', '>', 0),
            'recipe.items.ingredient.inventoryItem.inventoryReservations' => fn ($query) => $query
                ->where('branch_id', $branch->getKey())
                ->where('status', InventoryReservationStatus::Reserved->value),
        ]);
        $recipe = $variant->recipe;

        if (! $recipe || ! $recipe->is_active || $recipe->items->isEmpty()) {
            return new RecipeAvailabilityResult(0, []);
        }

        $capacities = [];
        foreach ($recipe->items as $recipeItem) {
            $inventoryItem = $recipeItem->ingredient->inventoryItem;
            $stock = $inventoryItem?->inventoryStocks->firstWhere('branch_id', $branch->getKey());
            $reserved = $inventoryItem
                ? $this->availability->reservedQuantity($inventoryItem->inventoryReservations)
                : '0.000';
            $quantities = $this->availability->forStock($stock, $inventoryItem?->inventoryBatches, $reserved);
            $available = BigDecimal::of($quantities->availableQuantity);
            $required = BigDecimal::of($recipeItem->quantity);
            $capacity = $available->dividedBy($required, 0, RoundingMode::Down)->toInt();
            $capacities[] = compact('recipeItem', 'available', 'required', 'capacity');
        }

        $producible = min(array_column($capacities, 'capacity'));
        $limiting = collect($capacities)
            ->where('capacity', $producible)
            ->map(function (array $entry) use ($producible): array {
                $neededForNext = $entry['required']->multipliedBy($producible + 1);
                $shortage = $neededForNext->minus($entry['available']);

                return [
                    'ingredient' => $entry['recipeItem']->ingredient->name,
                    'available' => (string) $entry['available']->toScale(3, RoundingMode::HalfUp),
                    'required' => (string) $entry['required']->toScale(3, RoundingMode::HalfUp),
                    'shortage_for_next' => (string) ($shortage->isNegative() ? BigDecimal::zero() : $shortage)
                        ->toScale(3, RoundingMode::HalfUp),
                ];
            })->values()->all();

        return new RecipeAvailabilityResult($producible, $limiting);
    }
}
