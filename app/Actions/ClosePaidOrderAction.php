<?php

namespace App\Actions;

use App\Enums\InventoryReservationStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderPaymentService;
use Brick\Math\BigDecimal;
use DomainException;

class ClosePaidOrderAction
{
    public function __construct(private readonly OrderPaymentService $payments) {}

    public function executeIfEligibleLocked(Order $order): Order
    {
        if ($order->status === OrderStatus::Paid) {
            return $order;
        }
        if (! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)
            || ! BigDecimal::of($this->payments->balance($order))->isZero()
            || $order->reservations()->where('status', InventoryReservationStatus::Reserved->value)->exists()
            || $this->hasPendingSalesWork($order)) {
            return $order;
        }

        return $this->close($order);
    }

    public function executeLocked(Order $order): Order
    {
        if ($order->status === OrderStatus::Paid) {
            return $order;
        }
        if (! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
            throw new DomainException('Este pedido no puede finalizarse como pagado.');
        }
        if (! BigDecimal::of($this->payments->balance($order))->isZero()) {
            throw new DomainException('El pedido todavía tiene saldo pendiente.');
        }
        if ($order->reservations()->where('status', InventoryReservationStatus::Reserved->value)->exists()) {
            throw new DomainException('El pedido todavía tiene reservas activas de inventario.');
        }
        if ($this->hasPendingSalesWork($order)) {
            throw new DomainException('Aún existen productos o tandas pendientes de confirmar.');
        }

        return $this->close($order);
    }

    private function hasPendingSalesWork(Order $order): bool
    {
        return $order->items()->whereIn('status', [OrderItemStatus::Draft->value, OrderItemStatus::PendingPayment->value])->exists()
            || $order->kitchenDispatches()->where('status', KitchenDispatchStatus::AwaitingPayment->value)->exists();
    }

    private function close(Order $order): Order
    {
        $order->kitchenDispatches()->where('status', KitchenDispatchStatus::Released->value)->update([
            'status' => KitchenDispatchStatus::Settled->value,
            'settled_at' => now(),
        ]);
        $order->forceFill(['status' => OrderStatus::Paid, 'active_restaurant_table_id' => null, 'closed_at' => now()])->save();

        return $order->refresh();
    }
}
