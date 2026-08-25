<?php

namespace App\Models;

use App\Enums\ExpenseDocumentType;
use App\Enums\ExpensePaymentMethod;
use App\Enums\ExpenseStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ExpenseFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['company_id', 'branch_id', 'expense_category_id', 'supplier_id', 'description', 'amount', 'expense_date', 'document_type', 'document_number', 'payment_method', 'cash_session_id', 'status', 'notes', 'created_by', 'approved_by', 'reversal_of_id'])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'immutable_date',
            'document_type' => ExpenseDocumentType::class,
            'payment_method' => ExpensePaymentMethod::class,
            'status' => ExpenseStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Expense $expense): void {
            $originalStatus = ExpenseStatus::from($expense->getRawOriginal('status'));
            $onlyStatusChanged = array_keys($expense->getDirty()) === ['status'];

            if ($originalStatus !== ExpenseStatus::Posted
                || $expense->status !== ExpenseStatus::Reversed
                || ! $onlyStatusChanged
                || ! $expense->reversals()->exists()) {
                throw new DomainException('A posted expense is immutable; reverse it instead.');
            }
        });
        static::deleting(fn (): never => throw new DomainException('Historical expenses cannot be deleted.'));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function cashMovements(): MorphMany
    {
        return $this->morphMany(CashMovement::class, 'reference');
    }
}
