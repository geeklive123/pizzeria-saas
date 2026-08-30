<?php

namespace App\Actions;

use App\Enums\OrderItemStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Enums\TableChargeMode;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class FinalizePerBatchTableAction
{
    public function __construct(private readonly ClosePaidOrderAction $close, private readonly CompanyAccessService $access) {}

    public function execute(Order $order, User $user): Order
    {
        $this->access->ensure($user, $order->company, Permission::ManageOrders);

        return DB::transaction(function () use ($order): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->type !== OrderType::DineIn || $order->charge_mode !== TableChargeMode::PerBatch) {
                throw new DomainException('Esta acción solo está disponible para mesas con cobro por tanda.');
            }
            if (! $order->items()->exists() || $order->items()->whereIn('status', [OrderItemStatus::Draft->value, OrderItemStatus::PendingPayment->value])->exists()) {
                throw new DomainException('Confirma o elimina los productos pendientes antes de finalizar la mesa.');
            }

            return $this->close->executeLocked($order);
        });
    }
}
