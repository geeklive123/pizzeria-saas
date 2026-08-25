<?php

namespace App\Actions;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderTotalsService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelOrderAction
{
    public function __construct(private readonly ReleaseInventoryReservationAction $release, private readonly OrderTotalsService $totals, private readonly CompanyAccessService $access) {}

    public function execute(Order $order, User $user): Order
    {
        $this->access->ensure($user, $order->company, Permission::CancelOrders);

        return DB::transaction(function () use ($order, $user): Order {
            $order = Order::query()->with('items.productVariant')->lockForUpdate()->findOrFail($order->id);
            if ($order->payments()->where('status', PaymentStatus::Completed->value)->exists()) {
                throw new DomainException('El pedido tiene pagos registrados. Revierte primero los pagos antes de cancelarlo.');
            }
            if ($order->status !== OrderStatus::Open) {
                throw new DomainException('Solo un pedido abierto puede cancelarse.');
            }
            foreach ($order->items->where('status', '!=', OrderItemStatus::Cancelled) as $item) {
                if (in_array($item->status, [OrderItemStatus::Draft, OrderItemStatus::Sent], true)) {
                    $this->release->execute($item, $item->quantity);
                }
                $item->forceFill([
                    'status' => OrderItemStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => $user->getKey(),
                ])->save();
            }
            $order->forceFill(['status' => OrderStatus::Cancelled, 'active_restaurant_table_id' => null, 'closed_at' => now()])->save();

            return $this->totals->recalculate($order);
        });
    }
}
