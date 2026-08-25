<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use App\Enums\PurchaseStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PurchaseFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id',
    'branch_id',
    'supplier_name',
    'document_number',
    'purchased_at',
    'notes',
    'created_by',
    'status',
])]
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (Purchase $purchase): void {
            if (! Branch::query()
                ->whereKey($purchase->branch_id)
                ->where('company_id', $purchase->company_id)
                ->exists()) {
                throw new DomainException('The purchase branch must belong to the same company.');
            }

            if (! $purchase->exists) {
                return;
            }

            $originalStatus = PurchaseStatus::from($purchase->getRawOriginal('status'));
            $dirtyAttributes = array_keys($purchase->getDirty());

            if ($originalStatus === PurchaseStatus::Reversed) {
                throw new DomainException('A reversed purchase is immutable.');
            }

            if ($originalStatus === PurchaseStatus::Draft && $purchase->status !== PurchaseStatus::Draft) {
                $itemCount = $purchase->items()->count();
                $movementCount = InventoryMovement::query()
                    ->where('company_id', $purchase->company_id)
                    ->where('reference_type', self::class)
                    ->where('reference_id', $purchase->getKey())
                    ->where('type', InventoryMovementType::Purchase)
                    ->count();

                if ($purchase->status !== PurchaseStatus::Posted
                    || array_diff($dirtyAttributes, ['status']) !== []
                    || $itemCount === 0
                    || $movementCount !== $itemCount) {
                    throw new DomainException('A purchase can only be posted after all inventory movements exist.');
                }
            }

            if ($originalStatus === PurchaseStatus::Posted) {
                $movements = InventoryMovement::query()
                    ->where('company_id', $purchase->company_id)
                    ->where('reference_type', self::class)
                    ->where('reference_id', $purchase->getKey())
                    ->where('type', InventoryMovementType::Purchase);
                $movementCount = (clone $movements)->count();
                $reversedMovementCount = $movements->whereHas('reversals')->count();
                $isReversalTransition = $purchase->status === PurchaseStatus::Reversed
                    && array_diff($dirtyAttributes, ['status']) === []
                    && $movementCount > 0
                    && $reversedMovementCount === $movementCount;

                if (! $isReversalTransition) {
                    throw new DomainException('A posted purchase is immutable; reverse it instead.');
                }
            }
        });

        static::deleting(function (Purchase $purchase): void {
            if ($purchase->status !== PurchaseStatus::Draft) {
                throw new DomainException('Only draft purchases can be deleted.');
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'purchased_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
