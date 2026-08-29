<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'promotion_id', 'inventory_item_id', 'quantity'])]
class PromotionComponent extends Model
{
    use BelongsToCompany;

    protected static function booted(): void
    {
        static::saving(function (PromotionComponent $component): void {
            $validPromotion = Promotion::query()->whereKey($component->promotion_id)
                ->where('company_id', $component->company_id)->exists();
            $validInventory = InventoryItem::query()->whereKey($component->inventory_item_id)
                ->where('company_id', $component->company_id)->exists();
            if (! $validPromotion || ! $validInventory || BigDecimal::of($component->quantity)->isLessThanOrEqualTo(0)) {
                throw new DomainException('El componente de promoción no es válido para la empresa activa.');
            }
        });
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
