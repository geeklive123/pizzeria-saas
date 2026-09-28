<?php

namespace App\Services;

use App\Data\SellableAvailabilityResult;
use App\Enums\InventoryReservationStatus;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\ProductVariant;
use App\Models\Promotion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class PromotionAvailabilityService
{
    public function __construct(
        private readonly InventoryAvailabilityService $availability,
        private readonly PizzaCompositionService $composition,
    ) {}

    public function calculate(Promotion $promotion, Branch $branch, OrderType $fulfillment = OrderType::DineIn): SellableAvailabilityResult
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
            'selectionGroups.category',
            'company',
        ]);

        if ($promotion->components->isEmpty() && $promotion->selectionGroups->isEmpty()) {
            return new SellableAvailabilityResult('0.000', 'promotion');
        }

        if ($promotion->selectionGroups->isNotEmpty()) {
            return $this->withPizzaGroups($promotion, $branch, $fulfillment);
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

    /**
     * Availability for selectable pizzas is intentionally a feasibility check for one
     * promotion. It composes every eligible full-flavor pizza with the normal engine,
     * then uses a depth-first search with immediate stock pruning. This avoids building
     * the full cartesian product and still accounts for shared ingredients and packaging.
     */
    private function withPizzaGroups(Promotion $promotion, Branch $branch, OrderType $fulfillment): SellableAvailabilityResult
    {
        $available = [];
        $items = [];
        $required = [];
        foreach ($promotion->components as $component) {
            $id = $component->inventory_item_id;
            $items[$id] = $component->inventoryItem;
            $required[$id] = BigDecimal::of($component->quantity);
        }

        $slots = [];
        foreach ($promotion->selectionGroups as $group) {
            if (! $group->category->is_active) {
                return new SellableAvailabilityResult('0.000', 'promotion');
            }
            $candidates = ProductVariant::query()->forCompany($promotion->company)
                ->where('is_active', true)->whereHas('product', fn ($query) => $query
                ->where('is_active', true)->where('category_id', $group->category_id))
                ->with('product')->orderBy('id')->get()
                ->map(function ($variant) use ($promotion, $group, $fulfillment, &$items): ?array {
                    try {
                        $result = $this->composition->compose($promotion->company, [['product_variant' => $variant]], [], $fulfillment);
                    } catch (DomainException) {
                        return null;
                    }
                    if ($result['snapshot']['size_key'] !== $group->size_key
                        || ($result['snapshot']['inventory_status'] ?? null) !== 'configured') {
                        return null;
                    }
                    $requirements = [];
                    foreach ($result['requirements'] as $requirement) {
                        $id = $requirement['inventory_item']->getKey();
                        $items[$id] = $requirement['inventory_item'];
                        $requirements[$id] = BigDecimal::of($requirement['quantity']);
                    }

                    return ['variant_id' => $variant->getKey(), 'requirements' => $requirements];
                })->filter()->values()->all();
            if ($candidates === []) {
                return new SellableAvailabilityResult('0.000', 'promotion', [['ingredient' => $group->category->name, 'available' => '0.000', 'required' => (string) $group->quantity, 'shortage_for_next' => '1.000']]);
            }
            for ($position = 0; $position < $group->quantity; $position++) {
                $slots[] = ['group' => $group->getKey(), 'repeat' => $group->allow_repeated_flavors, 'candidates' => $candidates];
            }
        }

        foreach ($items as $id => $item) {
            $available[$id] = BigDecimal::of($this->availableQuantity($item, $branch));
        }
        if (! $this->withinStock($required, $available)) {
            return new SellableAvailabilityResult('0.000', 'promotion');
        }

        $feasible = $this->search($slots, 0, $required, $available, []);

        return new SellableAvailabilityResult($feasible ? '1.000' : '0.000', 'promotion');
    }

    private function search(array $slots, int $index, array $required, array $available, array $used): bool
    {
        if ($index === count($slots)) {
            return true;
        }
        $slot = $slots[$index];
        foreach ($slot['candidates'] as $candidate) {
            if (! $slot['repeat'] && isset($used[$slot['group']][$candidate['variant_id']])) {
                continue;
            }
            $next = $required;
            foreach ($candidate['requirements'] as $id => $quantity) {
                $next[$id] = ($next[$id] ?? BigDecimal::zero())->plus($quantity);
            }
            if (! $this->withinStock($next, $available)) {
                continue;
            }
            $nextUsed = $used;
            $nextUsed[$slot['group']][$candidate['variant_id']] = true;
            if ($this->search($slots, $index + 1, $next, $available, $nextUsed)) {
                return true;
            }
        }

        return false;
    }

    private function withinStock(array $required, array $available): bool
    {
        foreach ($required as $id => $quantity) {
            if (! isset($available[$id]) || $available[$id]->isLessThan($quantity)) {
                return false;
            }
        }

        return true;
    }

    private function availableQuantity(InventoryItem $item, Branch $branch): string
    {
        $item->load([
            'inventoryStocks' => fn ($query) => $query->where('branch_id', $branch->getKey()),
            'inventoryBatches' => fn ($query) => $query->where('branch_id', $branch->getKey()),
            'inventoryReservations' => fn ($query) => $query->where('branch_id', $branch->getKey())
                ->where('status', InventoryReservationStatus::Reserved->value),
        ]);

        return $this->availability->forStock(
            $item->inventoryStocks->first(),
            $item->inventoryBatches,
            $this->availability->reservedQuantity($item->inventoryReservations),
        )->availableQuantity;
    }
}
