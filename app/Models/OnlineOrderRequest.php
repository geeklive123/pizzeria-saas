<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnlineOrderRequest extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['idempotency_key', 'request_hash'];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'total' => 'decimal:2', 'resolved_at' => 'immutable_datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OnlineOrderRequestItem::class);
    }

    public function publicCode(): string
    {
        return 'WEB-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
