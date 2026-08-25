<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PurchaseItemFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'company_id',
    'purchase_id',
    'inventory_item_id',
    'quantity',
    'input_unit_id',
    'base_quantity',
    'unit_cost',
    'total_cost',
    'expires_at',
])]
class PurchaseItem extends Model
{
    /** @use HasFactory<PurchaseItemFactory> */
    use BelongsToCompany, HasFactory;

    protected static function booted(): void
    {
        static::saving(function (PurchaseItem $item): void {
            $purchase = Purchase::query()
                ->whereKey($item->purchase_id)
                ->where('company_id', $item->company_id)
                ->first();
            $inventoryItemIsValid = InventoryItem::query()
                ->whereKey($item->inventory_item_id)
                ->where('company_id', $item->company_id)
                ->exists();
            $inputUnitIsValid = Unit::query()
                ->whereKey($item->input_unit_id)
                ->where('company_id', $item->company_id)
                ->exists();

            if (! $purchase || ! $inventoryItemIsValid || ! $inputUnitIsValid) {
                throw new DomainException('Purchase items cannot mix companies.');
            }

            if ($purchase->status !== PurchaseStatus::Draft) {
                throw new DomainException('Items of a posted purchase are immutable.');
            }

            foreach (['quantity', 'base_quantity', 'unit_cost', 'total_cost'] as $attribute) {
                if (! is_numeric($item->{$attribute}) || $item->{$attribute} < 0) {
                    throw new DomainException('Purchase quantities and costs cannot be negative.');
                }
            }

            if ($item->quantity <= 0 || $item->base_quantity <= 0) {
                throw new DomainException('Purchase quantities must be greater than zero.');
            }
        });

        static::deleting(function (PurchaseItem $item): void {
            if ($item->purchase()->value('status') !== PurchaseStatus::Draft->value) {
                throw new DomainException('Items of a posted purchase are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'base_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:6',
            'total_cost' => 'decimal:6',
            'expires_at' => 'immutable_date',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function inputUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'input_unit_id');
    }

    public function inventoryBatch(): HasOne
    {
        return $this->hasOne(InventoryBatch::class);
    }
}
