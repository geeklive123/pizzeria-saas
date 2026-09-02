<?php

namespace App\Services;

use App\Data\PreparationAvailabilityResult;
use App\Models\Branch;
use App\Models\InventoryStock;
use App\Models\Preparation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class PreparationAvailabilityService
{
    public function __construct(private readonly InventoryAvailabilityService $availability) {}

    public function calculate(Preparation $preparation, Branch $branch): PreparationAvailabilityResult
    {
        if ((int) $preparation->company_id !== (int) $branch->company_id) {
            throw new DomainException('La preparación y la sucursal deben pertenecer a la misma empresa.');
        }

        $preparation->loadMissing(['components.inventoryItem.unit']);
        $components = [];
        $maximum = null;

        foreach ($preparation->components->sortBy('inventory_item_id') as $component) {
            $stock = InventoryStock::query()
                ->where('company_id', $preparation->company_id)
                ->where('branch_id', $branch->getKey())
                ->where('inventory_item_id', $component->inventory_item_id)
                ->first();
            $available = $this->availability->forStock($stock)->availableQuantity;
            $possible = (int) (string) BigDecimal::of($available)
                ->dividedBy($component->quantity, 0, RoundingMode::Down);
            $maximum = $maximum === null ? $possible : min($maximum, $possible);
            $components[] = [
                'inventory_item_id' => (int) $component->inventory_item_id,
                'name' => $component->inventoryItem->name,
                'unit' => $component->inventoryItem->unit->symbol,
                'required_per_lot' => $component->quantity,
                'available' => $available,
                'possible_lots' => $possible,
            ];
        }

        $maximum ??= 0;
        $limiting = array_values(array_filter(
            $components,
            fn (array $component): bool => $component['possible_lots'] === $maximum,
        ));

        return new PreparationAvailabilityResult(
            $maximum,
            (string) BigDecimal::of($preparation->theoretical_yield)
                ->multipliedBy($maximum)->toScale(3, RoundingMode::HalfUp),
            $components,
            $limiting,
        );
    }

    /** @return list<array{name:string,missing:string,unit:string}> */
    public function shortages(PreparationAvailabilityResult $availability, int $lots): array
    {
        $shortages = [];

        foreach ($availability->components as $component) {
            $required = BigDecimal::of($component['required_per_lot'])->multipliedBy($lots);
            $missing = $required->minus($component['available']);

            if ($missing->isPositive()) {
                $shortages[] = [
                    'name' => $component['name'],
                    'missing' => (string) $missing->toScale(3, RoundingMode::HalfUp),
                    'unit' => $component['unit'],
                ];
            }
        }

        return $shortages;
    }
}
