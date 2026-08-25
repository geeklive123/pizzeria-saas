<?php

namespace App\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\UnitConversionService;
use App\Services\WeightedAverageCostCalculator;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class RegisterOpeningStockAction
{
    public function __construct(
        private readonly ApplyInventoryMovementAction $applyMovement,
        private readonly UnitConversionService $converter,
        private readonly WeightedAverageCostCalculator $costCalculator,
        private readonly CompanyAccessService $access,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function execute(
        Company $company,
        Branch $branch,
        InventoryItem $inventoryItem,
        int|string $quantity,
        Unit $inputUnit,
        string $inputUnitCost,
        User $user,
        ?DateTimeInterface $occurredAt = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
        ?DateTimeInterface $expiresAt = null,
    ): InventoryMovement {
        $this->access->ensure($user, $company, Permission::ManageInventory);
        $baseUnit = $inventoryItem->unit()->firstOrFail();
        $baseQuantity = $this->converter->convert($quantity, $inputUnit, $baseUnit);
        $baseUnitCost = $this->costCalculator->baseUnitCost($quantity, $baseQuantity, $inputUnitCost);

        return DB::transaction(function () use (
            $company,
            $branch,
            $inventoryItem,
            $quantity,
            $inputUnit,
            $inputUnitCost,
            $user,
            $occurredAt,
            $referenceType,
            $referenceId,
            $metadata,
            $baseQuantity,
            $baseUnitCost,
            $expiresAt,
        ): InventoryMovement {
            $openingExists = InventoryMovement::query()
                ->forCompany($company)
                ->where('branch_id', $branch->getKey())
                ->where('inventory_item_id', $inventoryItem->getKey())
                ->where('type', InventoryMovementType::Opening)
                ->lockForUpdate()
                ->exists();

            if ($openingExists) {
                throw new DomainException('Opening stock has already been registered for this inventory item and branch.');
            }

            return $this->applyMovement->execute(
                $company,
                $branch,
                $inventoryItem,
                InventoryMovementType::Opening,
                $baseQuantity,
                $baseUnitCost,
                $user,
                occurredAt: $occurredAt,
                referenceType: $referenceType,
                referenceId: $referenceId,
                metadata: array_merge($metadata, [
                    'input_quantity' => (string) $quantity,
                    'input_unit_id' => $inputUnit->getKey(),
                    'input_unit_cost' => $inputUnitCost,
                ]),
                batch: [
                    'received_at' => $occurredAt,
                    'expires_at' => $expiresAt,
                ],
            );
        });
    }
}
