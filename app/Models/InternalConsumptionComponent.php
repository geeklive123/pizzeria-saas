<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'internal_consumption_id', 'internal_consumption_item_id', 'inventory_item_id', 'unit_id', 'inventory_movement_id', 'inventory_item_name_snapshot', 'unit_symbol_snapshot', 'quantity', 'unit_cost', 'total_cost', 'cost_status'])]
class InternalConsumptionComponent extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:6', 'total_cost' => 'decimal:6'];
    }

    public function consumption(): BelongsTo
    {
        return $this->belongsTo(InternalConsumption::class, 'internal_consumption_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InternalConsumptionItem::class, 'internal_consumption_item_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }
}
