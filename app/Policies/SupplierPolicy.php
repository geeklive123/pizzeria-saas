<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CompanyContext;

class SupplierPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ViewSuppliers, $this->context->company());
    }

    public function create(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ManageSuppliers, $this->context->company());
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->canForCompany(Permission::ManageSuppliers, $supplier->company_id);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return false;
    }
}
