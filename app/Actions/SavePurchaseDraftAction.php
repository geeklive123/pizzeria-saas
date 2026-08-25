<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\PurchaseStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\UnitConversionService;
use App\Services\WeightedAverageCostCalculator;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class SavePurchaseDraftAction
{
    public function __construct(
        private readonly UnitConversionService $converter,
        private readonly WeightedAverageCostCalculator $costCalculator,
        private readonly CompanyAccessService $access,
    ) {}

    /** @param list<array{inventory_item_id:int, quantity:string, input_unit_id:int, unit_cost:string, expires_at:?string}> $items */
    public function execute(
        Company $company,
        Branch $branch,
        User $user,
        array $data,
        array $items,
        ?Purchase $purchase = null,
    ): Purchase {
        $this->access->ensure($user, $company, Permission::ManagePurchases);

        if ((int) $branch->company_id !== (int) $company->getKey()) {
            throw new DomainException('The branch does not belong to the active company.');
        }

        return DB::transaction(function () use ($company, $branch, $user, $data, $items, $purchase): Purchase {
            if ($purchase && $purchase->status !== PurchaseStatus::Draft) {
                throw new DomainException('Only a draft purchase can be edited.');
            }

            $purchase ??= new Purchase;
            $purchase->fill([
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'supplier_name' => filled($data['supplier_name'] ?? null) ? $data['supplier_name'] : null,
                'document_number' => filled($data['document_number'] ?? null) ? $data['document_number'] : null,
                'purchased_at' => CarbonImmutable::parse($data['purchased_at'], 'America/La_Paz')->utc(),
                'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
                'created_by' => $purchase->created_by ?: $user->getKey(),
                'status' => PurchaseStatus::Draft,
            ])->save();

            $purchase->items()->delete();

            foreach ($items as $dataItem) {
                $inventoryItem = InventoryItem::query()->forCompany($company)
                    ->with('unit')->findOrFail($dataItem['inventory_item_id']);
                $inputUnit = Unit::query()->forCompany($company)->findOrFail($dataItem['input_unit_id']);
                $baseQuantity = $this->converter->convert($dataItem['quantity'], $inputUnit, $inventoryItem->unit);

                $purchase->items()->create([
                    'company_id' => $company->getKey(),
                    'inventory_item_id' => $inventoryItem->getKey(),
                    'quantity' => $dataItem['quantity'],
                    'input_unit_id' => $inputUnit->getKey(),
                    'base_quantity' => $baseQuantity,
                    'unit_cost' => $dataItem['unit_cost'],
                    'total_cost' => $this->costCalculator->totalCost($dataItem['quantity'], $dataItem['unit_cost']),
                    'expires_at' => $dataItem['expires_at'] ?? null,
                ]);
            }

            return $purchase->refresh()->load(['items.inventoryItem', 'items.inputUnit']);
        });
    }
}
