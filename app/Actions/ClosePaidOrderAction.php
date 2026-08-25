<?php

namespace App\Actions;

use App\Enums\InventoryReservationStatus;
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
            || $order->items()->whereNotIn('status', [OrderItemStatus::Served->value, OrderItemStatus::Cancelled->value])->exists()) {
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
        if ($order->items()->whereNotIn('status', [OrderItemStatus::Served->value, OrderItemStatus::Cancelled->value])->exists()) {
            throw new DomainException('Aún hay productos pendientes en cocina. Antes de finalizar el pedido, todos los productos deben estar servidos.');
        }

        return $this->close($order);
    }

    private function close(Order $order): Order
    {
        $order->forceFill([
            'status' => OrderStatus::Paid,
            'active_restaurant_table_id' => null,
            'closed_at' => now(),
        ])->save();

        return $order->refresh();
    }
}
