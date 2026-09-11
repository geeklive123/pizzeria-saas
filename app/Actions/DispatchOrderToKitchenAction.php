<?php

namespace App\Actions;

use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Enums\TableChargeMode;
use App\Models\KitchenDispatch;
use App\Models\KitchenDispatchItem;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderFinancialService;
use App\Services\OrderTotalsService;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class DispatchOrderToKitchenAction
{
    public function __construct(
        private readonly ConsumeInventoryReservationAction $consume,
        private readonly CompanyAccessService $access,
        private readonly OrderFinancialService $financials,
        private readonly OrderTotalsService $totals,
        private readonly NextOperationalOrderNumberAction $numbers,
    ) {}

    public function execute(Order $order, User $user, int|string|null $discountPercentage = null): ?KitchenDispatch
    {
        $this->access->ensure($user, $order->company, Permission::ManageOrders);

        return DB::transaction(function () use ($order, $user, $discountPercentage): ?KitchenDispatch {
            $order = Order::query()->with(['company', 'branch'])->lockForUpdate()->findOrFail($order->getKey());

            if (! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
                throw new DomainException('Solo un pedido activo puede enviarse a cocina.');
            }

            $items = $order->items()->where('status', OrderItemStatus::Draft->value)
                ->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                return null;
            }

            if ($order->kitchenDispatches()->where('status', KitchenDispatchStatus::AwaitingPayment->value)->exists()) {
                throw new DomainException('Existe una tanda pendiente de pago. Complétala antes de confirmar otra.');
            }

            $requiresPayment = $order->type === OrderType::Takeaway || $order->charge_mode === TableChargeMode::PerBatch;
            if (! $requiresPayment && filled($discountPercentage) && BigDecimal::of($discountPercentage)->isGreaterThan(0)) {
                throw new DomainException('En el modo cobrar al final, el descuento se define al solicitar la cuenta.');
            }
            if (filled($discountPercentage) && BigDecimal::of($discountPercentage)->isGreaterThan(0)) {
                $this->access->ensure($user, $order->company, Permission::ApplyOrderDiscounts);
            }

            if ($order->operational_number === null) {
                $order->forceFill([
                    'operational_number' => $this->numbers->execute($order->company, $order->branch),
                ])->save();
            }

            $sequence = ((int) $order->kitchenDispatches()->max('sequence_number')) + 1;
            $dispatch = KitchenDispatch::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'order_id' => $order->getKey(),
                'sequence_number' => $sequence,
                'status' => $requiresPayment ? KitchenDispatchStatus::AwaitingPayment : KitchenDispatchStatus::Released,
                'dispatched_at' => now(),
                'dispatched_by' => $user->getKey(),
                'released_at' => $requiresPayment ? null : now(),
            ]);

            foreach ($items as $item) {
                KitchenDispatchItem::query()->create([
                    'company_id' => $item->company_id,
                    'branch_id' => $item->branch_id,
                    'kitchen_dispatch_id' => $dispatch->getKey(),
                    'order_item_id' => $item->getKey(),
                ]);
                $status = $requiresPayment
                    ? OrderItemStatus::PendingPayment
                    : ($item->requires_preparation ? OrderItemStatus::Sent : OrderItemStatus::Ready);
                if (! $requiresPayment) {
                    $item->loadMissing(['company', 'order.branch', 'reservations.inventoryItem']);
                    $this->consume->execute($item, $user);
                }
                $item->forceFill([
                    'status' => $status,
                    'sent_at' => $requiresPayment ? null : now(),
                    'ready_at' => $status === OrderItemStatus::Ready ? now() : null,
                ])->save();
            }

            $dispatch->load('items.orderItem.sections');
            $this->financials->applyToDispatch($dispatch, $requiresPayment ? $discountPercentage : null);
            $this->totals->recalculate($order);

            return $dispatch->refresh()->load('items.orderItem');
        });
    }
}
