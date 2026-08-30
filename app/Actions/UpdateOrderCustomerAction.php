<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateOrderCustomerAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    public function execute(Order $order, User $user, ?string $customerName): Order
    {
        $this->access->ensure($user, $order->company, Permission::ManageOrders);

        return DB::transaction(function () use ($order, $customerName): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
                throw new DomainException('El cliente solo puede actualizarse en una cuenta activa.');
            }
            $order->forceFill(['customer_name' => blank($customerName) ? null : trim($customerName)])->save();

            return $order->refresh();
        });
    }
}
