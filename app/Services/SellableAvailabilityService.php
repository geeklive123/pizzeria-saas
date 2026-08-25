<?php

namespace App\Services;

use App\Data\SellableAvailabilityResult;
use App\Enums\InventoryReservationStatus;
use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class SellableAvailabilityService
{
    public function __construct(
        private readonly RecipeAvailabilityService $recipes,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    public function calculate(ProductVariant $variant, Branch $branch): SellableAvailabilityResult
    {
        if ((int) $variant->company_id !== (int) $branch->company_id) {
            throw new DomainException('Sellable availability cannot mix companies.');
        }

        $variant->loadMissing([
            'product',
            'recipe',
            'inventoryItem.inventoryStocks',
            'inventoryItem.inventoryBatches',
            'inventoryItem.inventoryReservations' => fn ($query) => $query
                ->where('branch_id', $branch->getKey())
                ->where('status', InventoryReservationStatus::Reserved->value),
        ]);

        if ($variant->recipe?->is_active && $variant->recipe->items()->exists()) {
            $availability = $this->recipes->calculate($variant, $branch);

            return new SellableAvailabilityResult(
                (string) BigDecimal::of($availability->producibleQuantity)->toScale(3),
                'recipe',
                $availability->limitingIngredients,
            );
        }

        if ($variant->product->type === ProductType::Pizza && $variant->requires_preparation) {
            return new SellableAvailabilityResult('0.000', 'recipe_pending');
        }

        $stock = $variant->inventoryItem?->inventoryStocks
            ->firstWhere('branch_id', $branch->getKey());
        $batches = $variant->inventoryItem?->inventoryBatches
            ->where('branch_id', $branch->getKey());
        $reserved = $variant->inventoryItem
            ? $this->availability->reservedQuantity($variant->inventoryItem->inventoryReservations)
            : '0.000';
        $availability = $this->availability->forStock($stock, $batches, $reserved);

        return new SellableAvailabilityResult(
            (string) BigDecimal::of($availability->availableQuantity)->toScale(3, RoundingMode::HalfUp),
            'direct',
        );
    }
}
