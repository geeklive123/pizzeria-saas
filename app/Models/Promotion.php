<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PromotionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'product_variant_id', 'is_active', 'starts_at', 'ends_at'])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (Promotion $promotion): void {
            if (! ProductVariant::query()->whereKey($promotion->product_variant_id)
                ->where('company_id', $promotion->company_id)->exists()) {
                throw new DomainException('La promoción y su variante deben pertenecer a la misma empresa.');
            }
            if ($promotion->starts_at && $promotion->ends_at && $promotion->starts_at->isAfter($promotion->ends_at)) {
                throw new DomainException('La fecha final de la promoción debe ser posterior a la fecha inicial.');
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(PromotionComponent::class)->orderBy('inventory_item_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeCurrentlyActive(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where('is_active', true)
            ->where(fn (Builder $window) => $window->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $window) => $window->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
    }

    public function isCurrentlyActive(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return $this->is_active
            && ($this->starts_at === null || $this->starts_at <= $at)
            && ($this->ends_at === null || $this->ends_at >= $at);
    }
}
