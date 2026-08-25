<?php

namespace App\Services;

use App\Enums\InventoryReservationStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryItem;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

class InventoryOverviewService
{
    public function __construct(
        private readonly InventoryStockStatusService $stockStatus,
        private readonly InventoryBatchExpirationService $expiration,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    /** @return Collection<int, InventoryItem> */
    public function items(Company $company, Branch $branch): Collection
    {
        return InventoryItem::query()->forCompany($company)
            ->with([
                'unit',
                'ingredient',
                'productVariant.product',
                'inventoryStocks' => fn ($query) => $query->where('branch_id', $branch->getKey()),
                'inventoryMovements' => fn ($query) => $query->where('branch_id', $branch->getKey())->latest('occurred_at')->limit(1),
                'inventoryBatches' => fn ($query) => $query->where('branch_id', $branch->getKey())
                    ->where('quantity_remaining', '>', 0)
                    ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('expires_at')->orderBy('received_at')->orderBy('id'),
                'inventoryReservations' => fn ($query) => $query->where('branch_id', $branch->getKey())
                    ->where('status', InventoryReservationStatus::Reserved->value),
            ])
            ->orderBy('name')
            ->get()
            ->each(fn (InventoryItem $item) => $this->decorate($item));
    }

    public function decorate(InventoryItem $item): InventoryItem
    {
        $stock = $item->inventoryStocks->first();
        $quantity = $stock?->quantity ?? '0.000';
        $averageCost = $stock?->average_cost ?? '0.000000';
        $reserved = $this->availability->reservedQuantity($item->inventoryReservations);
        $availability = $this->availability->forStock($stock, $item->inventoryBatches, $reserved);
        $item->setAttribute('display_quantity', $quantity);
        $item->setAttribute('physical_quantity', $availability->physicalQuantity);
        $item->setAttribute('expired_quantity', $availability->expiredQuantity);
        $item->setAttribute('reserved_quantity', $availability->reservedQuantity);
        $item->setAttribute('available_quantity', $availability->availableQuantity);
        $item->setAttribute('display_average_cost', $averageCost);
        $item->setAttribute('display_value', (string) BigDecimal::of($quantity)->multipliedBy($averageCost));
        $item->setAttribute('stock_status', $this->stockStatus->status(
            $quantity,
            $stock?->minimum_quantity,
            expiredQuantity: $availability->expiredQuantity,
        ));
        $item->setRelation('lastMovement', $item->inventoryMovements->first());
        $nextBatch = $item->inventoryBatches->filter(fn ($batch) => $batch->expires_at !== null
            && BigDecimal::of($batch->quantity_remaining)->isGreaterThan(0))
            ->sortBy('expires_at')->first();
        $item->setRelation('nextExpiringBatch', $nextBatch);
        $item->setAttribute('next_expiration_at', $nextBatch?->expires_at);
        $item->setAttribute('expiration_description', match (true) {
            $nextBatch !== null => null,
            $item->inventoryBatches->isEmpty() => 'Sin vencimiento próximo',
            default => 'Sin caducidad',
        });
        $item->setAttribute('expiration_status', $nextBatch ? $this->expiration->status($nextBatch) : null);

        $item->inventoryBatches->each(fn ($batch) => $batch->setAttribute(
            'expiration_status',
            $this->expiration->status($batch),
        ));

        return $item;
    }

    public function totalValue(Collection $items): string
    {
        return (string) $items->reduce(
            fn (BigDecimal $total, InventoryItem $item): BigDecimal => $total->plus($item->display_value),
            BigDecimal::zero(),
        );
    }
}
