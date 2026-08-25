<?php

namespace App\Actions;

use App\Enums\InventoryReservationStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\OrderItem;
use App\Services\InventoryAvailabilityService;
use App\Services\OrderReservationRequirementsService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class ReserveInventoryForOrderItemAction
{
    public function __construct(
        private readonly OrderReservationRequirementsService $requirements,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    public function execute(OrderItem $orderItem, int|string $additionalQuantity): void
    {
        foreach ($this->requirements->forOrderItem($orderItem, $additionalQuantity) as $requirement) {
            $inventoryItem = $requirement['inventory_item'];
            $stock = InventoryStock::query()->where('company_id', $orderItem->company_id)
                ->where('branch_id', $orderItem->branch_id)
                ->where('inventory_item_id', $inventoryItem->getKey())
                ->lockForUpdate()->first();

            $reserved = InventoryReservation::query()
                ->where('company_id', $orderItem->company_id)
                ->where('branch_id', $orderItem->branch_id)
                ->where('inventory_item_id', $inventoryItem->getKey())
                ->where('status', InventoryReservationStatus::Reserved->value)
                ->orderBy('id')->lockForUpdate()->get()
                ->reduce(fn (BigDecimal $total, InventoryReservation $reservation): BigDecimal => $total->plus($reservation->quantity), BigDecimal::zero());
            $availability = $this->availability->forStock($stock, reservedQuantity: (string) $reserved);
            $required = BigDecimal::of($requirement['quantity']);

            if (BigDecimal::of($availability->availableQuantity)->isLessThan($required)) {
                throw new InsufficientStockException("No disponible: falta {$inventoryItem->name}.");
            }

            $reservation = InventoryReservation::query()->firstOrNew([
                'order_item_id' => $orderItem->getKey(),
                'inventory_item_id' => $inventoryItem->getKey(),
            ]);
            $current = $reservation->exists && $reservation->status === InventoryReservationStatus::Reserved
                ? BigDecimal::of($reservation->quantity) : BigDecimal::zero();
            $reservation->fill([
                'company_id' => $orderItem->company_id,
                'branch_id' => $orderItem->branch_id,
                'order_id' => $orderItem->order_id,
                'quantity' => (string) $current->plus($required)->toScale(3, RoundingMode::HalfUp),
                'status' => InventoryReservationStatus::Reserved,
                'reserved_at' => now(),
                'released_at' => null,
                'consumed_at' => null,
            ])->save();
        }
    }
}
