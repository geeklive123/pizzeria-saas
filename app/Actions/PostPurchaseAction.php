<?php

namespace App\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\Permission;
use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\UnitConversionService;
use App\Services\WeightedAverageCostCalculator;
use DomainException;
use Illuminate\Support\Facades\DB;

class PostPurchaseAction
{
    public function __construct(
        private readonly ApplyInventoryMovementAction $applyMovement,
        private readonly UnitConversionService $converter,
        private readonly WeightedAverageCostCalculator $costCalculator,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(Purchase $purchase, User $user): Purchase
    {
        $this->access->ensure($user, $purchase->company, Permission::ManagePurchases);

        return DB::transaction(function () use ($purchase, $user): Purchase {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->getKey());

            if ($purchase->status !== PurchaseStatus::Draft) {
                throw new DomainException('Only a draft purchase can be posted.');
            }

            $items = $purchase->items()
                ->with(['inventoryItem.unit', 'inputUnit'])
                ->orderBy('inventory_item_id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new DomainException('A purchase must contain at least one item before posting.');
            }

            foreach ($items as $item) {
                $baseQuantity = $this->converter->convert(
                    $item->quantity,
                    $item->inputUnit,
                    $item->inventoryItem->unit,
                );
                $totalCost = $this->costCalculator->totalCost($item->quantity, $item->unit_cost);
                $baseUnitCost = $this->costCalculator->baseUnitCost(
                    $item->quantity,
                    $baseQuantity,
                    $item->unit_cost,
                );

                $item->update([
                    'base_quantity' => $baseQuantity,
                    'total_cost' => $totalCost,
                ]);

                $this->applyMovement->execute(
                    $purchase->company,
                    $purchase->branch,
                    $item->inventoryItem,
                    InventoryMovementType::Purchase,
                    $baseQuantity,
                    $baseUnitCost,
                    $user,
                    occurredAt: $purchase->purchased_at,
                    referenceType: Purchase::class,
                    referenceId: $purchase->getKey(),
                    metadata: [
                        'purchase_item_id' => $item->getKey(),
                        'input_quantity' => $item->quantity,
                        'input_unit_id' => $item->input_unit_id,
                        'input_unit_cost' => $item->unit_cost,
                    ],
                    batch: [
                        'purchase_item' => $item,
                        'received_at' => $purchase->purchased_at,
                        'expires_at' => $item->expires_at,
                    ],
                );
            }

            $purchase->update(['status' => PurchaseStatus::Posted]);

            return $purchase->refresh()->load('items');
        });
    }
}
