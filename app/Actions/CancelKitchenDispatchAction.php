<?php

namespace App\Actions;

use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderCancellationScope;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\OrderCancellationAudit;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelKitchenDispatchAction
{
    public function __construct(
        private readonly CancelKitchenDispatchItemAction $cancelItem,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(KitchenDispatch $dispatch, User $user, string $reason): KitchenDispatch
    {
        $this->access->ensure($user, $dispatch->company, Permission::CancelOrders);
        if (blank($reason)) {
            throw new DomainException('Indica el motivo de la anulación.');
        }

        return DB::transaction(function () use ($dispatch, $user, $reason): KitchenDispatch {
            $order = Order::query()->lockForUpdate()->findOrFail($dispatch->order_id);
            if ($order->status === OrderStatus::Paid) {
                throw new DomainException('Este pedido ya fue pagado. Para modificar productos debe utilizar un flujo de reversión/reembolso.');
            }
            if (! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
                throw new DomainException('Este pedido no permite anulación parcial.');
            }

            $dispatch = KitchenDispatch::query()->lockForUpdate()->findOrFail($dispatch->getKey());
            if ($dispatch->status === KitchenDispatchStatus::Cancelled) {
                throw new DomainException('Esta tanda ya fue anulada.');
            }

            $itemIds = $dispatch->items()->orderBy('order_item_id')->pluck('order_item_id');
            $items = OrderItem::query()->whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get();
            $activeItems = $items->where('status', '!=', OrderItemStatus::Cancelled);
            if ($activeItems->isEmpty()) {
                throw new DomainException('Esta tanda no tiene productos activos para anular.');
            }

            $audit = OrderCancellationAudit::query()->create([
                'company_id' => $dispatch->company_id,
                'branch_id' => $dispatch->branch_id,
                'order_id' => $order->getKey(),
                'kitchen_dispatch_id' => $dispatch->getKey(),
                'scope' => OrderCancellationScope::KitchenDispatch,
                'snapshot' => ['previous_status' => $dispatch->status->value],
                'cancelled_at' => now(),
                'cancelled_by' => $user->getKey(),
                'cancellation_reason' => $reason,
            ]);

            foreach ($activeItems as $item) {
                $this->cancelItem->execute($item, $user, $reason, $audit);
            }

            $dispatch->refresh()->forceFill([
                'status' => KitchenDispatchStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $user->getKey(),
                'cancellation_reason' => $reason,
            ])->save();

            return $dispatch->refresh();
        }, attempts: 3);
    }
}
