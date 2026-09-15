<?php

namespace App\Models;

use App\Enums\OrderItemStatus;
use App\Enums\OrderType;
use App\Models\Concerns\BelongsToCompany;
use App\Support\UiFormatter;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['company_id', 'branch_id', 'order_id', 'product_variant_id', 'promotion_id', 'quantity', 'unit_price', 'line_total', 'fulfillment_type', 'requires_preparation', 'status', 'notes', 'configuration_snapshot', 'created_by', 'sent_at', 'preparing_at', 'ready_at', 'served_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason'])]
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

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function displayName(): string
    {
        $standaloneExtraName = $this->configuration_snapshot['extra']['name'] ?? null;
        if (($this->configuration_snapshot['type'] ?? null) === 'standalone_extra' && filled($standaloneExtraName)) {
            return (string) $standaloneExtraName;
        }
        $promotionName = $this->configuration_snapshot['promotion']['name'] ?? null;
        if (is_string($promotionName) && $promotionName !== '') {
            return $promotionName;
        }
        if ($this->sections->isNotEmpty()) {
            return 'Pizza '.UiFormatter::variantName(
                $this->sections->first()->variant_name_snapshot,
                $this->configuration_snapshot['size_key'] ?? null,
            );
        }

        return $this->productVariant->product->name.' · '.UiFormatter::variantName(
            $this->productVariant->name,
            $this->productVariant->size_key,
        );
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
