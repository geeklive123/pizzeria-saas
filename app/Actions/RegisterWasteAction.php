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
use DateTimeInterface;
use DomainException;

class RegisterWasteAction
{
    public function __construct(
        private readonly ApplyInventoryMovementAction $applyMovement,
        private readonly UnitConversionService $converter,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(
        Company $company,
        Branch $branch,
        InventoryItem $inventoryItem,
        int|string $quantity,
        Unit $inputUnit,
        string $reason,
        User $user,
        ?DateTimeInterface $occurredAt = null,
    ): InventoryMovement {
        $this->access->ensure($user, $company, Permission::ManageInventory);

        if (blank($reason)) {
            throw new DomainException('A waste reason is required.');
        }

        $baseQuantity = $this->converter->convert($quantity, $inputUnit, $inventoryItem->unit()->firstOrFail());

        return $this->applyMovement->execute(
            $company,
            $branch,
            $inventoryItem,
            InventoryMovementType::Waste,
            $baseQuantity,
            null,
            $user,
            $reason,
            $occurredAt,
            metadata: [
                'input_quantity' => (string) $quantity,
                'input_unit_id' => $inputUnit->getKey(),
            ],
        );
    }
}
