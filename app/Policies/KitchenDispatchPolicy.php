<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\KitchenDispatch;
use App\Models\User;
use App\Support\CompanyContext;

class KitchenDispatchPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany()
            && $user->canForCompany(Permission::ViewKitchen, $this->context->company());
    }

    public function view(User $user, KitchenDispatch $dispatch): bool
    {
        return $user->canForCompany(Permission::ViewKitchen, $dispatch->company_id);
    }

    public function update(User $user, KitchenDispatch $dispatch): bool
    {
        return $user->canForCompany(Permission::ManageKitchen, $dispatch->company_id);
    }
}
