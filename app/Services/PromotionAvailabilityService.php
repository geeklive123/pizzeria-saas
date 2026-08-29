<?php

namespace App\Services;

use App\Data\SellableAvailabilityResult;
use App\Enums\InventoryReservationStatus;
use App\Models\Branch;
use App\Models\Promotion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class PromotionAvailabilityService
{
    public function __construct(private readonly InventoryAvailabilityService $availability) {}

    public function calculate(Promotion $promotion, Branch $branch): SellableAvailabilityResult
    {
        if ((int) $promotion->company_id !== (int) $branch->company_id) {
            throw new DomainException('La disponibilidad de promoción no puede mezclar empresas.');
        }

        $promotion->loadMissing([
            'components.inventoryItem.unit',
            'components.inventoryItem.inventoryStocks' => fn ($query) => $query->where('branch_id', $branch->getKey()),
            'components.inventoryItem.inventoryBatches' => fn ($query) => $query->where('branch_id', $branch->getKey()),
            'components.inventoryItem.inventoryReservations' => fn ($query) => $query
                ->where('branch_id', $branch->getKey())
                ->where('status', InventoryReservationStatus::Reserved->value),
        ]);

        if ($promotion->components->isEmpty()) {
            return new SellableAvailabilityResult('0.000', 'promotion');
        }

        $availablePromotions = null;
        $details = [];
        foreach ($promotion->components as $component) {
            $inventoryItem = $component->inventoryItem;
            $available = $this->availability->forStock(
                $inventoryItem->inventoryStocks->first(),
                $inventoryItem->inventoryBatches,
                $this->availability->reservedQuantity($inventoryItem->inventoryReservations),
            )->availableQuantity;
            $producible = BigDecimal::of($available)->dividedBy($component->quantity, 0, RoundingMode::Down);
            $availablePromotions = $availablePromotions === null || $producible->isLessThan($availablePromotions)
                ? $producible
                : $availablePromotions;
            $details[] = [
                'ingredient' => $inventoryItem->name,
                'available' => (string) BigDecimal::of($available)->toScale(3, RoundingMode::HalfUp),
                'required' => $component->quantity,
                'shortage_for_next' => $producible->isZero()
                    ? (string) BigDecimal::of($component->quantity)->minus($available)->toScale(3, RoundingMode::HalfUp)
                    : '0.000',
            ];
        }

        return new SellableAvailabilityResult(
            (string) ($availablePromotions ?? BigDecimal::zero())->toScale(3, RoundingMode::HalfUp),
            'promotion',
            $details,
        );
    }
}
