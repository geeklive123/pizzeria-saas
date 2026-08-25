<?php

namespace App\Actions;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Models\KitchenDispatch;
use App\Models\KitchenDispatchItem;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class DispatchOrderToKitchenAction
{
    public function __construct(
        private readonly ConsumeInventoryReservationAction $consume,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(Order $order, User $user): ?KitchenDispatch
    {
        $this->access->ensure($user, $order->company, Permission::ManageOrders);

        return DB::transaction(function () use ($order, $user): ?KitchenDispatch {
            $order = Order::query()->with(['company', 'branch'])->lockForUpdate()->findOrFail($order->getKey());

            if (! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
                throw new DomainException('Solo un pedido activo puede enviarse a cocina.');
            }

            $items = $order->items()->where('status', OrderItemStatus::Draft->value)
                ->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                return null;
            }

            $sequence = ((int) $order->kitchenDispatches()->max('sequence_number')) + 1;
            $dispatch = KitchenDispatch::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'order_id' => $order->getKey(),
                'sequence_number' => $sequence,
                'dispatched_at' => now(),
                'dispatched_by' => $user->getKey(),
            ]);

            foreach ($items as $item) {
                KitchenDispatchItem::query()->create([
                    'company_id' => $item->company_id,
                    'branch_id' => $item->branch_id,
                    'kitchen_dispatch_id' => $dispatch->getKey(),
                    'order_item_id' => $item->getKey(),
                ]);
                $status = $item->requires_preparation ? OrderItemStatus::Sent : OrderItemStatus::Ready;
                if (! $item->requires_preparation) {
                    $item->loadMissing(['company', 'order.branch', 'reservations.inventoryItem']);
                    $this->consume->execute($item, $user);
                }
                $item->forceFill([
                    'status' => $status,
                    'sent_at' => now(),
                    'ready_at' => $status === OrderItemStatus::Ready ? now() : null,
                ])->save();
            }

            return $dispatch->load('items.orderItem');
        });
    }
}
