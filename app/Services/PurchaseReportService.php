<?php

namespace App\Services;

use App\Data\ReportDateRange;
use App\Enums\PurchaseStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Purchase;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PurchaseReportService
{
    public function __construct(private readonly ReportDecimalService $decimal) {}

    public function data(Company $company, Branch $branch, ReportDateRange $range): array
    {
        $purchases = $this->query($company, $branch, $range)->with(['items.inventoryItem.unit'])->get();
        $items = $purchases->pluck('items')->flatten();

        return [
            'total' => $this->sum($items, 'total_cost'),
            'purchases' => $purchases->count(),
            'by_supplier' => $purchases->groupBy(fn (Purchase $purchase) => $purchase->supplier_name ?: 'Sin proveedor')->map(fn (Collection $group, string $name) => ['name' => $name, 'amount' => $this->sum($group->pluck('items')->flatten(), 'total_cost')])->sort(fn (array $left, array $right) => BigDecimal::of($right['amount'])->compareTo($left['amount']))->values(),
            'items' => $items->groupBy('inventory_item_id')->map(function (Collection $group): array {
                $first = $group->first();
                $quantity = $this->sumQuantity($group, 'base_quantity');
                $total = $this->sum($group, 'total_cost');

                return ['name' => $first->inventoryItem->name, 'unit' => $first->inventoryItem->unit->symbol, 'quantity' => $quantity, 'amount' => $total, 'average_cost' => BigDecimal::of($quantity)->isZero() ? '0.000000' : (string) BigDecimal::of($total)->dividedBy($quantity, 6, RoundingMode::HalfUp)];
            })->sort(fn (array $left, array $right) => BigDecimal::of($right['amount'])->compareTo($left['amount']))->values(),
        ];
    }

    public function paginate(Company $company, Branch $branch, ReportDateRange $range): LengthAwarePaginator
    {
        return $this->query($company, $branch, $range)->with(['items', 'createdBy:id,name'])->latest('purchased_at')->paginate(config('reports.per_page'))->withQueryString();
    }

    public function query(Company $company, Branch $branch, ReportDateRange $range): Builder
    {
        return Purchase::query()->forCompany($company)->where('branch_id', $branch->id)
            ->where('status', PurchaseStatus::Posted->value)
            ->whereBetween('purchased_at', [$range->fromUtc(), $range->toUtc()]);
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
