<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\InventoryItemFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id',
    'unit_id',
    'ingredient_id',
    'product_variant_id',
    'name',
    'is_active',
])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (InventoryItem $item): void {
            $hasIngredient = $item->ingredient_id !== null;
            $hasProductVariant = $item->product_variant_id !== null;

            if ($hasIngredient === $hasProductVariant) {
                throw new DomainException('An inventory item must represent exactly one ingredient or product variant.');
            }

            $unitIsValid = Unit::query()
                ->whereKey($item->unit_id)
                ->where('company_id', $item->company_id)
                ->exists();

            if (! $unitIsValid) {
                throw new DomainException('The inventory unit must belong to the same company.');
            }

            if ($hasIngredient) {
                $ingredient = Ingredient::query()
                    ->whereKey($item->ingredient_id)
                    ->where('company_id', $item->company_id)
                    ->first();

                if (! $ingredient || (int) $ingredient->unit_id !== (int) $item->unit_id) {
                    throw new DomainException(
                        'An ingredient inventory item must use the ingredient base unit and company.',
                    );
                }
            }

            if ($hasProductVariant && ! ProductVariant::query()
                ->whereKey($item->product_variant_id)
                ->where('company_id', $item->company_id)
                ->exists()) {
                throw new DomainException('The product variant must belong to the same company.');
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function inventoryStocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function inventoryBatches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class);
    }

    public function inventoryReservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }
}
