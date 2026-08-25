<?php

namespace App\Services;

use App\Enums\InventoryBatchStatus;
use App\Enums\InventoryStockStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;

class DashboardService
{
    public function __construct(
        private readonly InventoryOverviewService $inventory,
        private readonly SellableAvailabilityService $availability,
    ) {}

    public function data(Company $company, Branch $branch): array
    {
        $items = $this->inventory->items($company, $branch);
        $inventoryAttention = $items->filter(function ($item): bool {
            return $item->stock_status !== InventoryStockStatus::Normal
                || in_array($item->expiration_status, [
                    InventoryBatchStatus::Expired,
                    InventoryBatchStatus::ExpiringSoon,
                ], true);
        })->take(6);
        $recipeAttention = ProductVariant::query()->forCompany($company)
            ->where('is_active', true)->whereHas('recipe', fn ($query) => $query->where('is_active', true))
            ->with('product')->orderBy('id')->get()
            ->map(function (ProductVariant $variant) use ($branch): ProductVariant {
                $variant->setAttribute('sellable_availability', $this->availability->calculate($variant, $branch));
                $limitingIngredients = collect($variant->sellable_availability->limitingIngredients)
                    ->pluck('ingredient')
                    ->filter()
                    ->join(', ');
                $variant->setAttribute(
                    'limiting_ingredients_label',
                    $limitingIngredients !== '' ? $limitingIngredients : 'Sin ingrediente limitante identificado',
                );

                return $variant;
            })
            ->filter(fn (ProductVariant $variant): bool => (int) $variant->sellable_availability->availableQuantity
                <= (int) config('inventory.low_recipe_availability', 3))
            ->take(4);

        return [
            'activeProducts' => Product::query()->forCompany($company)->where('is_active', true)->count(),
            'activeIngredients' => Ingredient::query()->forCompany($company)->where('is_active', true)->count(),
            'inventoryItems' => $items,
            'inventoryValue' => $this->inventory->totalValue($items),
            'recentPurchases' => Purchase::query()->forCompany($company)->where('branch_id', $branch->getKey())
                ->with('createdBy')->latest('purchased_at')->limit(5)->get(),
            'recentMovements' => InventoryMovement::query()->forCompany($company)->where('branch_id', $branch->getKey())
                ->with(['inventoryItem.unit', 'createdBy'])->latest('occurred_at')->limit(6)->get(),
            'inventoryAttention' => $inventoryAttention,
            'recipeAttention' => $recipeAttention,
            'occupiedTables' => Order::query()->forCompany($company)->forBranch($branch)
                ->whereIn('status', [OrderStatus::Open, OrderStatus::ReadyForPayment])->whereNotNull('active_restaurant_table_id')->count(),
            'openOrders' => Order::query()->forCompany($company)->forBranch($branch)
                ->whereIn('status', [OrderStatus::Open, OrderStatus::ReadyForPayment])->count(),
            'kitchenItems' => OrderItem::query()->forCompany($company)->where('branch_id', $branch->getKey())
                ->whereIn('status', [OrderItemStatus::Sent, OrderItemStatus::Preparing])->count(),
            'readyItems' => OrderItem::query()->forCompany($company)->where('branch_id', $branch->getKey())
                ->where('status', OrderItemStatus::Ready)->count(),
            'inventoryAlerts' => $inventoryAttention->count(),
        ];
    }
}
