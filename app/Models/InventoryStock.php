<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Brick\Math\BigDecimal;
use Database\Factories\InventoryStockFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'inventory_item_id', 'quantity', 'average_cost', 'minimum_quantity'])]
class InventoryStock extends Model
{
    /** @use HasFactory<InventoryStockFactory> */
    use BelongsToCompany, HasFactory;

    protected static function booted(): void
    {
        static::saving(function (InventoryStock $stock): void {
            $branchIsValid = Branch::query()
                ->whereKey($stock->branch_id)
                ->where('company_id', $stock->company_id)
                ->exists();
            $inventoryItemIsValid = InventoryItem::query()
                ->whereKey($stock->inventory_item_id)
                ->where('company_id', $stock->company_id)
                ->exists();

            if (! $branchIsValid || ! $inventoryItemIsValid) {
                throw new DomainException('The stock branch and inventory item must belong to the same company.');
            }

            if (! is_numeric($stock->quantity) || BigDecimal::of($stock->quantity)->isNegative()) {
                throw new DomainException('Inventory stock cannot be negative.');
            }

            if ($stock->minimum_quantity !== null
                && (! is_numeric($stock->minimum_quantity) || BigDecimal::of($stock->minimum_quantity)->isNegative())) {
                throw new DomainException('Minimum inventory stock cannot be negative.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'average_cost' => 'decimal:6',
            'minimum_quantity' => 'decimal:3',
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
}
