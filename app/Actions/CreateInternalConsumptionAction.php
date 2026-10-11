<?php

namespace App\Actions;

use App\Enums\InternalConsumptionReason;
use App\Enums\InternalConsumptionStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\Permission;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InternalConsumption;
use App\Models\InternalConsumptionComponent;
use App\Models\InternalConsumptionItem;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\InternalConsumptionPreparationService;
use App\Services\InventoryAvailabilityService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateInternalConsumptionAction
{
    public function __construct(
        private readonly InternalConsumptionPreparationService $preparation,
        private readonly InventoryAvailabilityService $availability,
        private readonly ApplyInventoryMovementAction $movements,
        private readonly CompanyAccessService $access,
    ) {}

    /** @param list<array<string,mixed>> $items */
    public function execute(Company $company, Branch $branch, User $user, InternalConsumptionReason $reason, ?string $notes, string $idempotencyKey, array $items): InternalConsumption
    {
        $this->access->ensure($user, $company, Permission::CreateInternalConsumptions);

        try {
            return DB::transaction(function () use ($company, $branch, $user, $reason, $notes, $idempotencyKey, $items): InternalConsumption {
                $existing = InternalConsumption::query()->forCompany($company)->where('branch_id', $branch->getKey())
                    ->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return $existing->load(['items', 'components']);
                }

                $prepared = $this->preparation->prepare($company, $branch, $items);
                foreach ($prepared['requirements'] as $requirement) {
                    $inventoryItem = $requirement['inventory_item'];
                    $stock = InventoryStock::query()->where('company_id', $company->getKey())
                        ->where('branch_id', $branch->getKey())->where('inventory_item_id', $inventoryItem->getKey())
                        ->lockForUpdate()->first();
                    $reservations = InventoryReservation::query()->where('company_id', $company->getKey())
                        ->where('branch_id', $branch->getKey())->where('inventory_item_id', $inventoryItem->getKey())
                        ->where('status', InventoryReservationStatus::Reserved->value)->orderBy('id')->lockForUpdate()->get();
                    $available = $this->availability->forStock($stock, reservedQuantity: $this->availability->reservedQuantity($reservations));
                    if (BigDecimal::of($available->availableQuantity)->isLessThan(BigDecimal::of($requirement['quantity']))) {
                        throw new InsufficientStockException("No disponible: falta {$inventoryItem->name}.");
                    }
                }

                $consumption = InternalConsumption::query()->create([
                    'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(),
                    'code' => 'CI-'.Str::ulid(),
                    'reason' => $reason, 'notes' => $notes, 'status' => InternalConsumptionStatus::Confirmed,
                    'total_cost' => '0.000000', 'cost_status' => 'known', 'idempotency_key' => $idempotencyKey,
                    'created_by' => $user->getKey(), 'confirmed_at' => now(),
                ]);

                $movementTasks = [];
                foreach ($prepared['items'] as $preparedItem) {
                    $composition = $preparedItem['composition'];
                    $item = InternalConsumptionItem::query()->create([
                        'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(),
                        'internal_consumption_id' => $consumption->getKey(),
                        'product_variant_id' => $composition['primary_variant']->getKey(),
                        'quantity' => $preparedItem['quantity'], 'fulfillment_type' => $preparedItem['fulfillment_type'],
                        'product_name_snapshot' => $composition['primary_variant']->product->name,
                        'variant_name_snapshot' => $composition['primary_variant']->name,
                        'configuration_snapshot' => $composition['snapshot'], 'notes' => $preparedItem['notes'],
                    ]);
                    foreach ($preparedItem['requirements'] as $requirement) {
                        $movementTasks[] = ['item' => $item, ...$requirement];
                    }
                }

                usort($movementTasks, fn (array $left, array $right): int => [$left['inventory_item']->id, $left['item']->id] <=> [$right['inventory_item']->id, $right['item']->id]);
                $total = BigDecimal::zero();
                $knownComponents = 0;
                $unknownComponents = 0;
                foreach ($movementTasks as $task) {
                    $inventoryItem = $task['inventory_item']->loadMissing('unit');
                    $movement = $this->movements->execute(
                        $company, $branch, $inventoryItem, InventoryMovementType::InternalConsumption,
                        $task['quantity'], null, $user,
                        reason: "Consumo interno {$consumption->code}",
                        referenceType: InternalConsumptionItem::class, referenceId: $task['item']->getKey(),
                        metadata: ['internal_consumption_id' => $consumption->getKey(), 'internal_consumption_ulid' => $consumption->ulid],
                    );
                    $known = BigDecimal::of($movement->unit_cost)->isGreaterThan(0);
                    if ($known) {
                        $total = $total->plus($movement->total_cost);
                        $knownComponents++;
                    } else {
                        $unknownComponents++;
                    }
                    InternalConsumptionComponent::query()->create([
                        'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(),
                        'internal_consumption_id' => $consumption->getKey(), 'internal_consumption_item_id' => $task['item']->getKey(),
                        'inventory_item_id' => $inventoryItem->getKey(), 'unit_id' => $inventoryItem->unit_id,
                        'inventory_movement_id' => $movement->getKey(), 'inventory_item_name_snapshot' => $inventoryItem->name,
                        'unit_symbol_snapshot' => $inventoryItem->unit->symbol, 'quantity' => $movement->quantity,
                        'unit_cost' => $known ? $movement->unit_cost : null, 'total_cost' => $known ? $movement->total_cost : null,
                        'cost_status' => $known ? 'known' : 'unknown',
                    ]);
                }

                $consumption->forceFill([
                    'total_cost' => (string) $total->toScale(6, RoundingMode::HalfUp),
                    'cost_status' => match (true) {
                        $unknownComponents === 0 => 'known',
                        $knownComponents === 0 => 'unknown',
                        default => 'partial',
                    },
                ])->save();

                return $consumption->load(['items', 'components.movement']);
            });
        } catch (QueryException $exception) {
            $existing = InternalConsumption::query()->forCompany($company)->where('branch_id', $branch->getKey())
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load(['items', 'components']);
            }

            throw $exception;
        }
    }
}
