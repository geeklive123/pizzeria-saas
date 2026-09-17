<?php

namespace App\Models;

use App\Enums\OrderCancellationScope;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'branch_id', 'order_id', 'kitchen_dispatch_id', 'order_item_id', 'parent_id', 'scope', 'snapshot', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'restored_at', 'restored_by', 'restoration_reason'])]
class OrderCancellationAudit extends Model
{
    use BelongsToCompany, HasUlids;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'scope' => OrderCancellationScope::class,
            'snapshot' => 'array',
            'cancelled_at' => 'immutable_datetime',
            'restored_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function kitchenDispatch(): BelongsTo
    {
        return $this->belongsTo(KitchenDispatch::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }
}
