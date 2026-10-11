<?php

namespace App\Models;

use App\Enums\InternalConsumptionReason;
use App\Enums\InternalConsumptionStatus;
use App\Models\Concerns\BelongsToCompany;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['company_id', 'branch_id', 'code', 'reason', 'notes', 'status', 'total_cost', 'cost_status', 'idempotency_key', 'created_by', 'confirmed_at', 'cancelled_at'])]
class InternalConsumption extends Model
{
    use BelongsToCompany, HasUlids;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'reason' => InternalConsumptionReason::class,
            'status' => InternalConsumptionStatus::class,
            'total_cost' => 'decimal:6',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InternalConsumptionItem::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(InternalConsumptionComponent::class);
    }

    public function cancellation(): HasOne
    {
        return $this->hasOne(InternalConsumptionCancellation::class);
    }

    public function printAttempts(): HasMany
    {
        return $this->hasMany(PrintAttempt::class);
    }

    public function pizzaQuantity(): string
    {
        return (string) $this->items->reduce(
            fn (BigDecimal $total, InternalConsumptionItem $item): BigDecimal => $total->plus($item->quantity),
            BigDecimal::zero(),
        )->toScale(3);
    }

    public function effectiveCostStatus(): string
    {
        $components = $this->relationLoaded('components')
            ? $this->components
            : $this->components()->get(['id', 'internal_consumption_id', 'cost_status']);

        if ($components->isEmpty()) {
            return $this->cost_status;
        }

        $known = $components->where('cost_status', 'known')->count();

        return match (true) {
            $known === $components->count() => 'known',
            $known === 0 => 'unknown',
            default => 'partial',
        };
    }
}
