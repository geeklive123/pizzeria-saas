<?php

namespace App\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\Permission;
use App\Models\InventoryMovement;
use App\Models\PreparationProduction;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReverseInventoryMovementAction
{
    public function __construct(
        private readonly ApplyInventoryMovementAction $applyMovement,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(
        InventoryMovement $movement,
        User $user,
        string $reason,
        bool $allowGroupedProduction = false,
        Permission $requiredPermission = Permission::ManageInventory,
    ): InventoryMovement {
        $this->access->ensure($user, $movement->company, $requiredPermission);

        if (blank($reason)) {
            throw new DomainException('A reversal reason is required.');
        }
        if ($movement->reference_type === PreparationProduction::class && ! $allowGroupedProduction) {
            throw new DomainException('Production movements must be reversed through their complete production.');
        }

        return DB::transaction(function () use ($movement, $user, $reason): InventoryMovement {
            $movement = InventoryMovement::query()
                ->with(['company', 'branch', 'inventoryItem'])
                ->lockForUpdate()
                ->findOrFail($movement->getKey());

            if ($movement->type === InventoryMovementType::Reversal) {
                throw new DomainException('A reversal movement cannot itself be reversed.');
            }

            if ($movement->reversals()->lockForUpdate()->exists()) {
                throw new DomainException('This inventory movement has already been reversed.');
            }

            return $this->applyMovement->execute(
                $movement->company,
                $movement->branch,
                $movement->inventoryItem,
                InventoryMovementType::Reversal,
                $movement->quantity,
                $movement->unit_cost,
                $user,
                $reason,
                referenceType: InventoryMovement::class,
                referenceId: $movement->getKey(),
                metadata: ['original_ulid' => $movement->ulid],
                reversalOf: $movement,
                reversalDirection: -$movement->direction(),
            );
        });
    }
}
