<?php

namespace App\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\CashSession;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CancelPaidOrderAction
{
    public function __construct(
        private readonly ReversePaymentAction $reversePayment,
        private readonly ReverseInventoryMovementAction $reverseInventory,
    ) {}

    public function execute(Order $order, User $user, string $reason): Order
    {
        Gate::forUser($user)->authorize('cancelPaid', $order);
        if (blank($reason) || mb_strlen($reason) > 500) {
            throw new DomainException('Indica un motivo de anulación de hasta 500 caracteres.');
        }

        return DB::transaction(function () use ($order, $user, $reason): Order {
            // Match payment registration: cash sessions before the order, in stable order.
            CashSession::query()->whereIn('id', $order->payments()->select('cash_session_id'))
                ->orderBy('id')->lockForUpdate()->get();
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== OrderStatus::Paid) {
                throw new DomainException('Solo se pueden anular y revertir pedidos pagados.');
            }
            if ($order->reservations()->where('status', InventoryReservationStatus::Reserved->value)->exists()) {
                throw new DomainException('El pedido pagado conserva reservas pendientes; revisa su estado operativo.');
            }

            $payments = $order->payments()->where('status', PaymentStatus::Completed->value)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($payments as $payment) {
                $this->reversePayment->execute($payment, $reason, $user, forPaidCancellation: true);
            }

            $items = $order->items()->orderBy('id')->lockForUpdate()->get();
            $movements = InventoryMovement::query()->forCompany($order->company_id)
                ->where('branch_id', $order->branch_id)
                ->where('reference_type', OrderItem::class)
                ->whereIn('reference_id', $items->modelKeys())
                ->where('type', InventoryMovementType::OrderConsumption->value)
                ->whereDoesntHave('reversals')
                ->orderBy('inventory_item_id')->orderBy('id')->lockForUpdate()->get();
            foreach ($movements as $movement) {
                $this->reverseInventory->execute($movement, $user, $reason, requiredPermission: Permission::CancelOrders);
            }

            $cancelledAt = now();
            foreach ($items->where('status', '!=', OrderItemStatus::Cancelled) as $item) {
                $item->forceFill([
                    'status' => OrderItemStatus::Cancelled,
                    'cancelled_at' => $cancelledAt,
                    'cancelled_by' => $user->id,
                    'cancellation_reason' => $reason,
                ])->save();
            }
            $order->kitchenDispatches()->update(['status' => KitchenDispatchStatus::Cancelled->value]);
            // Keep the original sale totals, snapshots, closing date and operational number.
            $order->forceFill([
                'status' => OrderStatus::Cancelled,
                'active_restaurant_table_id' => null,
                'cancellation_reason' => $reason,
                'cancelled_by' => $user->id,
                'cancelled_at' => $cancelledAt,
            ])->save();

            return $order->refresh();
        }, attempts: 3);
    }
}
