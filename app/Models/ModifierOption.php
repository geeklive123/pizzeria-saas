<?php

namespace App\Models;

use App\Enums\ModifierOptionType;
use App\Models\Concerns\BelongsToCompany;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'product_modifier_id', 'name', 'type', 'price_delta', 'inventory_item_id', 'ingredient_id', 'quantity', 'unit_id', 'is_active', 'sort_order'])]
class ModifierOption extends Model
{
    use BelongsToCompany, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (ModifierOption $option): void {
            if (BigDecimal::of($option->price_delta ?? '0')->isNegative()) {
                throw new DomainException('El ajuste de precio no puede ser negativo.');
            }

            if ($option->type === ModifierOptionType::Add && (! $option->inventory_item_id || ! $option->quantity || ! $option->unit_id)) {
                throw new DomainException('Un extra debe definir artículo, cantidad y unidad de inventario.');
            }

            if ($option->type === ModifierOptionType::Remove && ! $option->ingredient_id && ! $option->inventory_item_id) {
                throw new DomainException('Una remoción debe identificar el ingrediente que descuenta.');
            }

            if (! ProductModifier::query()->whereKey($option->product_modifier_id)
                ->where('company_id', $option->company_id)->exists()) {
                throw new DomainException('La opción y el modificador deben pertenecer a la misma empresa.');
            }

            $inventoryItem = $option->inventory_item_id
                ? InventoryItem::query()->whereKey($option->inventory_item_id)->where('company_id', $option->company_id)->first()
                : null;
            if ($option->inventory_item_id && ! $inventoryItem) {
                throw new DomainException('El artículo del modificador debe pertenecer a la misma empresa.');
            }
            if ($option->unit_id && (! $inventoryItem || (int) $inventoryItem->unit_id !== (int) $option->unit_id)) {
                throw new DomainException('La cantidad del modificador debe usar la unidad del artículo de inventario.');
            }
            if ($option->ingredient_id && (! $inventoryItem || (int) $inventoryItem->ingredient_id !== (int) $option->ingredient_id)) {
                throw new DomainException('El ingrediente del modificador no coincide con su artículo de inventario.');
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
            'type' => ModifierOptionType::class,
            'price_delta' => 'decimal:2',
            'quantity' => 'decimal:3',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(ProductModifier::class, 'product_modifier_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
