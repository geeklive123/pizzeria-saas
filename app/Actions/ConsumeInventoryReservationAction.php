<?php

namespace App\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Models\InventoryReservation;
use App\Models\OrderItem;
use App\Models\User;

class ConsumeInventoryReservationAction
{
    public function __construct(private readonly ApplyInventoryMovementAction $movements) {}

    public function execute(OrderItem $item, User $user): void
    {
        $reservations = InventoryReservation::query()
            ->where('order_item_id', $item->getKey())
            ->where('status', InventoryReservationStatus::Reserved->value)
            ->orderBy('inventory_item_id')
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            $this->movements->execute(
                $item->company,
                $item->order->branch,
                $reservation->inventoryItem,
                InventoryMovementType::OrderConsumption,
                $reservation->quantity,
                null,
                $user,
                reason: "Consumo de pedido {$item->order->formattedOperationalNumber()}",
                referenceType: OrderItem::class,
                referenceId: $item->getKey(),
                metadata: ['inventory_reservation_id' => $reservation->getKey()],
            );

            $reservation->forceFill([
                'status' => InventoryReservationStatus::Consumed,
                'consumed_at' => now(),
            ])->save();
        }
    }
}
