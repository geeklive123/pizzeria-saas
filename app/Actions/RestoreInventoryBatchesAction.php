<?php

namespace App\Actions;

use App\Models\InventoryBatch;
use App\Models\InventoryStock;
use Brick\Math\BigDecimal;
use DomainException;

class RestoreInventoryBatchesAction
{
    /** @param list<array{batch_id:int, quantity:string}> $allocations */
    public function execute(InventoryStock $stock, array $allocations): void
    {
        foreach ($allocations as $allocation) {
            $batch = InventoryBatch::query()
                ->where('company_id', $stock->company_id)
                ->where('branch_id', $stock->branch_id)
                ->where('inventory_item_id', $stock->inventory_item_id)
                ->lockForUpdate()->findOrFail($allocation['batch_id']);
            $restored = BigDecimal::of($batch->quantity_remaining)->plus($allocation['quantity']);

            if ($restored->isGreaterThan($batch->quantity_received)) {
                throw new DomainException('A batch reversal cannot exceed its received quantity.');
            }

            $batch->forceFill(['quantity_remaining' => (string) $restored->toScale(3)])->save();
        }
    }
}
