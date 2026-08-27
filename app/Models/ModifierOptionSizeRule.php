<?php

namespace App\Models;

use App\Enums\ProductModifierPurpose;
use App\Models\Concerns\BelongsToCompany;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'modifier_option_id', 'size_key', 'price_delta', 'quantity'])]
class ModifierOptionSizeRule extends Model
{
    use BelongsToCompany, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (ModifierOptionSizeRule $rule): void {
            $validOption = ModifierOption::query()->whereKey($rule->modifier_option_id)
                ->where('company_id', $rule->company_id)
                ->whereHas('modifier', fn ($query) => $query->where('purpose', ProductModifierPurpose::ToppingCatalog))
                ->exists();

            if (! $validOption) {
                throw new DomainException('La regla de tamaño debe pertenecer a un topping de la misma empresa.');
            }
            if ($rule->price_delta === null && $rule->quantity === null) {
                throw new DomainException('La regla de tamaño debe definir precio o cantidad.');
            }
            if ($rule->price_delta !== null && BigDecimal::of($rule->price_delta)->isNegative()) {
                throw new DomainException('El precio por tamaño no puede ser negativo.');
            }
            if ($rule->quantity !== null && ! BigDecimal::of($rule->quantity)->isPositive()) {
                throw new DomainException('La cantidad por tamaño debe ser mayor que cero.');
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return ['price_delta' => 'decimal:2', 'quantity' => 'decimal:3'];
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ModifierOption::class, 'modifier_option_id');
    }
}
