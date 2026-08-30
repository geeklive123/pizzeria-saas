<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TableChargeMode;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'branch_id', 'restaurant_table_id', 'active_restaurant_table_id', 'order_number', 'type', 'charge_mode', 'status', 'customer_name', 'customer_phone', 'notes', 'subtotal', 'pizza_base_subtotal', 'extras_subtotal', 'other_subtotal', 'discount_percentage', 'discount_total', 'total', 'financial_snapshot', 'opened_at', 'closed_at', 'created_by'])]
class Order extends Model
{
    use BelongsToCompany, HasFactory, HasUlids;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'charge_mode' => TableChargeMode::class,
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'pizza_base_subtotal' => 'decimal:2',
            'extras_subtotal' => 'decimal:2',
            'other_subtotal' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
            'financial_snapshot' => 'array',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function restaurantTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function kitchenDispatches(): HasMany
    {
        return $this->hasMany(KitchenDispatch::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function printAttempts(): HasMany
    {
        return $this->hasMany(PrintAttempt::class);
    }

    public function scopeForBranch(Builder $query, Branch|int $branch): Builder
    {
        return $query->where('branch_id', $branch instanceof Branch ? $branch->getKey() : $branch);
    }

    public function formattedNumber(): string
    {
        return '#'.str_pad((string) $this->order_number, 6, '0', STR_PAD_LEFT);
    }
}
