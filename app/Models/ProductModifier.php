<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'product_id', 'name', 'is_active', 'sort_order'])]
class ProductModifier extends Model
{
    use BelongsToCompany, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (ProductModifier $modifier): void {
            if ($modifier->product_id && ! Product::query()->whereKey($modifier->product_id)
                ->where('company_id', $modifier->company_id)->exists()) {
                throw new DomainException('El modificador y el producto deben pertenecer a la misma empresa.');
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class);
    }
}
