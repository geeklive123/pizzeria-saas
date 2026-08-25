<?php

namespace App\Actions;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class MarkOrderReadyForPaymentAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    public function execute(Order $order, User $user): Order
    {
        $this->access->ensure($user, $order->company, Permission::ManageOrders);

        return DB::transaction(function () use ($order): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status === OrderStatus::ReadyForPayment) {
                return $order;
            }
            if ($order->status !== OrderStatus::Open || ! $order->items()->exists()) {
                throw new DomainException('Solo un pedido abierto con productos puede solicitar la cuenta.');
            }
            if ($order->items()->whereNotIn('status', [OrderItemStatus::Served->value, OrderItemStatus::Cancelled->value])->exists()) {
                throw new DomainException('Aún hay productos pendientes en cocina. Antes de solicitar la cuenta, todos deben estar servidos.');
            }
            $order->forceFill(['status' => OrderStatus::ReadyForPayment])->save();

            return $order->refresh();
        });
    }
}
