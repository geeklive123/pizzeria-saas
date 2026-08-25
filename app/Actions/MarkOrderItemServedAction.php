<?php

namespace App\Actions;

use App\Enums\OrderItemStatus;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class MarkOrderItemServedAction
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly ClosePaidOrderAction $closeOrder,
    ) {}

    public function execute(OrderItem $item, User $user): OrderItem
    {
        $this->access->ensure($user, $item->company, Permission::ManageOrders);

        return DB::transaction(function () use ($item): OrderItem {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($item->getKey());
            if ($item->status === OrderItemStatus::Served) {
                return $item;
            }
            if ($item->status !== OrderItemStatus::Ready) {
                throw new DomainException('Solo un producto listo puede marcarse como servido.');
            }
            $item->forceFill(['status' => OrderItemStatus::Served, 'served_at' => now()])->save();
            $this->closeOrder->executeIfEligibleLocked($order);

            return $item->refresh();
        });
    }
}
