<?php

namespace App\Services;

use App\Data\ReportDateRange;
use App\Enums\KitchenDispatchStatus;
use App\Enums\ModifierOptionType;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\TableChargeMode;
use App\Models\Branch;
use App\Models\Company;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SalesReportService
{
    public function __construct(private readonly ReportDecimalService $decimal) {}

    public function data(Company $company, Branch $branch, ReportDateRange $range, array $filters = []): array
    {
        $orders = $this->paidOrders($company, $branch, $range)
            ->when($filters['payment_method'] ?? null, fn (Builder $query, string $method) => $query->whereHas('payments', fn (Builder $payments) => $payments->where('status', PaymentStatus::Completed->value)->where('method', $method)))
            ->when($filters['category_id'] ?? null, fn (Builder $query, int $category) => $query->whereHas('items.productVariant.product', fn (Builder $products) => $products->where('category_id', $category)))
            ->when($filters['product_id'] ?? null, fn (Builder $query, int $product) => $query->whereHas('items.productVariant', fn (Builder $variants) => $variants->where('product_id', $product)))
            ->when($filters['variant_id'] ?? null, fn (Builder $query, int $variant) => $query->whereHas('items', fn (Builder $items) => $items->where('product_variant_id', $variant)))
            ->get(['id', 'subtotal', 'discount_total', 'total', 'type', 'closed_at', 'created_by']);
        $batchDispatches = KitchenDispatch::query()->forCompany($company)->forBranch($branch)
            ->where('status', KitchenDispatchStatus::Settled->value)
            ->whereBetween('settled_at', [$range->fromUtc(), $range->toUtc()])
            ->whereHas('order', fn (Builder $query) => $query->where('type', OrderType::DineIn->value)->where('charge_mode', TableChargeMode::PerBatch->value))
            ->when($filters['payment_method'] ?? null, fn (Builder $query, string $method) => $query->whereHas('payments', fn (Builder $payments) => $payments->where('status', PaymentStatus::Completed->value)->where('method', $method)))
            ->with('order:id,type,closed_at,created_by')->get();
        $salesTotal = $this->decimal->money((string) BigDecimal::of($this->sum($orders, 'total'))->plus($this->sum($batchDispatches, 'total')));
        $grossSales = $this->decimal->money((string) BigDecimal::of($this->sum($orders, 'subtotal'))->plus($this->sum($batchDispatches, 'gross_subtotal')));
        $discountTotal = $this->decimal->money((string) BigDecimal::of($this->sum($orders, 'discount_total'))->plus($this->sum($batchDispatches, 'discount_total')));
        $payments = Payment::query()->forCompany($company)->where('branch_id', $branch->id)
            ->where('status', PaymentStatus::Completed->value)
            ->whereBetween('paid_at', [$range->fromUtc(), $range->toUtc()])
            ->when($filters['payment_method'] ?? null, fn (Builder $query, string $method) => $query->where('method', $method))
            ->with('receivedBy:id,name')->get(['id', 'method', 'amount', 'paid_at', 'received_by']);

        $items = OrderItem::query()->forCompany($company)->where('branch_id', $branch->id)
            ->where(function (Builder $query) use ($orders, $batchDispatches): void {
                $query->whereIn('order_id', $orders->pluck('id'))
                    ->orWhereHas('kitchenDispatchItem', fn (Builder $items) => $items->whereIn('kitchen_dispatch_id', $batchDispatches->pluck('id')));
            })
            ->where('status', '!=', OrderItemStatus::Cancelled->value)
            ->when($filters['category_id'] ?? null, fn (Builder $query, int $category) => $query->whereHas('productVariant.product', fn (Builder $products) => $products->where('category_id', $category)))
            ->when($filters['product_id'] ?? null, fn (Builder $query, int $product) => $query->whereHas('productVariant', fn (Builder $variants) => $variants->where('product_id', $product)))
            ->when($filters['variant_id'] ?? null, fn (Builder $query, int $variant) => $query->where('product_variant_id', $variant))
            ->with(['productVariant.product.category', 'sections', 'modifiers', 'kitchenDispatchItem'])
            ->get();

        return [
            'sales_total' => $salesTotal,
            'gross_sales' => $grossSales,
            'discount_total' => $discountTotal,
            'orders_paid' => $orders->count() + $batchDispatches->count(),
            'average_ticket' => $this->decimal->average($salesTotal, $orders->count() + $batchDispatches->count()),
            'by_day' => $this->ordersByLocalKey($orders, $range, 'Y-m-d'),
            'by_hour' => $this->ordersByLocalKey($orders, $range, 'H:00'),
            'by_type' => $this->groupOrders($orders, fn (Order $order) => $order->type->value),
            'payments' => $this->paymentBreakdown($payments),
            'products' => $this->productRanking($items, false),
            'variants' => $this->productRanking($items, true),
            'by_category' => $this->categoryRanking($items),
            'pizza_analysis' => $this->pizzaAnalysis($items),
            'orders' => $orders,
            'recognized_item_ids' => $items->pluck('id'),
        ];
    }

    public function paidOrders(Company $company, Branch $branch, ReportDateRange $range): Builder
    {
        return Order::query()->forCompany($company)->forBranch($branch)
            ->where('status', OrderStatus::Paid->value)
            ->where(fn (Builder $query) => $query->where('type', '!=', OrderType::DineIn->value)->orWhere('charge_mode', '!=', TableChargeMode::PerBatch->value))
            ->whereBetween('closed_at', [$range->fromUtc(), $range->toUtc()]);
    }

    private function paymentBreakdown(Collection $payments): Collection
    {
        $total = $this->sum($payments, 'amount');

        return $payments->groupBy(fn (Payment $payment) => $payment->method->value)
            ->map(fn (Collection $group, string $method) => [
                'method' => $method,
                'amount' => $amount = $this->sum($group, 'amount'),
                'count' => $group->count(),
                'percentage' => $this->decimal->percentage($amount, $total),
            ])->sort(fn (array $left, array $right) => BigDecimal::of($right['amount'])->compareTo($left['amount']))->values();
    }

    private function productRanking(Collection $items, bool $variant): Collection
    {
        return $items->groupBy(function (OrderItem $item) use ($variant): string|int {
            $promotionUlid = $item->configuration_snapshot['promotion']['ulid'] ?? null;

            return $promotionUlid ? 'promotion:'.$promotionUlid : ($variant ? $item->product_variant_id : $item->productVariant->product_id);
        })
            ->map(function (Collection $group) use ($variant): array {
                $first = $group->first();
                $promotionName = $first->configuration_snapshot['promotion']['name'] ?? null;

                return [
                    'name' => $promotionName ?: ($variant ? $first->productVariant->product->name.' · '.$first->productVariant->name : $first->productVariant->product->name),
                    'quantity' => $this->sumQuantity($group, 'quantity'),
                    'revenue' => $this->sumRevenue($group),
                ];
            })->sort(fn (array $left, array $right) => BigDecimal::of($right['quantity'])->compareTo($left['quantity']))->values();
    }

    private function categoryRanking(Collection $items): Collection
    {
        return $items->groupBy(fn (OrderItem $item) => ($item->configuration_snapshot['type'] ?? null) === 'promotion'
            ? 'Promociones'
            : ($item->productVariant->product->category?->name ?? 'Sin categoría'))
            ->map(fn (Collection $group, string $name) => ['name' => $name, 'count' => $this->sumQuantity($group, 'quantity'), 'amount' => $this->sumRevenue($group)])
            ->sort(fn (array $left, array $right) => BigDecimal::of($right['amount'])->compareTo($left['amount']))->values();
    }

    private function pizzaAnalysis(Collection $items): array
    {
        $configured = $items->filter(fn (OrderItem $item) => $item->sections->isNotEmpty());
        $flavors = [];
        $combinations = [];
        $sectionCounts = [1 => BigDecimal::zero(), 2 => BigDecimal::zero(), 3 => BigDecimal::zero(), 4 => BigDecimal::zero()];
        $modifiers = ['add' => [], 'remove' => []];

        foreach ($configured as $item) {
            $quantity = BigDecimal::of($item->quantity);
            $count = $item->sections->count();
            if (isset($sectionCounts[$count])) {
                $sectionCounts[$count] = $sectionCounts[$count]->plus($quantity);
            }
            $names = [];
            foreach ($item->sections as $section) {
                $name = $section->product_name_snapshot.' '.$section->variant_name_snapshot;
                $names[] = $name;
                $share = $quantity->multipliedBy($section->fraction_numerator)->dividedBy($section->fraction_denominator, 6, RoundingMode::HalfUp);
                $flavors[$name] = ($flavors[$name] ?? BigDecimal::zero())->plus($share);
            }
            sort($names);
            $key = implode(' + ', $names);
            $combinations[$key] = ($combinations[$key] ?? BigDecimal::zero())->plus($quantity);
            foreach ($item->modifiers as $modifier) {
                $type = $modifier->type === ModifierOptionType::Add ? 'add' : 'remove';
                $modifiers[$type][$modifier->name_snapshot] = ($modifiers[$type][$modifier->name_snapshot] ?? 0) + 1;
            }
        }

        return [
            'flavors' => $this->decimalMap($flavors),
            'combinations' => $this->decimalMap($combinations),
            'section_counts' => collect($sectionCounts)->map(fn (BigDecimal $value, int $count) => ['sections' => $count, 'quantity' => $this->decimal->quantity((string) $value)])->values(),
            'extras' => collect($modifiers['add'])->map(fn (int $count, string $name) => compact('name', 'count'))->sortByDesc('count')->values(),
            'removed' => collect($modifiers['remove'])->map(fn (int $count, string $name) => compact('name', 'count'))->sortByDesc('count')->values(),
        ];
    }

    private function ordersByLocalKey(Collection $orders, ReportDateRange $range, string $format): Collection
    {
        return $orders->groupBy(fn (Order $order) => $order->closed_at->setTimezone($range->timezone)->format($format))
            ->map(fn (Collection $group, string $key) => ['label' => $key, 'orders' => $group->count(), 'amount' => $this->sum($group, 'total')])
            ->sortBy('label')->values();
    }

    private function groupOrders(Collection $orders, callable $key): Collection
    {
        return $orders->groupBy($key)->map(fn (Collection $group, string $name) => ['name' => $name, 'orders' => $group->count(), 'amount' => $this->sum($group, 'total')])->values();
    }

    private function decimalMap(array $values): Collection
    {
        return collect($values)->map(fn (BigDecimal $value, string $name) => ['name' => $name, 'quantity' => $this->decimal->quantity((string) $value)])->sort(fn (array $left, array $right) => BigDecimal::of($right['quantity'])->compareTo($left['quantity']))->values();
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

    private function sumRevenue(iterable $items): string
    {
        $total = BigDecimal::zero();
        foreach ($items as $item) {
            $total = $total->plus($item->kitchenDispatchItem?->net_total ?? $item->line_total);
        }

        return $this->decimal->money((string) $total);
    }
}
