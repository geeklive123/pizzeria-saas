<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Brick\Math\BigDecimal;
use Database\Factories\PreparationFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'output_inventory_item_id', 'unit_id', 'name', 'theoretical_yield', 'is_active'])]
class Preparation extends Model
{
    /** @use HasFactory<PreparationFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (Preparation $preparation): void {
            if (! is_numeric($preparation->theoretical_yield)
                || BigDecimal::of($preparation->theoretical_yield)->isLessThanOrEqualTo(0)) {
                throw new DomainException('El rendimiento teórico debe ser mayor que cero.');
            }

            $output = InventoryItem::query()->forCompany($preparation->company_id)
                ->whereKey($preparation->output_inventory_item_id)->first();

            if (! $output || (int) $output->unit_id !== (int) $preparation->unit_id || ! $output->ingredient_id) {
                throw new DomainException('La salida debe ser un ingrediente inventariable y usar su unidad base.');
            }
        });

        static::deleting(fn () => throw new DomainException('Las preparaciones no se eliminan; deben desactivarse.'));
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return ['theoretical_yield' => 'decimal:3', 'is_active' => 'boolean'];
    }

    public function outputInventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'output_inventory_item_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(PreparationComponent::class);
    }

    public function productions(): HasMany
    {
        return $this->hasMany(PreparationProduction::class);
    }
}
