<?php

namespace App\Actions;

use App\Enums\InventoryReservationStatus;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\OrderItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class ReleaseInventoryReservationAction
{
    public function execute(OrderItem $orderItem, int|string $quantityToRelease): void
    {
        $itemQuantity = BigDecimal::of($orderItem->quantity);
        $releaseQuantity = BigDecimal::of($quantityToRelease);

        if ($releaseQuantity->isLessThanOrEqualTo(0) || $releaseQuantity->isGreaterThan($itemQuantity)) {
            throw new DomainException('The inventory release quantity is invalid.');
        }

        $reservations = InventoryReservation::query()
            ->where('order_item_id', $orderItem->getKey())
            ->where('status', InventoryReservationStatus::Reserved->value)
            ->orderBy('inventory_item_id')
            ->get();

        foreach ($reservations as $knownReservation) {
            InventoryStock::query()->where('company_id', $orderItem->company_id)
                ->where('branch_id', $orderItem->branch_id)
                ->where('inventory_item_id', $knownReservation->inventory_item_id)
                ->lockForUpdate()->first();
            $reservation = InventoryReservation::query()
                ->whereKey($knownReservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $reserved = BigDecimal::of($reservation->quantity);
            $released = $releaseQuantity->isEqualTo($itemQuantity)
                ? $reserved
                : $reserved->multipliedBy($releaseQuantity)
                    ->dividedBy($itemQuantity, 3, RoundingMode::HalfUp);
            $remaining = $reserved->minus($released);

            if ($remaining->isNegative()) {
                throw new DomainException('Cannot release more inventory than the order item reserved.');
            }

            $reservation->forceFill([
                'quantity' => (string) $remaining->toScale(3, RoundingMode::HalfUp),
                'status' => $remaining->isZero() ? InventoryReservationStatus::Released : InventoryReservationStatus::Reserved,
                'released_at' => $remaining->isZero() ? now() : null,
            ])->save();
        }
    }
}
