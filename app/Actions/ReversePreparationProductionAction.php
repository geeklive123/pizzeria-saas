<?php

namespace App\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\Permission;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\PreparationProduction;
use App\Models\User;
use App\Services\CompanyAccessService;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReversePreparationProductionAction
{
    public function __construct(
        private readonly ReverseInventoryMovementAction $reverseMovement,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(PreparationProduction $production, User $user, string $reason): PreparationProduction
    {
        $this->access->ensure($user, $production->company, Permission::ManageInventory);
        if (blank($reason)) {
            throw new DomainException('Debes indicar el motivo de reversión.');
        }

        return DB::transaction(function () use ($production, $user, $reason): PreparationProduction {
            $production = PreparationProduction::query()->forCompany($production->company_id)
                ->with(['company', 'movements.inventoryItem'])->whereKey($production->getKey())
                ->lockForUpdate()->firstOrFail();
            if ($production->reversed_at) {
                throw new DomainException('Esta producción ya fue revertida.');
            }

            $output = $production->movements->firstWhere('type', InventoryMovementType::ProductionOutput);
            $inputs = $production->movements->where('type', InventoryMovementType::ProductionConsumption)->sortBy('inventory_item_id');
            if (! $output instanceof InventoryMovement || $inputs->isEmpty()) {
                throw new DomainException('La producción no tiene trazabilidad completa para revertirse.');
            }
            $batch = InventoryBatch::query()->where('inventory_movement_id', $output->getKey())
                ->lockForUpdate()->first();
            if (! $batch || ! BigDecimal::of($batch->quantity_remaining)->isEqualTo($output->quantity)) {
                throw new DomainException('No se puede revertir: parte del preparado producido ya fue consumido.');
            }

            $this->reverseMovement->execute($output, $user, $reason, allowGroupedProduction: true);
            foreach ($inputs as $movement) {
                $this->reverseMovement->execute($movement, $user, $reason, allowGroupedProduction: true);
            }

            $production->forceFill([
                'reversed_at' => now(), 'reversed_by' => $user->getKey(), 'reversal_reason' => $reason,
            ])->save();

            return $production->refresh()->load(['movements.reversals', 'reversedBy']);
        });
    }
}
