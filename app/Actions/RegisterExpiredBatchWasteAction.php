<?php

namespace App\Actions;

use App\Enums\InventoryBatchConsumptionMode;
use App\Enums\InventoryBatchStatus;
use App\Enums\InventoryMovementType;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\InventoryBatchExpirationService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class RegisterExpiredBatchWasteAction
{
    public function __construct(
        private readonly ApplyInventoryMovementAction $applyMovement,
        private readonly InventoryBatchExpirationService $expiration,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(
        Company $company,
        Branch $branch,
        InventoryBatch $batch,
        int|string $quantity,
        string $reason,
        User $user,
    ): InventoryMovement {
        $this->access->ensure($user, $company, Permission::ManageInventory);

        if ((int) $batch->company_id !== (int) $company->getKey()
            || (int) $batch->branch_id !== (int) $branch->getKey()) {
            throw new DomainException('The expired batch does not belong to the active inventory context.');
        }

        if ($this->expiration->status($batch) !== InventoryBatchStatus::Expired) {
            throw new DomainException('Only an expired batch can be retired with this operation.');
        }

        if (blank($reason)) {
            throw new DomainException('A reason is required to retire expired inventory.');
        }

        $baseQuantity = BigDecimal::of($quantity)->toScale(3, RoundingMode::HalfUp);

        return $this->applyMovement->execute(
            $company,
            $branch,
            $batch->inventoryItem,
            InventoryMovementType::Waste,
            (string) $baseQuantity,
            null,
            $user,
            $reason,
            metadata: [
                'expired_batch_ulid' => $batch->ulid,
                'administrative_expired_disposal' => true,
            ],
            batch: [
                'consumption_mode' => InventoryBatchConsumptionMode::Expired,
                'preferred_batch_id' => $batch->getKey(),
            ],
        );
    }
}
