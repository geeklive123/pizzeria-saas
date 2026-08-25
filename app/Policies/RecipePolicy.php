<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Model;

class RecipePolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany()
            && $user->canForCompany(Permission::ViewRecipes, $this->context->company());
    }

    public function view(User $user, Model $resource): bool
    {
        return $user->canForCompany(Permission::ViewRecipes, $resource->getAttribute('company_id'));
    }

    public function create(User $user): bool
    {
        return $this->context->hasCompany()
            && $user->canForCompany(Permission::ManageRecipes, $this->context->company());
    }

    public function update(User $user, Model $resource): bool
    {
        return $user->canForCompany(Permission::ManageRecipes, $resource->getAttribute('company_id'));
    }

    public function delete(User $user, Model $resource): bool
    {
        return $this->update($user, $resource);
    }
}
