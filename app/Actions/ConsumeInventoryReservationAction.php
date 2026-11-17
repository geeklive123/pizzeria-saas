<?php

namespace App\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Models\InventoryReservation;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\OnlineOrderActionContext;

class ConsumeInventoryReservationAction
{
    public function __construct(private readonly ApplyInventoryMovementAction $movements) {}

    public function execute(OrderItem $item, User $user, ?OnlineOrderActionContext $online = null): void
    {
        $online?->assertConfirmedOrder($item->order, $user);
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
                online: $online,
            );

            $reservation->forceFill([
                'status' => InventoryReservationStatus::Consumed,
                'consumed_at' => now(),
            ])->save();
        }
    }
}
