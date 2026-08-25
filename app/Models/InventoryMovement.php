<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use App\Exceptions\ImmutableInventoryMovementException;
use App\Models\Concerns\BelongsToCompany;
use Brick\Math\BigDecimal;
use Database\Factories\InventoryMovementFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'company_id',
    'branch_id',
    'inventory_item_id',
    'type',
    'quantity',
    'unit_cost',
    'total_cost',
    'reference_type',
    'reference_id',
    'reason',
    'metadata',
    'occurred_at',
    'created_by',
    'reversal_of_id',
])]
class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::creating(function (InventoryMovement $movement): void {
            if (! is_numeric($movement->quantity)
                || BigDecimal::of($movement->quantity)->isLessThanOrEqualTo(0)) {
                throw new DomainException('Inventory movement quantities must be greater than zero.');
            }
        });

        static::updating(fn () => throw new ImmutableInventoryMovementException(
            'Posted inventory movements are immutable; create a reversal instead.',
        ));

        static::deleting(fn () => throw new ImmutableInventoryMovementException(
            'Posted inventory movements cannot be deleted; create a reversal instead.',
        ));
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:6',
            'total_cost' => 'decimal:6',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function direction(): int
    {
        if ($this->type !== InventoryMovementType::Reversal) {
            return $this->type->direction();
        }

        $direction = (int) data_get($this->metadata, 'direction');

        if (! in_array($direction, [-1, 1], true)) {
            throw new DomainException('The reversal movement has no valid direction metadata.');
        }

        return $direction;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function inventoryBatch(): HasOne
    {
        return $this->hasOne(InventoryBatch::class);
    }
}
