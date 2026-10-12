<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryStock;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class InternalConsumptionPreparationService
{
    public function __construct(
        private readonly PizzaCompositionService $composition,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    /** @param list<array<string,mixed>> $items */
    public function prepare(Company $company, Branch $branch, array $items): array
    {
        if ((int) $branch->company_id !== (int) $company->getKey()) {
            throw new DomainException('La sucursal no pertenece a la empresa activa.');
        }

        $prepared = [];
        $aggregated = [];
        foreach ($items as $data) {
            $quantity = BigDecimal::of($data['quantity'])->toScale(3, RoundingMode::HalfUp);
            if ($quantity->isLessThanOrEqualTo(0)) {
                throw new DomainException('La cantidad de cada pizza debe ser mayor que cero.');
            }
            $modifiers = collect($data['modifiers'] ?? [])->filter(fn (array $modifier): bool => filled($modifier['option'] ?? null))->values()->all();
            $result = $this->composition->compose(
                $company,
                $data['sections'],
                $modifiers,
                OrderType::from($data['fulfillment_type']),
                array_values(array_filter($data['toppings'] ?? [], fn ($value): bool => filled($value))),
            );
            if (($result['snapshot']['inventory_status'] ?? null) !== 'configured') {
                throw new DomainException('No se puede registrar un consumo interno con una receta pendiente de cantidades.');
            }
            $requirements = [];
            foreach ($result['requirements'] as $requirement) {
                $required = BigDecimal::of($requirement['quantity'])->multipliedBy($quantity)->toScale(3, RoundingMode::HalfUp);
                $requirements[] = [...$requirement, 'quantity' => (string) $required];
                $id = $requirement['inventory_item']->getKey();
                $aggregated[$id] ??= ['inventory_item' => $requirement['inventory_item'], 'quantity' => BigDecimal::zero()];
                $aggregated[$id]['quantity'] = $aggregated[$id]['quantity']->plus($required);
            }
            $prepared[] = [
                'quantity' => (string) $quantity,
                'fulfillment_type' => OrderType::from($data['fulfillment_type']),
                'notes' => $data['notes'] ?? null,
                'composition' => $result,
                'requirements' => $requirements,
            ];
        }

        ksort($aggregated);
        $estimatedTotal = BigDecimal::zero();
        $knownCosts = 0;
        $unknownCosts = 0;
        foreach ($aggregated as &$requirement) {
            $item = $requirement['inventory_item'];
            $stock = InventoryStock::query()->where('company_id', $company->getKey())
                ->where('branch_id', $branch->getKey())->where('inventory_item_id', $item->getKey())->first();
            $required = $requirement['quantity']->toScale(3, RoundingMode::HalfUp);
            $available = $this->availability->forStock($stock);
            if (BigDecimal::of($available->availableQuantity)->isLessThan($required)) {
                throw new InsufficientStockException("No disponible: falta {$item->name}.");
            }
            $unitCost = $stock?->average_cost;
            $known = $unitCost !== null && BigDecimal::of($unitCost)->isGreaterThan(0);
            $totalCost = $known ? BigDecimal::of($unitCost)->multipliedBy($required)->toScale(6, RoundingMode::HalfUp) : null;
            if ($totalCost) {
                $estimatedTotal = $estimatedTotal->plus($totalCost);
                $knownCosts++;
            } else {
                $unknownCosts++;
            }
            $requirement = [
                'inventory_item' => $item,
                'quantity' => (string) $required,
                'available_quantity' => $available->availableQuantity,
                'unit_cost' => $known ? $unitCost : null,
                'total_cost' => $totalCost ? (string) $totalCost : null,
                'cost_status' => $known ? 'known' : 'unknown',
            ];
        }
        unset($requirement);

        return [
            'items' => $prepared,
            'requirements' => array_values($aggregated),
            'estimated_total_cost' => (string) $estimatedTotal->toScale(6, RoundingMode::HalfUp),
            'cost_status' => match (true) {
                $unknownCosts === 0 => 'known',
                $knownCosts === 0 => 'unknown',
                default => 'partial',
            },
        ];
    }
}
