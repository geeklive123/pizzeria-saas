<?php

namespace App\Services;

use App\Data\ReportDateRange;
use App\Enums\InventoryMovementType;
use App\Enums\OrderItemStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

class ProfitabilityReportService
{
    public function __construct(
        private readonly SalesReportService $sales,
        private readonly ExpenseReportService $expenses,
        private readonly InventoryReportService $inventory,
        private readonly ReportDecimalService $decimal,
    ) {}

    public function data(Company $company, Branch $branch, ReportDateRange $range, array $filters = []): array
    {
        $sales = $this->sales->data($company, $branch, $range, $filters);
        $orderIds = $sales['orders']->pluck('id');
        $items = OrderItem::query()->forCompany($company)->where('branch_id', $branch->id)
            ->whereIn('order_id', $orderIds)->where('status', '!=', OrderItemStatus::Cancelled->value)
            ->with('productVariant.product')->get();
        $movements = InventoryMovement::query()->forCompany($company)->where('branch_id', $branch->id)
            ->where('type', InventoryMovementType::OrderConsumption->value)->whereDoesntHave('reversals')
            ->where('reference_type', OrderItem::class)->whereIn('reference_id', $items->pluck('id'))->get(['reference_id', 'total_cost']);
        $costsByItem = $movements->groupBy('reference_id')->map(fn (Collection $group) => $this->sum($group, 'total_cost'));
        $byVariant = $items->groupBy(function (OrderItem $item): string|int {
            $promotionUlid = $item->configuration_snapshot['promotion']['ulid'] ?? null;

            return $promotionUlid ? 'promotion:'.$promotionUlid : $item->product_variant_id;
        })->map(function (Collection $group) use ($costsByItem): array {
            $first = $group->first();
            $revenue = $this->sum($group, 'line_total');
            $cost = BigDecimal::zero();
            foreach ($group as $item) {
                $cost = $cost->plus($costsByItem->get($item->id, '0.00'));
            }
            $cost = $this->decimal->money((string) $cost);

            return [
                'name' => $first->configuration_snapshot['promotion']['name']
                    ?? $first->productVariant->product->name.' · '.$first->productVariant->name,
                'revenue' => $revenue,
                'estimated_cost' => $cost,
                'estimated_margin' => $this->decimal->subtract($revenue, $cost),
            ];
        })->sort(fn (array $left, array $right) => BigDecimal::of($right['estimated_margin'])->compareTo($left['estimated_margin']))->values();

        $productionCost = $this->sum($movements, 'total_cost');
        $expenseTotal = $this->expenses->data($company, $branch, $range, $filters)['total'];
        $wasteCost = $this->inventory->waste($company, $branch, $range)['total_cost'];

        return [
            'sales' => $sales['sales_total'],
            'production_cost' => $productionCost,
            'expenses' => $expenseTotal,
            'waste_cost' => $wasteCost,
            'estimated_result' => $this->decimal->subtract($sales['sales_total'], $productionCost, $expenseTotal, $wasteCost),
            'by_variant' => $byVariant,
            'methodology' => 'Ventas cobradas menos costo histórico de movimientos de consumo, gastos operativos y costo estimado de mermas. Las compras no se restan nuevamente.',
        ];
    }

    private function sum(iterable $rows, string $attribute): string
    {
        $total = BigDecimal::zero();
        foreach ($rows as $row) {
            $total = $total->plus($row->{$attribute});
        }

        return $this->decimal->money((string) $total);
    }
}
