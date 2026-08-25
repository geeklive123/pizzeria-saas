<?php

namespace App\Models;

use App\Enums\ModifierOptionType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'order_item_id', 'order_item_section_id', 'modifier_option_id', 'type', 'name_snapshot', 'price_delta_snapshot', 'inventory_item_id', 'quantity_snapshot', 'unit_id'])]
class OrderItemModifier extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'type' => ModifierOptionType::class,
            'price_delta_snapshot' => 'decimal:2',
            'quantity_snapshot' => 'decimal:3',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(OrderItemSection::class, 'order_item_section_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ModifierOption::class, 'modifier_option_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
