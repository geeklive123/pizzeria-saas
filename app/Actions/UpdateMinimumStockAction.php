<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\User;
use App\Services\CompanyAccessService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateMinimumStockAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    public function execute(
        Company $company,
        Branch $branch,
        InventoryItem $inventoryItem,
        int|string|null $minimumQuantity,
        User $user,
    ): InventoryStock {
        $this->access->ensure($user, $company, Permission::ManageInventory);

        if ((int) $branch->company_id !== (int) $company->getKey()
            || (int) $inventoryItem->company_id !== (int) $company->getKey()) {
            throw new DomainException('Minimum stock cannot mix companies or branches.');
        }

        $minimum = $minimumQuantity === null || $minimumQuantity === ''
            ? null
            : BigDecimal::of($minimumQuantity)->toScale(3, RoundingMode::HalfUp);

        if ($minimum?->isNegative()) {
            throw new DomainException('Minimum stock cannot be negative.');
        }

        return DB::transaction(function () use ($company, $branch, $inventoryItem, $minimum): InventoryStock {
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

            $stock = InventoryStock::query()->forCompany($company)
                ->where('branch_id', $branch->getKey())
                ->where('inventory_item_id', $inventoryItem->getKey())
                ->lockForUpdate()->firstOrFail();
            $stock->forceFill(['minimum_quantity' => $minimum === null ? null : (string) $minimum])->save();

            return $stock->refresh();
        });
    }
}
