<?php

namespace App\Services;

use App\Data\ReportDateRange;
use App\Enums\InternalConsumptionReason;
use App\Enums\InternalConsumptionStatus;
use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InternalConsumption;
use App\Models\InternalConsumptionComponent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class InternalConsumptionReportService
{
    public function period(Company $company, Branch $branch, ReportDateRange $range): array
    {
        $consumptions = InternalConsumption::query()
            ->forCompany($company)
            ->where('branch_id', $branch->getKey())
            ->where('status', InternalConsumptionStatus::Confirmed->value)
            ->whereBetween('confirmed_at', [$range->fromUtc(), $range->toUtc()])
            ->whereHas('components.movement', fn ($query) => $query
                ->where('type', InventoryMovementType::InternalConsumption->value)
                ->whereDoesntHave('reversals'))
            ->with([
                'items:id,internal_consumption_id,quantity',
                'components' => fn ($query) => $query
                    ->whereHas('movement', fn ($movements) => $movements
                        ->where('type', InventoryMovementType::InternalConsumption->value)
                        ->whereDoesntHave('reversals'))
                    ->with(['movement:id,type,total_cost', 'unit:id,symbol']),
            ])->get();

        $knownCost = BigDecimal::zero();
        $knownComponentCount = 0;
        $pizzaQuantity = BigDecimal::zero();
        $statusCounts = ['known' => 0, 'partial' => 0, 'unknown' => 0, 'known_zero' => 0];

        foreach ($consumptions as $consumption) {
            foreach ($consumption->items as $item) {
                $pizzaQuantity = $pizzaQuantity->plus($item->quantity);
            }

            $components = $consumption->components;
            $knownComponents = $components->where('cost_status', 'known');
            $unknownComponents = $components->where('cost_status', '!=', 'known');
            $knownComponentCount += $knownComponents->count();
            $consumptionKnownCost = $this->knownComponentCost($knownComponents);
            $knownCost = $knownCost->plus($consumptionKnownCost);

            $status = match (true) {
                $unknownComponents->isEmpty() => 'known',
                $knownComponents->isEmpty() => 'unknown',
                default => 'partial',
            };
            $statusCounts[$status]++;
            if ($status === 'known' && $consumptionKnownCost->isZero()) {
                $statusCounts['known_zero']++;
            }
        }

        $components = $consumptions->flatMap->components;

        return [
            'consumptions' => $consumptions->count(),
            'pizzas' => (string) $pizzaQuantity->toScale(3, RoundingMode::HalfUp),
            'known_cost' => (string) $knownCost->toScale(2, RoundingMode::HalfUp),
            'has_known_costs' => $knownComponentCount > 0,
            'has_incomplete_costs' => $statusCounts['partial'] > 0 || $statusCounts['unknown'] > 0,
            'cost_status_counts' => $statusCounts,
            'by_item' => $this->groupComponents($components),
        ];
    }

    public function monthly(Company $company, Branch $branch, ?string $month = null): array
    {
        $start = $month ? CarbonImmutable::createFromFormat('Y-m', $month, 'America/La_Paz')->startOfMonth() : CarbonImmutable::now('America/La_Paz')->startOfMonth();
        $end = $start->endOfMonth();
        $rows = InternalConsumption::query()->forCompany($company)->where('branch_id', $branch->getKey())
            ->where('status', InternalConsumptionStatus::Confirmed->value)
            ->whereBetween('confirmed_at', [$start->utc(), $end->utc()])->with('items')->get();

        return collect(InternalConsumptionReason::cases())->map(function (InternalConsumptionReason $reason) use ($rows): array {
            $matching = $rows->where('reason', $reason);
            $pizzas = $matching->flatMap->items->reduce(fn (BigDecimal $total, $item): BigDecimal => $total->plus($item->quantity), BigDecimal::zero());
            $cost = $matching->reduce(fn (BigDecimal $total, InternalConsumption $item): BigDecimal => $total->plus($item->total_cost), BigDecimal::zero());

            return ['reason' => $reason, 'pizzas' => (string) $pizzas->toScale(3, RoundingMode::HalfUp), 'cost' => (string) $cost->toScale(6, RoundingMode::HalfUp)];
        })->all();
    }

    private function groupComponents(Collection $components): Collection
    {
        return $components->groupBy(fn (InternalConsumptionComponent $component): string => $component->inventory_item_id.':'.$component->unit_id)
            ->map(function (Collection $group): array {
                $first = $group->first();
                $quantity = BigDecimal::zero();
                foreach ($group as $component) {
                    $quantity = $quantity->plus($component->quantity);
                }

                return [
                    'name' => $first->inventory_item_name_snapshot,
                    'unit' => $first->unit_symbol_snapshot ?: $first->unit?->symbol,
                    'quantity' => (string) $quantity->toScale(3, RoundingMode::HalfUp),
                    'movements' => $group->count(),
                    'known_cost' => (string) $this->knownComponentCost($group->where('cost_status', 'known'))->toScale(2, RoundingMode::HalfUp),
                    'cost_status' => match (true) {
                        $group->every(fn (InternalConsumptionComponent $component): bool => $component->cost_status === 'known') => 'known',
                        $group->every(fn (InternalConsumptionComponent $component): bool => $component->cost_status !== 'known') => 'unknown',
                        default => 'partial',
                    },
                    'has_incomplete_costs' => $group->contains(fn (InternalConsumptionComponent $component): bool => $component->cost_status !== 'known'),
                ];
            })->sortBy('name')->values();
    }

    private function knownComponentCost(iterable $components): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($components as $component) {
            $total = $total->plus($component->total_cost ?? '0');
        }

        return $total;
    }
}
