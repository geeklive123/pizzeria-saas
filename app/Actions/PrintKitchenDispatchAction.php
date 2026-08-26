<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\PrintAttemptStatus;
use App\Models\KitchenDispatch;
use App\Models\PrintAttempt;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\ThermalPrintingService;
use DomainException;

class PrintKitchenDispatchAction
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly ThermalPrintingService $printing,
    ) {}

    public function execute(KitchenDispatch $dispatch, User $user): PrintAttempt
    {
        $dispatch->loadMissing('company');
        $this->access->ensure($user, $dispatch->company, Permission::ManageOrders);
        if ($dispatch->order()->where('branch_id', $dispatch->branch_id)->doesntExist()) {
            throw new DomainException('La comanda no pertenece al pedido activo.');
        }

        $lastAttempt = $dispatch->printAttempts()->latest('attempted_at')->first();
        if ($lastAttempt?->status === PrintAttemptStatus::Failed) {
            return $this->printing->retry($lastAttempt);
        }

        return $this->printing->kitchen($dispatch, $user, $lastAttempt !== null);
    }
}
