<?php

namespace App\Actions;

use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderPaymentService;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class SettleKitchenDispatchAction
{
    public function __construct(
        private readonly ConsumeInventoryReservationAction $consume,
        private readonly OrderPaymentService $payments,
    ) {}

    public function execute(KitchenDispatch $dispatch, User $user): KitchenDispatch
    {
        return DB::transaction(function () use ($dispatch, $user): KitchenDispatch {
            $dispatch = KitchenDispatch::query()->with(['order.branch', 'items.orderItem.company', 'items.orderItem.reservations.inventoryItem'])
                ->lockForUpdate()->findOrFail($dispatch->id);
            if ($dispatch->status === KitchenDispatchStatus::Settled) {
                return $dispatch;
            }
            if (! in_array($dispatch->status, [KitchenDispatchStatus::AwaitingPayment, KitchenDispatchStatus::Released], true)) {
                throw new DomainException('La tanda no está pendiente de liquidación.');
            }
            if (! BigDecimal::of($this->payments->dispatchBalance($dispatch))->isZero()) {
                throw new DomainException('La tanda todavía tiene saldo pendiente.');
            }

            foreach ($dispatch->items as $dispatchItem) {
                $item = $dispatchItem->orderItem;
                if ($item->status !== OrderItemStatus::PendingPayment) {
                    continue;
                }
                $this->consume->execute($item, $user);
                $status = $item->requires_preparation ? OrderItemStatus::Sent : OrderItemStatus::Ready;
                $item->forceFill(['status' => $status, 'sent_at' => now(), 'ready_at' => $status === OrderItemStatus::Ready ? now() : null])->save();
            }
            $dispatch->forceFill(['status' => KitchenDispatchStatus::Settled, 'released_at' => now(), 'settled_at' => now()])->save();

            $order = Order::query()->lockForUpdate()->findOrFail($dispatch->order_id);
            if ($order->type === OrderType::Takeaway
                && BigDecimal::of($this->payments->balance($order))->isZero()
                && ! $order->items()->whereIn('status', [OrderItemStatus::Draft->value, OrderItemStatus::PendingPayment->value])->exists()
                && ! $order->reservations()->where('status', 'reserved')->exists()) {
                $order->forceFill(['status' => OrderStatus::Paid, 'active_restaurant_table_id' => null, 'closed_at' => now()])->save();
            } elseif ($order->status === OrderStatus::ReadyForPayment) {
                $order->forceFill(['status' => OrderStatus::Open])->save();
            }

            return $dispatch->refresh()->load('order');
        });
    }
}
