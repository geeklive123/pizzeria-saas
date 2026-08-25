<?php

namespace App\Models;

use App\Enums\CashClosingBalanceStatus;
use App\Enums\CashSessionStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['company_id', 'branch_id', 'cash_register_id', 'previous_cash_session_id', 'active_cash_register_id', 'opened_by', 'closed_by', 'opening_amount', 'inherited_cash_amount', 'opening_difference_amount', 'expected_cash_amount', 'counted_cash_amount', 'difference_amount', 'closing_balance_status', 'status', 'opened_at', 'closed_at', 'notes', 'closing_observation'])]
class CashSession extends Model
{
    use BelongsToCompany, HasUlids;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'opening_amount' => 'decimal:2',
            'inherited_cash_amount' => 'decimal:2',
            'opening_difference_amount' => 'decimal:2',
            'expected_cash_amount' => 'decimal:2',
            'counted_cash_amount' => 'decimal:2',
            'difference_amount' => 'decimal:2',
            'closing_balance_status' => CashClosingBalanceStatus::class,
            'status' => CashSessionStatus::class,
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CashSession $session): void {
            if ($session->getRawOriginal('status') === CashSessionStatus::Closed->value) {
                throw new LogicException('Closed cash sessions are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Cash sessions are historical and cannot be deleted.'));
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function previousSession(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_cash_session_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function scopeForBranch(Builder $query, Branch|int $branch): Builder
    {
        return $query->where('branch_id', $branch instanceof Branch ? $branch->getKey() : $branch);
    }
}
