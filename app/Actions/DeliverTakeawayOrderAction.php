<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\TakeawayDelivery;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeliverTakeawayOrderAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    public function execute(Order $order, User $user): TakeawayDelivery
    {
        $this->access->ensure($user, $order->company, Permission::ManageOrders);

        return DB::transaction(function () use ($order, $user): TakeawayDelivery {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $delivery = TakeawayDelivery::query()->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
                ->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            if ($order->type !== OrderType::Takeaway || $order->status !== OrderStatus::Paid) {
                throw new DomainException('Solo se puede entregar un pedido para llevar pagado.');
            }
            if (! $delivery->delivered_at) {
                $delivery->update(['delivered_at' => now(), 'delivered_by' => $user->id]);
            }

            return $delivery;
        }, 3);
    }
}
