<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PreparationProductionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'branch_id', 'preparation_id', 'lots', 'theoretical_yield', 'actual_yield',
    'yield_variance', 'total_cost', 'unit_cost', 'produced_at', 'created_by', 'reversed_at',
    'reversed_by', 'reversal_reason',
])]
class PreparationProduction extends Model
{
    /** @use HasFactory<PreparationProductionFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new DomainException('El historial de producción no se elimina.'));
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'theoretical_yield' => 'decimal:3',
            'actual_yield' => 'decimal:3',
            'yield_variance' => 'decimal:3',
            'total_cost' => 'decimal:6',
            'unit_cost' => 'decimal:6',
            'produced_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function preparation(): BelongsTo
    {
        return $this->belongsTo(Preparation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'reference_id')
            ->where('reference_type', self::class);
    }
}
