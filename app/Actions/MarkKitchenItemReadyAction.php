<?php

namespace App\Actions;

use App\Enums\OrderItemStatus;
use App\Enums\Permission;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class MarkKitchenItemReadyAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    public function execute(OrderItem $item, User $user): OrderItem
    {
        $this->access->ensure($user, $item->company, Permission::ManageKitchen);

        return DB::transaction(function () use ($item): OrderItem {
            $item = OrderItem::query()->lockForUpdate()->findOrFail($item->getKey());
            if (in_array($item->status, [OrderItemStatus::Ready, OrderItemStatus::Served], true)) {
                return $item;
            }
            if ($item->status !== OrderItemStatus::Preparing) {
                throw new DomainException('Solo un producto en preparación puede marcarse como listo.');
            }
            $item->forceFill(['status' => OrderItemStatus::Ready, 'ready_at' => now()])->save();

            return $item->refresh();
        });
    }
}
