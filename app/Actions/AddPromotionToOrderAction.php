<?php

namespace App\Actions;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Promotion;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderTotalsService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class AddPromotionToOrderAction
{
    public function __construct(
        private readonly ReserveInventoryForOrderItemAction $reserve,
        private readonly OrderTotalsService $totals,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(Order $order, Promotion $promotion, int|string $quantity, User $user, ?OrderType $fulfillment = null, ?string $notes = null): OrderItem
    {
        $this->access->ensure($user, $order->company, Permission::ManageOrders);
        $quantity = BigDecimal::of($quantity)->toScale(3, RoundingMode::HalfUp);
        if ($quantity->isLessThanOrEqualTo(0) || (int) $promotion->company_id !== (int) $order->company_id) {
            throw new DomainException('La cantidad o la promoción seleccionada no es válida.');
        }

        return DB::transaction(function () use ($order, $promotion, $quantity, $user, $fulfillment, $notes): OrderItem {
            $order = Order::query()->with('company')->lockForUpdate()->findOrFail($order->getKey());
            $promotion = Promotion::query()->with(['productVariant.product', 'components.inventoryItem.unit'])
                ->lockForUpdate()->findOrFail($promotion->getKey());
            if ($order->status !== OrderStatus::Open || ! $promotion->isCurrentlyActive()
                || ! $promotion->productVariant->is_active || ! $promotion->productVariant->product->is_active
                || $promotion->components->isEmpty()) {
                throw new DomainException('La promoción ya no está disponible.');
            }

            $price = BigDecimal::of($promotion->productVariant->price)->toScale(2, RoundingMode::HalfUp);
            $requirements = $promotion->components->map(fn ($component): array => [
                'inventory_item_id' => $component->inventory_item_id,
                'inventory_item_ulid' => $component->inventoryItem->ulid,
                'inventory_item_name' => $component->inventoryItem->name,
                'unit_id' => $component->inventoryItem->unit_id,
                'unit_symbol' => $component->inventoryItem->unit->symbol,
                'quantity' => $component->quantity,
            ])->values()->all();
            $snapshotComponents = collect($requirements)->map(fn (array $component): array => [
                ...$component,
                'quantity_per_promotion' => $component['quantity'],
                'quantity_applied' => (string) BigDecimal::of($component['quantity'])
                    ->multipliedBy($quantity)->toScale(3, RoundingMode::HalfUp),
            ])->all();

            $item = OrderItem::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'order_id' => $order->getKey(),
                'product_variant_id' => $promotion->product_variant_id,
                'promotion_id' => $promotion->getKey(),
                'quantity' => (string) $quantity,
                'unit_price' => (string) $price,
                'line_total' => (string) $price->multipliedBy($quantity)->toScale(2, RoundingMode::HalfUp),
                'fulfillment_type' => $fulfillment ?? $order->type,
                'requires_preparation' => false,
                'status' => OrderItemStatus::Draft,
                'notes' => $notes,
                'configuration_snapshot' => [
                    'type' => 'promotion',
                    'promotion' => [
                        'id' => $promotion->getKey(),
                        'ulid' => $promotion->ulid,
                        'name' => $promotion->productVariant->product->name,
                        'unit_price' => (string) $price,
                    ],
                    'requirements' => $requirements,
                    'components' => $snapshotComponents,
                ],
                'created_by' => $user->getKey(),
            ]);
            $this->reserve->execute($item->load('productVariant'), (string) $quantity);
            $this->totals->recalculate($order);

            return $item->refresh();
        });
    }
}
