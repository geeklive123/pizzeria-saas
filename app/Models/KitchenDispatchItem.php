<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'kitchen_dispatch_id', 'order_item_id', 'financial_type', 'gross_total', 'pizza_base_total', 'extras_total', 'other_total', 'discount_total', 'net_total'])]
class KitchenDispatchItem extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'gross_total' => 'decimal:2',
            'pizza_base_total' => 'decimal:2',
            'extras_total' => 'decimal:2',
            'other_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'net_total' => 'decimal:2',
        ];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(KitchenDispatch::class, 'kitchen_dispatch_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
