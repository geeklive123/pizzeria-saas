<?php

namespace App\Actions;

use App\Enums\ModifierOptionType;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Enums\ProductModifierPurpose;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderTotalsService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class AddStandaloneExtraAction
{
    public function __construct(
        private readonly ReserveInventoryForOrderItemAction $reserve,
        private readonly OrderTotalsService $totals,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(Order $order, ModifierOption $extra, int|string $quantity, User $user, ?OrderType $fulfillment = null): OrderItem
    {
        $order->loadMissing('company');
        $this->access->ensure($user, $order->company, Permission::ManageOrders);
        $quantity = BigDecimal::of($quantity)->toScale(3, RoundingMode::HalfUp);

        if ($quantity->isLessThanOrEqualTo(0) || (int) $extra->company_id !== (int) $order->company_id) {
            throw new DomainException('La cantidad o el extra seleccionado no es válido.');
        }

        return DB::transaction(function () use ($order, $extra, $quantity, $user, $fulfillment): OrderItem {
            $order = Order::query()->with('company')->lockForUpdate()->findOrFail($order->getKey());
            $extra = ModifierOption::query()->with(['modifier', 'inventoryItem.unit'])
                ->lockForUpdate()->findOrFail($extra->getKey());

            if ($order->status !== OrderStatus::Open || ! $extra->is_active
                || $extra->modifier?->purpose !== ProductModifierPurpose::ToppingCatalog
                || (int) $extra->company_id !== (int) $order->company_id) {
                throw new DomainException('El extra seleccionado ya no está disponible.');
            }
            if ($extra->inventory_item_id && ! $extra->quantity) {
                throw new DomainException('El extra no tiene una cantidad de inventario configurada.');
            }

            $unitPrice = BigDecimal::of($extra->price_delta)->toScale(2, RoundingMode::HalfUp);
            $requirements = $extra->inventoryItem ? [[
                'inventory_item_id' => $extra->inventory_item_id,
                'inventory_item_ulid' => $extra->inventoryItem->ulid,
                'inventory_item_name' => $extra->inventoryItem->name,
                'unit_id' => $extra->inventoryItem->unit_id,
                'unit_symbol' => $extra->inventoryItem->unit->symbol,
                'quantity' => $extra->quantity,
            ]] : [];

            $item = OrderItem::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'order_id' => $order->getKey(),
                'product_variant_id' => null,
                'quantity' => (string) $quantity,
                'unit_price' => (string) $unitPrice,
                'line_total' => (string) $unitPrice->multipliedBy($quantity)->toScale(2, RoundingMode::HalfUp),
                'fulfillment_type' => $fulfillment ?? $order->type,
                'requires_preparation' => false,
                'status' => OrderItemStatus::Draft,
                'configuration_snapshot' => [
                    'type' => 'standalone_extra',
                    'extra' => [
                        'id' => $extra->getKey(),
                        'ulid' => $extra->ulid,
                        'name' => $extra->name,
                        'unit_price' => (string) $unitPrice,
                    ],
                    'requirements' => $requirements,
                ],
                'created_by' => $user->getKey(),
            ]);

            OrderItemModifier::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'order_item_id' => $item->getKey(),
                'modifier_option_id' => $extra->getKey(),
                'type' => ModifierOptionType::Add,
                'name_snapshot' => $extra->name,
                'price_delta_snapshot' => (string) $unitPrice,
                'inventory_item_id' => $extra->inventory_item_id,
                'quantity_snapshot' => $extra->quantity,
                'unit_id' => $extra->unit_id,
            ]);

            $this->reserve->execute($item, (string) $quantity);
            $this->totals->recalculate($order);

            return $item->refresh()->load('modifiers');
        });
    }
}
