<?php

namespace App\Actions;

use App\Enums\InventoryBatchConsumptionMode;
use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Services\InventoryBatchConsistencyService;
use App\Services\WeightedAverageCostCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ApplyInventoryMovementAction
{
    public function __construct(
        private readonly WeightedAverageCostCalculator $costCalculator,
        private readonly CreateInventoryBatchAction $createBatch,
        private readonly ConsumeInventoryBatchesAction $consumeBatches,
        private readonly RestoreInventoryBatchesAction $restoreBatches,
        private readonly InventoryBatchConsistencyService $batchConsistency,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function execute(
        Company $company,
        Branch $branch,
        InventoryItem $inventoryItem,
        InventoryMovementType $type,
        int|string $baseQuantity,
        ?string $baseUnitCost,
        User $user,
        ?string $reason = null,
        ?DateTimeInterface $occurredAt = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
        ?InventoryMovement $reversalOf = null,
        ?int $reversalDirection = null,
        array $batch = [],
    ): InventoryMovement {
        $this->validateContext($company, $branch, $inventoryItem, $user);

        $quantity = BigDecimal::of($baseQuantity)->toScale(3, RoundingMode::HalfUp);

        if ($quantity->isLessThanOrEqualTo(0)) {
            throw new DomainException('Inventory movement quantities must be greater than zero.');
        }

        $direction = $type === InventoryMovementType::Reversal
            ? $this->validateReversal($reversalOf, $reversalDirection)
            : $type->direction();

        return DB::transaction(function () use (
            $company,
            $branch,
            $inventoryItem,
            $type,
            $quantity,
            $baseUnitCost,
            $user,
            $reason,
            $occurredAt,
            $referenceType,
            $referenceId,
            $metadata,
            $reversalOf,
            $direction,
            $batch,
        ): InventoryMovement {
            $now = now();

            InventoryStock::query()->insertOrIgnore([
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'inventory_item_id' => $inventoryItem->getKey(),
                'quantity' => '0.000',
                'average_cost' => '0.000000',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $stock = InventoryStock::query()
                ->forCompany($company)
                ->where('branch_id', $branch->getKey())
                ->where('inventory_item_id', $inventoryItem->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->batchConsistency->ensureCoverage($stock);

            $currentQuantity = BigDecimal::of($stock->quantity);
            $newQuantity = $direction === 1
                ? $currentQuantity->plus($quantity)
                : $currentQuantity->minus($quantity);

            if ($newQuantity->isNegative()) {
                throw new InsufficientStockException('Insufficient stock for this inventory movement.');
            }

            $batchAllocations = [];
            $restoredExistingBatches = false;

            if ($direction === -1) {
                $preferredMovementId = $type === InventoryMovementType::Reversal
                    && $reversalOf?->direction() === 1
                    ? (int) $reversalOf->getKey()
                    : null;
                $consumptionMode = $type === InventoryMovementType::Reversal
                    ? InventoryBatchConsumptionMode::Any
                    : ($batch['consumption_mode'] ?? InventoryBatchConsumptionMode::Usable);

                if (! $consumptionMode instanceof InventoryBatchConsumptionMode) {
                    throw new DomainException('The inventory batch consumption mode is invalid.');
                }

                $batchAllocations = $this->consumeBatches->execute(
                    $stock,
                    (string) $quantity,
                    $preferredMovementId,
                    $consumptionMode,
                    $batch['preferred_batch_id'] ?? null,
                );
                $metadata['batch_allocations'] = $batchAllocations;
            } elseif ($type === InventoryMovementType::Reversal) {
                $originalAllocations = data_get($reversalOf?->metadata, 'batch_allocations', []);

                if (is_array($originalAllocations) && $originalAllocations !== []) {
                    $this->restoreBatches->execute($stock, $originalAllocations);
                    $metadata['restored_batch_allocations'] = $originalAllocations;
                    $restoredExistingBatches = true;
                }
            }

            $movementUnitCost = $baseUnitCost ?? $stock->average_cost;

            if (BigDecimal::of($movementUnitCost)->isNegative()) {
                throw new DomainException('Inventory unit cost cannot be negative.');
            }

            if ($direction === 1) {
                $newAverageCost = $this->costCalculator->calculate(
                    $stock->quantity,
                    $stock->average_cost,
                    (string) $quantity,
                    $movementUnitCost,
                );
            } elseif ($type === InventoryMovementType::Reversal) {
                $newAverageCost = $this->costCalculator->afterRemoval(
                    $stock->quantity,
                    $stock->average_cost,
                    (string) $quantity,
                    $movementUnitCost,
                );
            } else {
                $newAverageCost = $stock->average_cost;
            }

            $stock->forceFill([
                'quantity' => (string) $newQuantity->toScale(3, RoundingMode::HalfUp),
                'average_cost' => $newAverageCost,
            ])->save();

            if ($type === InventoryMovementType::Reversal) {
                $metadata = array_merge($metadata, [
                    'direction' => $direction,
                    'reversed_type' => $reversalOf?->type->value,
                ]);
            }

            $movement = InventoryMovement::query()->create([
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'inventory_item_id' => $inventoryItem->getKey(),
                'type' => $type,
                'quantity' => (string) $quantity,
                'unit_cost' => $movementUnitCost,
                'total_cost' => $this->costCalculator->totalCost((string) $quantity, $movementUnitCost),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reason' => $reason,
                'metadata' => $metadata ?: null,
                'occurred_at' => $occurredAt ?? $now,
                'created_by' => $user->getKey(),
                'reversal_of_id' => $reversalOf?->getKey(),
            ]);

            if ($direction === 1 && ! $restoredExistingBatches) {
                $purchaseItem = $batch['purchase_item'] ?? null;

                $this->createBatch->execute(
                    $movement,
                    (string) $quantity,
                    $movementUnitCost,
                    $purchaseItem instanceof PurchaseItem ? $purchaseItem : null,
                    $batch['received_at'] ?? $occurredAt ?? $now,
                    $batch['expires_at'] ?? null,
                );
            }

            $this->batchConsistency->assertMatches($stock->refresh());

            return $movement;
        });
    }

    private function validateContext(
        Company $company,
        Branch $branch,
        InventoryItem $inventoryItem,
        User $user,
    ): void {
        if ((int) $branch->company_id !== (int) $company->getKey()) {
            throw new DomainException('The branch must belong to the selected company.');
        }

        if ((int) $inventoryItem->company_id !== (int) $company->getKey()) {
            throw new DomainException('The inventory item must belong to the selected company.');
        }

        if (! $user->membershipFor($company)) {
            throw new AuthorizationException('The user has no active membership in the selected company.');
        }
    }

    private function validateReversal(?InventoryMovement $reversalOf, ?int $direction): int
    {
        if (! $reversalOf || ! in_array($direction, [-1, 1], true)) {
            throw new DomainException('A reversal requires its original movement and opposite direction.');
        }

        return $direction;
    }
}
