<?php

namespace App\Services;

use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RestaurantTable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SaleEntryService
{
    public function __construct(private readonly InventoryOverviewService $inventory) {}

    /** @return array{tables: Collection<int, RestaurantTable>, metrics: array<string, int|string>, lowStockItems: Collection<int, InventoryItem>} */
    public function data(Company $company, Branch $branch): array
    {
        $tables = $this->activeTables($company, $branch);
        $occupiedTables = $tables->whereNotNull('openOrder')->count();
        $timezone = config('reports.timezone', 'America/La_Paz');
        $today = CarbonImmutable::now($timezone);
        $from = $today->startOfDay()->utc();
        $to = $today->endOfDay()->utc();

        $payments = Payment::query()->forCompany($company)->where('branch_id', $branch->getKey())
            ->where('status', PaymentStatus::Completed->value)
            ->whereBetween('paid_at', [$from, $to]);
        $qrPayments = (clone $payments)->where('method', PaymentMethod::Qr->value);

        return [
            'tables' => $tables,
            'metrics' => [
                'tables_total' => $tables->count(),
                'tables_occupied' => $occupiedTables,
                'occupancy_percentage' => $tables->isEmpty() ? 0 : (int) round(($occupiedTables * 100) / $tables->count()),
                'open_orders' => Order::query()->forCompany($company)->forBranch($branch)
                    ->whereIn('status', [OrderStatus::Open->value, OrderStatus::ReadyForPayment->value])->count(),
                'collected_today' => $this->money((string) (clone $payments)->sum('amount')),
                'collected_orders_today' => (clone $payments)->distinct()->count('order_id'),
                'qr_today' => $this->money((string) (clone $qrPayments)->sum('amount')),
                'qr_payments_today' => (clone $qrPayments)->count(),
            ],
            'lowStockItems' => $this->lowStockItems($company, $branch),
        ];
    }

    public function activeTables(Company $company, Branch $branch): Collection
    {
        return RestaurantTable::query()->forCompany($company)->forBranch($branch)
            ->where('is_active', true)
            ->with(['openOrder.kitchenDispatches' => fn ($query) => $query->latest('sequence_number')])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->each(function (RestaurantTable $table): void {
                $order = $table->openOrder;
                if (! $order) {
                    return;
                }

                $pendingDispatch = $order->kitchenDispatches
                    ->firstWhere('status', KitchenDispatchStatus::AwaitingPayment);
                $order->setAttribute(
                    'has_pending_payment',
                    $order->status === OrderStatus::ReadyForPayment || $pendingDispatch !== null,
                );
                $order->setAttribute('pending_amount', $pendingDispatch?->total ?? $order->total);
            });
    }

    /** @return Collection<int, InventoryItem> */
    private function lowStockItems(Company $company, Branch $branch): Collection
    {
        return $this->inventory->items($company, $branch)
            ->filter(function (InventoryItem $item): bool {
                $minimum = $item->inventoryStocks->first()?->minimum_quantity;

                return $item->is_active && $minimum !== null && BigDecimal::of($minimum)->isGreaterThan(0)
                    && BigDecimal::of($item->available_quantity)->isLessThanOrEqualTo(
                        BigDecimal::of($minimum)->multipliedBy('1.25'),
                    );
            })
            ->each(function (InventoryItem $item): void {
                $minimum = $item->inventoryStocks->first()->minimum_quantity;
                $item->setAttribute(
                    'sales_stock_level',
                    BigDecimal::of($item->available_quantity)->isLessThanOrEqualTo($minimum) ? 'low' : 'near',
                );
            })
            ->sort(function (InventoryItem $left, InventoryItem $right): int {
                $level = ($left->sales_stock_level === 'low' ? 0 : 1) <=> ($right->sales_stock_level === 'low' ? 0 : 1);
                if ($level !== 0) {
                    return $level;
                }

                $leftRatio = BigDecimal::of($left->available_quantity)
                    ->dividedBy($left->inventoryStocks->first()->minimum_quantity, 6, RoundingMode::HalfUp);
                $rightRatio = BigDecimal::of($right->available_quantity)
                    ->dividedBy($right->inventoryStocks->first()->minimum_quantity, 6, RoundingMode::HalfUp);

                return $leftRatio->compareTo($rightRatio);
            })
            ->take(6)
            ->values();
    }

    private function money(string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
    }
}
