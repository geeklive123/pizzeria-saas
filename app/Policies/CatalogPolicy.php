<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Model;

class CatalogPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany()
            && $user->canForCompany(Permission::ViewCatalog, $this->context->company());
    }

    public function view(User $user, Model $resource): bool
    {
        return $user->canForCompany(Permission::ViewCatalog, $resource->getAttribute('company_id'));
    }

    public function create(User $user): bool
    {
        return $this->context->hasCompany()
            && $user->canForCompany(Permission::ManageCatalog, $this->context->company());
    }

    public function update(User $user, Model $resource): bool
    {
        return $user->canForCompany(Permission::ManageCatalog, $resource->getAttribute('company_id'));
    }

    public function delete(User $user, Model $resource): bool
    {
        return $this->update($user, $resource);
    }
}
