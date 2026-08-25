<?php

namespace App\Models;

use App\Enums\OrderItemStatus;
use App\Enums\OrderType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['company_id', 'branch_id', 'order_id', 'product_variant_id', 'quantity', 'unit_price', 'line_total', 'fulfillment_type', 'requires_preparation', 'status', 'notes', 'configuration_snapshot', 'created_by', 'sent_at', 'preparing_at', 'ready_at', 'served_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason'])]
class OrderItem extends Model
{
    use BelongsToCompany, HasFactory, HasUlids;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'fulfillment_type' => OrderType::class,
            'requires_preparation' => 'boolean',
            'status' => OrderItemStatus::class,
            'configuration_snapshot' => 'array',
            'sent_at' => 'immutable_datetime',
            'preparing_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'served_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(OrderItemSection::class)->orderBy('position');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }

    public function kitchenDispatchItem(): HasOne
    {
        return $this->hasOne(KitchenDispatchItem::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
