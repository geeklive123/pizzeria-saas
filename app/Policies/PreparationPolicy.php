<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Preparation;
use App\Models\User;
use App\Support\CompanyContext;

class PreparationPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany()
            && $user->canForCompany(Permission::ViewInventory, $this->context->company())
            && $user->canForCompany(Permission::ViewRecipes, $this->context->company());
    }

    public function view(User $user, Preparation $preparation): bool
    {
        return $user->canForCompany(Permission::ViewInventory, $preparation->company_id)
            && $user->canForCompany(Permission::ViewRecipes, $preparation->company_id);
    }

    public function create(User $user): bool
    {
        return $this->context->hasCompany()
            && $user->canForCompany(Permission::ManageInventory, $this->context->company());
    }

    public function update(User $user, Preparation $preparation): bool
    {
        return $user->canForCompany(Permission::ManageInventory, $preparation->company_id);
    }

    public function produce(User $user, Preparation $preparation): bool
    {
        return $this->update($user, $preparation)
            || $user->canForCompany(Permission::ManageKitchen, $preparation->company_id);
    }

    public function reverse(User $user, Preparation $preparation): bool
    {
        return $this->update($user, $preparation);
    }
}
