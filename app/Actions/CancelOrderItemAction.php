<?php

namespace App\Actions;

use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\OrderDeliveryCharge;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderTotalsService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelOrderItemAction
{
    public function __construct(
        private readonly ReleaseInventoryReservationAction $release,
        private readonly OrderTotalsService $totals,
        private readonly CompanyAccessService $access,
        private readonly ClosePaidOrderAction $closeOrder,
    ) {}

    public function execute(OrderItem $item, User $user, ?string $reason = null): OrderItem
    {
        $this->access->ensure($user, $item->company, Permission::ManageOrders);

        return DB::transaction(function () use ($item, $user, $reason): OrderItem {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $item = OrderItem::query()->with(['order', 'productVariant', 'kitchenDispatchItem.dispatch'])->lockForUpdate()->findOrFail($item->id);
            if ($item->status === OrderItemStatus::Cancelled) {
                return $item;
            }
            if ($order->status !== OrderStatus::Open) {
                throw new DomainException('Este producto ya no puede cancelarse.');
            }
            if ($item->status !== OrderItemStatus::Draft && OrderDeliveryCharge::query()->where('order_id', $order->id)->where('enabled', true)->exists()) {
                throw new DomainException('Anula el producto desde la tanda para conservar el cargo de envío.');
            }
            if ($item->status !== OrderItemStatus::Draft) {
                $this->access->ensure($user, $item->company, Permission::CancelOrders);
                if (blank($reason)) {
                    throw new DomainException('Indica el motivo para cancelar un producto que ya fue enviado.');
                }
            }
            if (in_array($item->status, [OrderItemStatus::Draft, OrderItemStatus::PendingPayment, OrderItemStatus::Sent], true)) {
                $this->release->execute($item, $item->quantity);
            }
            $item->forceFill([
                'status' => OrderItemStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $user->getKey(),
                'cancellation_reason' => $reason,
            ])->save();
            $dispatch = $item->kitchenDispatchItem?->dispatch;
            if ($dispatch && ! $dispatch->items()->whereHas('orderItem', fn ($query) => $query->where('status', '!=', OrderItemStatus::Cancelled->value))->exists()) {
                $dispatch->forceFill(['status' => KitchenDispatchStatus::Cancelled])->save();
            }
            $deliveryCleared = false;
            if ($order->type === OrderType::Takeaway
                && ! $order->items()->where('status', '!=', OrderItemStatus::Cancelled->value)->exists()) {
                $charge = OrderDeliveryCharge::query()->where('order_id', $order->id)->where('enabled', true)->whereNull('kitchen_dispatch_id')->lockForUpdate()->first();
                if ($charge) {
                    $deliveryCleared = true;
                    $charge->update(['enabled' => false, 'updated_by' => $user->id]);
                    DB::table('order_delivery_charge_audits')->insert([
                        'order_delivery_charge_id' => $charge->id,
                        'previous_amount' => $charge->amount,
                        'new_amount' => $charge->amount,
                        'previous_enabled' => true,
                        'new_enabled' => false,
                        'user_id' => $user->id,
                        'created_at' => now(),
                    ]);
                }
            }
            $order = $this->totals->recalculate($order);
            if ($order->type === OrderType::Takeaway && ! $deliveryCleared) {
                $this->closeOrder->executeIfEligibleLocked($order);
            }

            return $item->refresh();
        });
    }
}
