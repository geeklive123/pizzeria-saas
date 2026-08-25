<?php

namespace App\Actions;

use App\Enums\InventoryBatchConsumptionMode;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryBatch;
use App\Models\InventoryStock;
use App\Services\InventoryBatchConsistencyService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;

class ConsumeInventoryBatchesAction
{
    public function __construct(private readonly InventoryBatchConsistencyService $consistency) {}

    /** @return list<array{batch_id:int, batch_ulid:string, quantity:string, unit_cost:string}> */
    public function execute(
        InventoryStock $stock,
        int|string $quantity,
        ?int $preferredMovementId = null,
        InventoryBatchConsumptionMode $mode = InventoryBatchConsumptionMode::Usable,
        ?int $preferredBatchId = null,
    ): array {
        if ($preferredMovementId !== null && $preferredBatchId !== null) {
            throw new DomainException('Batch consumption cannot prefer a movement and a batch simultaneously.');
        }

        $this->consistency->ensureCoverage($stock);
        $remaining = BigDecimal::of($quantity)->toScale(3, RoundingMode::HalfUp);
        $query = InventoryBatch::query()
            ->where('company_id', $stock->company_id)
            ->where('branch_id', $stock->branch_id)
            ->where('inventory_item_id', $stock->inventory_item_id)
            ->where('quantity_remaining', '>', 0);

        $today = CarbonImmutable::now(config('inventory.timezone', 'America/La_Paz'))->toDateString();

        match ($mode) {
            InventoryBatchConsumptionMode::Usable => $query->where(fn ($usable) => $usable
                ->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today)),
            InventoryBatchConsumptionMode::Expired => $query->whereNotNull('expires_at')
                ->whereDate('expires_at', '<', $today),
            InventoryBatchConsumptionMode::Any => null,
        };

        if ($preferredBatchId !== null) {
            $query->whereKey($preferredBatchId);
        }

        $hasPreferredBatch = $preferredMovementId !== null
            && (clone $query)->where('inventory_movement_id', $preferredMovementId)->exists();

        if ($hasPreferredBatch && $preferredBatchId === null) {
            $query->where('inventory_movement_id', $preferredMovementId);
        }

        $batches = $query
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_at')->orderBy('received_at')->orderBy('id')
            ->lockForUpdate()->get();
        $allocations = [];

        foreach ($batches as $batch) {
            if ($remaining->isZero()) {
                break;
            }

            $available = BigDecimal::of($batch->quantity_remaining);
            $consumed = $available->isLessThan($remaining) ? $available : $remaining;
            $batch->forceFill(['quantity_remaining' => (string) $available->minus($consumed)->toScale(3)])->save();
            $allocations[] = [
                'batch_id' => (int) $batch->getKey(),
                'batch_ulid' => $batch->ulid,
                'quantity' => (string) $consumed->toScale(3),
                'unit_cost' => $batch->unit_cost,
            ];
            $remaining = $remaining->minus($consumed);
        }

        if (! $remaining->isZero()) {
            throw new InsufficientStockException('Insufficient batch stock for this inventory movement.');
        }

        return $allocations;
    }
}
