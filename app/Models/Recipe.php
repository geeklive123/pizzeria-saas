<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\RecipeFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'product_variant_id', 'name', 'is_active'])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (Recipe $recipe): void {
            if (! ProductVariant::query()
                ->whereKey($recipe->product_variant_id)
                ->where('company_id', $recipe->company_id)
                ->exists()) {
                throw new DomainException('The variant and recipe must belong to the same company.');
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

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }
}
