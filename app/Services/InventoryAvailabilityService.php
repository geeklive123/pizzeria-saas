<?php

namespace App\Services;

use App\Data\InventoryAvailabilityResult;
use App\Enums\InventoryBatchStatus;
use App\Enums\InventoryReservationStatus;
use App\Models\InventoryBatch;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Collection;

class InventoryAvailabilityService
{
    public function __construct(private readonly InventoryBatchExpirationService $expiration) {}

    public function calculate(
        int|string $physicalQuantity,
        int|string $expiredQuantity = '0',
        int|string $reservedQuantity = '0',
    ): InventoryAvailabilityResult {
        $physical = BigDecimal::of($physicalQuantity)->toScale(3, RoundingMode::HalfUp);
        $expired = BigDecimal::of($expiredQuantity)->toScale(3, RoundingMode::HalfUp);
        $reserved = BigDecimal::of($reservedQuantity)->toScale(3, RoundingMode::HalfUp);

        if ($physical->isNegative() || $expired->isNegative() || $reserved->isNegative()) {
            throw new DomainException('Inventory availability quantities cannot be negative.');
        }

        $available = $physical->minus($expired)->minus($reserved);

        if ($available->isNegative()) {
            throw new DomainException('Expired and reserved quantities cannot exceed physical inventory.');
        }

        return new InventoryAvailabilityResult(
            (string) $physical,
            (string) $expired,
            (string) $reserved,
            (string) $available,
        );
    }

    /** @param Collection<int, InventoryBatch>|null $batches */
    public function forStock(
        ?InventoryStock $stock,
        ?Collection $batches = null,
        int|string|null $reservedQuantity = null,
        ?DateTimeInterface $today = null,
    ): InventoryAvailabilityResult {
        if (! $stock) {
            return $this->calculate('0', '0', $reservedQuantity ?? '0');
        }

        $batches ??= InventoryBatch::query()
            ->where('company_id', $stock->company_id)
            ->where('branch_id', $stock->branch_id)
            ->where('inventory_item_id', $stock->inventory_item_id)
            ->where('quantity_remaining', '>', 0)
            ->get();

        $expired = $batches->reduce(
            fn (BigDecimal $total, InventoryBatch $batch): BigDecimal => $this->expiration->status($batch, $today) === InventoryBatchStatus::Expired
                    ? $total->plus($batch->quantity_remaining)
                    : $total,
            BigDecimal::zero(),
        );

        $reservedQuantity ??= (string) InventoryReservation::query()
            ->where('company_id', $stock->company_id)
            ->where('branch_id', $stock->branch_id)
            ->where('inventory_item_id', $stock->inventory_item_id)
            ->where('status', InventoryReservationStatus::Reserved->value)
            ->get()
            ->reduce(
                fn (BigDecimal $total, InventoryReservation $reservation): BigDecimal => $total->plus($reservation->quantity),
                BigDecimal::zero(),
            );

        return $this->calculate($stock->quantity, (string) $expired, $reservedQuantity);
    }

    /** @param Collection<int, InventoryReservation> $reservations */
    public function reservedQuantity(Collection $reservations): string
    {
        return (string) $reservations->reduce(
            fn (BigDecimal $total, InventoryReservation $reservation): BigDecimal => $total->plus($reservation->quantity),
            BigDecimal::zero(),
        )->toScale(3, RoundingMode::HalfUp);
    }
}
