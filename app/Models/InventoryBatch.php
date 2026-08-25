<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Brick\Math\BigDecimal;
use Database\Factories\InventoryBatchFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'branch_id',
    'inventory_item_id',
    'purchase_item_id',
    'inventory_movement_id',
    'transition_stock_id',
    'quantity_received',
    'quantity_remaining',
    'unit_cost',
    'received_at',
    'expires_at',
])]
class InventoryBatch extends Model
{
    /** @use HasFactory<InventoryBatchFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (InventoryBatch $batch): void {
            $received = BigDecimal::of($batch->quantity_received);
            $remaining = BigDecimal::of($batch->quantity_remaining);
            $cost = BigDecimal::of($batch->unit_cost);

            if ($received->isLessThanOrEqualTo(0)
                || $remaining->isNegative()
                || $remaining->isGreaterThan($received)
                || $cost->isNegative()) {
                throw new DomainException('Inventory batch quantities or cost are invalid.');
            }

            $branchIsValid = Branch::query()->whereKey($batch->branch_id)
                ->where('company_id', $batch->company_id)->exists();
            $itemIsValid = InventoryItem::query()->whereKey($batch->inventory_item_id)
                ->where('company_id', $batch->company_id)->exists();

            if (! $branchIsValid || ! $itemIsValid) {
                throw new DomainException('The inventory batch cannot mix companies or branches.');
            }

            if ($batch->purchase_item_id) {
                $purchaseItemIsValid = PurchaseItem::query()
                    ->whereKey($batch->purchase_item_id)
                    ->where('purchase_items.company_id', $batch->company_id)
                    ->where('purchase_items.inventory_item_id', $batch->inventory_item_id)
                    ->whereHas('purchase', fn ($query) => $query
                        ->where('branch_id', $batch->branch_id))
                    ->exists();

                if (! $purchaseItemIsValid) {
                    throw new DomainException('The batch purchase item is not valid for this inventory context.');
                }
            }

            if ($batch->inventory_movement_id && ! InventoryMovement::query()
                ->whereKey($batch->inventory_movement_id)
                ->where('company_id', $batch->company_id)
                ->where('branch_id', $batch->branch_id)
                ->where('inventory_item_id', $batch->inventory_item_id)
                ->exists()) {
                throw new DomainException('The batch movement is not valid for this inventory context.');
            }

            if ($batch->transition_stock_id && ! InventoryStock::query()
                ->whereKey($batch->transition_stock_id)
                ->where('company_id', $batch->company_id)
                ->where('branch_id', $batch->branch_id)
                ->where('inventory_item_id', $batch->inventory_item_id)
                ->exists()) {
                throw new DomainException('The transition stock is not valid for this inventory context.');
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:3',
            'quantity_remaining' => 'decimal:3',
            'unit_cost' => 'decimal:6',
            'received_at' => 'immutable_datetime',
            'expires_at' => 'immutable_date',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }

    public function transitionStock(): BelongsTo
    {
        return $this->belongsTo(InventoryStock::class, 'transition_stock_id');
    }
}
