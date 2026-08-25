<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'kitchen_dispatch_id', 'order_item_id'])]
class KitchenDispatchItem extends Model
{
    use BelongsToCompany;

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(KitchenDispatch::class, 'kitchen_dispatch_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
