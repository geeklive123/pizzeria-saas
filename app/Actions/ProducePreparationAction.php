<?php

namespace App\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\Permission;
use App\Exceptions\InsufficientPreparationStockException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Preparation;
use App\Models\PreparationProduction;
use App\Models\User;
use App\Services\InventoryAvailabilityService;
use App\Services\PreparationAvailabilityService;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ProducePreparationAction
{
    public function __construct(
        private readonly ApplyInventoryMovementAction $movements,
        private readonly InventoryAvailabilityService $inventoryAvailability,
        private readonly PreparationAvailabilityService $preparationAvailability,
    ) {}

    public function execute(
        Company $company,
        Branch $branch,
        Preparation $preparation,
        int $lots,
        User $user,
        int|string|null $actualYield = null,
    ): PreparationProduction {
        $this->authorize($company, $branch, $preparation, $user);
        if ($lots < 1) {
            throw new DomainException('La cantidad de lotes debe ser al menos uno.');
        }

        return DB::transaction(function () use ($company, $branch, $preparation, $lots, $user, $actualYield): PreparationProduction {
            $preparation = Preparation::query()->forCompany($company)
                ->with(['components.inventoryItem.unit', 'outputInventoryItem.unit'])
                ->whereKey($preparation->getKey())->lockForUpdate()->firstOrFail();
            if (! $preparation->is_active || ! $preparation->outputInventoryItem->is_active
                || $preparation->components->contains(fn ($component): bool => ! $component->inventoryItem->is_active)) {
                throw new DomainException('La preparación está inactiva y no puede producirse.');
            }

            $itemIds = $preparation->components->pluck('inventory_item_id')
                ->push($preparation->output_inventory_item_id)->unique()->sort()->values();
            foreach ($itemIds as $itemId) {
                InventoryStock::query()->insertOrIgnore([
                    'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(),
                    'inventory_item_id' => $itemId, 'quantity' => '0.000', 'average_cost' => '0.000000',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $stock = InventoryStock::query()->forCompany($company)
                    ->where('branch_id', $branch->getKey())->where('inventory_item_id', $itemId)
                    ->lockForUpdate()->firstOrFail();
                if ($preparation->components->contains('inventory_item_id', $itemId)) {
                    $reservations = InventoryReservation::query()->forCompany($company)
                        ->where('branch_id', $branch->getKey())->where('inventory_item_id', $itemId)
                        ->where('status', InventoryReservationStatus::Reserved->value)
                        ->orderBy('id')->lockForUpdate()->get();
                    $this->inventoryAvailability->forStock(
                        $stock,
                        reservedQuantity: $this->inventoryAvailability->reservedQuantity($reservations),
                    );
                }
            }

            $availability = $this->preparationAvailability->calculate($preparation, $branch);
            if ($lots > $availability->maximumLots) {
                throw new InsufficientPreparationStockException(
                    $availability->maximumLots,
                    $this->preparationAvailability->shortages($availability, $lots),
                    $preparation->name,
                    $lots,
                );
            }

            $theoretical = BigDecimal::of($preparation->theoretical_yield)->multipliedBy($lots)
                ->toScale(3, RoundingMode::HalfUp);
            $actual = $this->yield($actualYield, $theoretical);
            $production = PreparationProduction::query()->create([
                'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(),
                'preparation_id' => $preparation->getKey(), 'lots' => $lots,
                'theoretical_yield' => (string) $theoretical, 'actual_yield' => (string) $actual,
                'yield_variance' => (string) $actual->minus($theoretical)->toScale(3, RoundingMode::HalfUp),
                'total_cost' => '0.000000', 'unit_cost' => '0.000000',
                'produced_at' => now(), 'created_by' => $user->getKey(),
            ]);

            $totalCost = BigDecimal::zero();
            foreach ($preparation->components->sortBy('inventory_item_id') as $component) {
                $quantity = BigDecimal::of($component->quantity)->multipliedBy($lots)->toScale(3, RoundingMode::HalfUp);
                $movement = $this->movements->execute(
                    $company, $branch, $component->inventoryItem, InventoryMovementType::ProductionConsumption,
                    (string) $quantity, null, $user,
                    reason: 'Consumo para producción de '.$preparation->name,
                    referenceType: PreparationProduction::class, referenceId: $production->getKey(),
                    metadata: ['preparation_ulid' => $preparation->ulid, 'lots' => $lots],
                );
                $totalCost = $totalCost->plus($this->allocationCost($movement));
            }

            $unitCost = $totalCost->dividedBy($actual, 6, RoundingMode::HalfUp);
            $this->movements->execute(
                $company, $branch, $preparation->outputInventoryItem, InventoryMovementType::ProductionOutput,
                (string) $actual, (string) $unitCost, $user,
                reason: 'Salida de producción de '.$preparation->name,
                referenceType: PreparationProduction::class, referenceId: $production->getKey(),
                metadata: ['preparation_ulid' => $preparation->ulid, 'lots' => $lots,
                    'theoretical_yield' => (string) $theoretical, 'actual_yield' => (string) $actual],
            );

            $production->forceFill([
                'total_cost' => (string) $totalCost->toScale(6, RoundingMode::HalfUp),
                'unit_cost' => (string) $unitCost,
            ])->save();

            return $production->refresh()->load(['preparation.outputInventoryItem.unit', 'movements.inventoryItem.unit', 'createdBy']);
        });
    }

    private function authorize(Company $company, Branch $branch, Preparation $preparation, User $user): void
    {
        if (! $company->is_active || ! $branch->is_active
            || (int) $branch->company_id !== (int) $company->getKey()
            || (int) $preparation->company_id !== (int) $company->getKey()
            || (! $user->canForCompany(Permission::ManageInventory, $company)
                && ! $user->canForCompany(Permission::ManageKitchen, $company))) {
            throw new AuthorizationException('No estás autorizado para producir esta preparación.');
        }
    }

    private function yield(int|string|null $value, BigDecimal $theoretical): BigDecimal
    {
        if ($value === null || $value === '') {
            return $theoretical;
        }
        try {
            $yield = BigDecimal::of($value)->toScale(3, RoundingMode::HalfUp);
        } catch (NumberFormatException) {
            throw new DomainException('El rendimiento real no es válido.');
        }
        if ($yield->isLessThanOrEqualTo(0)) {
            throw new DomainException('El rendimiento real debe ser mayor que cero.');
        }

        return $yield;
    }

    private function allocationCost(InventoryMovement $movement): BigDecimal
    {
        return collect(data_get($movement->metadata, 'batch_allocations', []))->reduce(
            fn (BigDecimal $cost, array $allocation): BigDecimal => $cost->plus(
                BigDecimal::of($allocation['quantity'])->multipliedBy($allocation['unit_cost']),
            ),
            BigDecimal::zero(),
        );
    }
}
