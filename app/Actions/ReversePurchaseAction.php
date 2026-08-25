<?php

namespace App\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\Permission;
use App\Enums\PurchaseStatus;
use App\Models\InventoryMovement;
use App\Models\Purchase;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReversePurchaseAction
{
    public function __construct(
        private readonly ReverseInventoryMovementAction $reverseMovement,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(Purchase $purchase, User $user, string $reason): Purchase
    {
        $this->access->ensure($user, $purchase->company, Permission::ManagePurchases);

        return DB::transaction(function () use ($purchase, $user, $reason): Purchase {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->getKey());

            if ($purchase->status !== PurchaseStatus::Posted) {
                throw new DomainException('Only a posted purchase can be reversed.');
            }

            $movements = InventoryMovement::query()
                ->where('company_id', $purchase->company_id)
                ->where('reference_type', Purchase::class)
                ->where('reference_id', $purchase->getKey())
                ->where('type', InventoryMovementType::Purchase)
                ->orderBy('inventory_item_id')
                ->lockForUpdate()
                ->get();

            if ($movements->isEmpty()) {
                throw new DomainException('The posted purchase has no inventory movements.');
            }

            foreach ($movements as $movement) {
                $this->reverseMovement->execute($movement, $user, $reason);
            }

            $purchase->update(['status' => PurchaseStatus::Reversed]);

            return $purchase->refresh();
        });
    }
}
