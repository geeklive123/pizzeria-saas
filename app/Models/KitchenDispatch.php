<?php

namespace App\Models;

use App\Enums\KitchenDispatchStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'branch_id', 'order_id', 'sequence_number', 'status', 'dispatched_at', 'dispatched_by', 'gross_subtotal', 'pizza_base_subtotal', 'extras_subtotal', 'other_subtotal', 'discount_percentage', 'discount_total', 'total', 'financial_snapshot', 'released_at', 'settled_at'])]
class KitchenDispatch extends Model
{
    use BelongsToCompany, HasUlids;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'status' => KitchenDispatchStatus::class,
            'gross_subtotal' => 'decimal:2',
            'pizza_base_subtotal' => 'decimal:2',
            'extras_subtotal' => 'decimal:2',
            'other_subtotal' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
            'financial_snapshot' => 'array',
            'dispatched_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }

    public function scopeForBranch(Builder $query, Branch|int $branch): Builder
    {
        return $query->where('branch_id', $branch instanceof Branch ? $branch->getKey() : $branch);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(KitchenDispatchItem::class);
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function printAttempts(): HasMany
    {
        return $this->hasMany(PrintAttempt::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
