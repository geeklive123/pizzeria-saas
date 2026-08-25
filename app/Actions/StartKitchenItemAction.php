<?php

namespace App\Actions;

use App\Enums\OrderItemStatus;
use App\Enums\Permission;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class StartKitchenItemAction
{
    public function __construct(private readonly ConsumeInventoryReservationAction $consume, private readonly CompanyAccessService $access) {}

    public function execute(OrderItem $item, User $user): OrderItem
    {
        $this->access->ensure($user, $item->company, Permission::ManageKitchen);

        return DB::transaction(function () use ($item, $user): OrderItem {
            $item = OrderItem::query()->with(['company', 'order.branch', 'reservations.inventoryItem'])
                ->lockForUpdate()->findOrFail($item->getKey());
            if (in_array($item->status, [OrderItemStatus::Preparing, OrderItemStatus::Ready, OrderItemStatus::Served], true)) {
                return $item;
            }
            if ($item->status !== OrderItemStatus::Sent || ! $item->requires_preparation) {
                throw new DomainException('Solo un producto enviado a cocina puede iniciar preparación.');
            }
            $this->consume->execute($item, $user);
            $item->forceFill(['status' => OrderItemStatus::Preparing, 'preparing_at' => now()])->save();

            return $item->refresh();
        });
    }
}
