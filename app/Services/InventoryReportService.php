<?php

namespace App\Services;

use App\Data\ReportDateRange;
use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryMovement;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class InventoryReportService
{
    public function __construct(private readonly InventoryOverviewService $overview, private readonly ReportDecimalService $decimal) {}

    public function inventory(Company $company, Branch $branch, string $sort = 'name'): array
    {
        $items = $this->overview->items($company, $branch);
        $items = (match ($sort) {
            'value' => $items->sortByDesc(fn ($item) => BigDecimal::of($item->display_value)),
            'low_stock' => $items->sortBy(fn ($item) => $item->stock_status->value),
            'expiration' => $items->sortBy(fn ($item) => $item->next_expiration_at?->format('Y-m-d') ?? '9999-12-31'),
            default => $items->sortBy('name'),
        })->values();

        return ['items' => $items, 'total_value' => $this->overview->totalValue($items)];
    }

    public function waste(Company $company, Branch $branch, ReportDateRange $range): array
    {
        $movements = $this->movementQuery($company, $branch, $range, InventoryMovementType::Waste)
            ->with(['inventoryItem.unit', 'createdBy:id,name', 'inventoryBatch'])->latest('occurred_at')->get();

        return ['movements' => $movements, 'total_cost' => $this->sum($movements, 'total_cost'), 'total_quantity' => $this->sumQuantity($movements, 'quantity'), 'by_item' => $this->groupMovements($movements)];
    }

    public function consumption(Company $company, Branch $branch, ReportDateRange $range): array
    {
        $movements = $this->movementQuery($company, $branch, $range, InventoryMovementType::OrderConsumption)
            ->with(['inventoryItem.unit'])->get();

        return ['movements' => $movements, 'total_cost' => $this->sum($movements, 'total_cost'), 'by_item' => $this->groupMovements($movements)];
    }

    public function movementQuery(Company $company, Branch $branch, ReportDateRange $range, InventoryMovementType $type): Builder
    {
        return InventoryMovement::query()->forCompany($company)->where('branch_id', $branch->id)
            ->where('type', $type->value)->whereDoesntHave('reversals')
            ->whereBetween('occurred_at', [$range->fromUtc(), $range->toUtc()]);
    }

    private function groupMovements(Collection $movements): Collection
    {
        return $movements->groupBy('inventory_item_id')->map(function (Collection $group): array {
            $first = $group->first();

            return ['name' => $first->inventoryItem->name, 'unit' => $first->inventoryItem->unit->symbol, 'quantity' => $this->sumQuantity($group, 'quantity'), 'cost' => $this->sum($group, 'total_cost')];
        })->sort(fn (array $left, array $right) => BigDecimal::of($right['cost'])->compareTo($left['cost']))->values();
    }

    private function sum(iterable $rows, string $attribute): string
    {
        $total = BigDecimal::zero();
        foreach ($rows as $row) {
            $total = $total->plus($row->{$attribute});
        }

        return $this->decimal->money((string) $total);
    }

    private function sumQuantity(iterable $rows, string $attribute): string
    {
        $total = BigDecimal::zero();
        foreach ($rows as $row) {
            $total = $total->plus($row->{$attribute});
        }

        return $this->decimal->quantity((string) $total);
    }
}
