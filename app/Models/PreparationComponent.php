<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Brick\Math\BigDecimal;
use Database\Factories\PreparationComponentFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'preparation_id', 'inventory_item_id', 'quantity'])]
class PreparationComponent extends Model
{
    /** @use HasFactory<PreparationComponentFactory> */
    use BelongsToCompany, HasFactory;

    protected static function booted(): void
    {
        static::saving(function (PreparationComponent $component): void {
            if (! is_numeric($component->quantity) || BigDecimal::of($component->quantity)->isLessThanOrEqualTo(0)) {
                throw new DomainException('La cantidad de un componente debe ser mayor que cero.');
            }

            $preparation = Preparation::query()->forCompany($component->company_id)
                ->whereKey($component->preparation_id)->first();
            $item = InventoryItem::query()->forCompany($component->company_id)
                ->whereKey($component->inventory_item_id)->first();

            if (! $preparation || ! $item || ! $item->ingredient_id
                || (int) $preparation->output_inventory_item_id === (int) $item->getKey()) {
                throw new DomainException('El componente no es válido para esta preparación.');
            }
        });
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function preparation(): BelongsTo
    {
        return $this->belongsTo(Preparation::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
