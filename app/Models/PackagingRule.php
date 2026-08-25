<?php

namespace App\Models;

use App\Enums\OrderType;
use App\Models\Concerns\BelongsToCompany;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'size_key', 'fulfillment_type', 'inventory_item_id', 'quantity'])]
class PackagingRule extends Model
{
    use BelongsToCompany, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (PackagingRule $rule): void {
            if (blank($rule->size_key) || ! is_numeric($rule->quantity) || BigDecimal::of($rule->quantity)->isLessThanOrEqualTo(0)) {
                throw new DomainException('La regla de packaging debe tener tamaño y cantidad positiva.');
            }
            if (! InventoryItem::query()->whereKey($rule->inventory_item_id)
                ->where('company_id', $rule->company_id)->where('is_active', true)->exists()) {
                throw new DomainException('El packaging debe pertenecer a la misma empresa y estar activo.');
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return ['fulfillment_type' => OrderType::class, 'quantity' => 'decimal:3'];
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
