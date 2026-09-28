<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'promotion_id', 'category_id', 'size_key', 'quantity', 'allow_repeated_flavors', 'allow_fusions', 'allow_extras', 'allow_removals', 'sort_order'])]
class PromotionSelectionGroup extends Model
{
    use BelongsToCompany, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (PromotionSelectionGroup $group): void {
            $validPromotion = Promotion::query()->whereKey($group->promotion_id)
                ->where('company_id', $group->company_id)->exists();
            $validCategory = Category::query()->whereKey($group->category_id)
                ->where('company_id', $group->company_id)->exists();
            if (! $validPromotion || ! $validCategory || $group->quantity < 1) {
                throw new DomainException('El grupo seleccionable no es válido para la empresa activa.');
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
            'quantity' => 'integer',
            'allow_repeated_flavors' => 'boolean',
            'allow_fusions' => 'boolean',
            'allow_extras' => 'boolean',
            'allow_removals' => 'boolean',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
