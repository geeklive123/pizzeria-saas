<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnlineOrderRequest extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['idempotency_key', 'request_hash'];

    protected function casts(): array
    {
        return ['is_fence' => 'boolean', 'subtotal' => 'decimal:2', 'total' => 'decimal:2', 'resolved_at' => 'immutable_datetime', 'converted_at' => 'immutable_datetime', 'legacy_reviewed_at' => 'immutable_datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OnlineOrderRequestItem::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function posOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'pos_order_id');
    }

    public function legacyReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'legacy_reviewed_by');
    }

    public function publicCode(): string
    {
        return 'WEB-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
