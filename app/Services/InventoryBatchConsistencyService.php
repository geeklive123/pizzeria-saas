<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\InventoryStock;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Collection;

class InventoryBatchConsistencyService
{
    /** @return Collection<int, InventoryBatch> */
    public function ensureCoverage(InventoryStock $stock): Collection
    {
        $batches = $this->lockedBatches($stock);
        $batchQuantity = $this->sum($batches);
        $stockQuantity = BigDecimal::of($stock->quantity);

        if ($batches->isEmpty() && $stockQuantity->isGreaterThan(0)) {
            InventoryBatch::query()->create([
                'company_id' => $stock->company_id,
                'branch_id' => $stock->branch_id,
                'inventory_item_id' => $stock->inventory_item_id,
                'transition_stock_id' => $stock->getKey(),
                'quantity_received' => $stock->quantity,
                'quantity_remaining' => $stock->quantity,
                'unit_cost' => $stock->average_cost,
                'received_at' => $stock->updated_at ?? now(),
                'expires_at' => null,
            ]);

            return $this->lockedBatches($stock);
        }

        if (! $batchQuantity->isEqualTo($stockQuantity)) {
            throw new DomainException('Inventory stock and batch quantities are inconsistent.');
        }

        return $batches;
    }

    public function assertMatches(InventoryStock $stock): void
    {
        if (! $this->sum($this->lockedBatches($stock))->isEqualTo($stock->quantity)) {
            throw new DomainException('Inventory stock and batch quantities are inconsistent.');
        }
    }

    /** @return Collection<int, InventoryBatch> */
    private function lockedBatches(InventoryStock $stock): Collection
    {
        return InventoryBatch::query()
            ->where('company_id', $stock->company_id)
            ->where('branch_id', $stock->branch_id)
            ->where('inventory_item_id', $stock->inventory_item_id)
            ->orderBy('id')->lockForUpdate()->get();
    }

    private function sum(Collection $batches): BigDecimal
    {
        return $batches->reduce(
            fn (BigDecimal $total, InventoryBatch $batch): BigDecimal => $total->plus($batch->quantity_remaining),
            BigDecimal::zero(),
        );
    }
}
