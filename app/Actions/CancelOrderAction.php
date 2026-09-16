<?php

namespace App\Actions;

use App\Enums\KitchenDispatchStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderTotalsService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelOrderAction
{
    public function __construct(
        private readonly ReleaseInventoryReservationAction $release,
        private readonly ReverseInventoryMovementAction $reverseMovement,
        private readonly OrderTotalsService $totals,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(Order $order, User $user, string $reason): Order
    {
        $this->access->ensure($user, $order->company, Permission::CancelOrders);
        if (blank($reason)) {
            throw new DomainException('Indica el motivo de la anulación.');
        }

        return DB::transaction(function () use ($order, $user, $reason): Order {
            $order = Order::query()->with(['company', 'branch', 'items.productVariant'])->lockForUpdate()->findOrFail($order->id);
            if ($order->payments()->where('status', PaymentStatus::Completed->value)->exists()) {
                throw new DomainException('El pedido tiene pagos vigentes. Revierte primero los pagos antes de anularlo.');
            }
            if (! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
                throw new DomainException('Este pedido ya no puede anularse.');
            }
            $cancelledAt = now();
            foreach ($order->items->where('status', '!=', OrderItemStatus::Cancelled) as $item) {
                if (in_array($item->status, [OrderItemStatus::Draft, OrderItemStatus::PendingPayment, OrderItemStatus::Sent], true)) {
                    $this->release->execute($item, $item->quantity);
                }
                $movements = InventoryMovement::query()
                    ->where('reference_type', OrderItem::class)
                    ->where('reference_id', $item->getKey())
                    ->where('type', InventoryMovementType::OrderConsumption->value)
                    ->whereDoesntHave('reversals')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($movements as $movement) {
                    $this->reverseMovement->execute(
                        $movement,
                        $user,
                        $reason,
                        requiredPermission: Permission::CancelOrders,
                    );
                }
                $item->forceFill([
                    'status' => OrderItemStatus::Cancelled,
                    'cancelled_at' => $cancelledAt,
                    'cancelled_by' => $user->getKey(),
                    'cancellation_reason' => $reason,
                ])->save();
            }
            $order->kitchenDispatches()
                ->whereIn('status', [KitchenDispatchStatus::AwaitingPayment->value, KitchenDispatchStatus::Released->value])
                ->update(['status' => KitchenDispatchStatus::Cancelled->value]);
            $order->forceFill([
                'status' => OrderStatus::Cancelled,
                'active_restaurant_table_id' => null,
                'closed_at' => $cancelledAt,
                'cancellation_reason' => $reason,
                'cancelled_at' => $cancelledAt,
                'cancelled_by' => $user->getKey(),
            ])->save();

            return $this->totals->recalculate($order);
        });
    }
}
