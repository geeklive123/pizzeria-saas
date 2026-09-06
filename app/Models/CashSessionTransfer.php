<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['company_id', 'branch_id', 'order_id', 'destination_cash_session_id', 'destination_cashier_id', 'authorized_by', 'reason', 'source_cash_session_ids', 'source_cashier_ids', 'payment_ids', 'source_cash_movement_ids', 'compensating_cash_movement_ids', 'created_at'])]
class CashSessionTransfer extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'source_cash_session_ids' => 'array',
            'source_cashier_ids' => 'array',
            'payment_ids' => 'array',
            'source_cash_movement_ids' => 'array',
            'compensating_cash_movement_ids' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Cash session transfers are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Cash session transfers are immutable.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function destinationSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'destination_cash_session_id');
    }

    public function destinationCashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destination_cashier_id');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
