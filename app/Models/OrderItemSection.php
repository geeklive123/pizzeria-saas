<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'branch_id', 'order_item_id', 'product_variant_id', 'fraction_numerator', 'fraction_denominator', 'position', 'unit_price_snapshot', 'product_name_snapshot', 'variant_name_snapshot'])]
class OrderItemSection extends Model
{
    use BelongsToCompany;

    protected static function booted(): void
    {
        static::saving(function (OrderItemSection $section): void {
            if ($section->fraction_numerator < 1 || $section->fraction_denominator < 1 || $section->fraction_numerator > $section->fraction_denominator) {
                throw new DomainException('La fracción de una sección no es válida.');
            }

            $itemIsValid = OrderItem::query()->whereKey($section->order_item_id)
                ->where('company_id', $section->company_id)->where('branch_id', $section->branch_id)->exists();
            $variantIsValid = ProductVariant::query()->whereKey($section->product_variant_id)
                ->where('company_id', $section->company_id)->exists();

            if (! $itemIsValid || ! $variantIsValid) {
                throw new DomainException('La sección debe pertenecer al mismo contexto que el pedido y la variante.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'fraction_numerator' => 'integer',
            'fraction_denominator' => 'integer',
            'position' => 'integer',
            'unit_price_snapshot' => 'decimal:2',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }

    public function fractionLabel(): string
    {
        return "{$this->fraction_numerator}/{$this->fraction_denominator}";
    }
}
