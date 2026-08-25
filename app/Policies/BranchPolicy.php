<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyContext;

class BranchPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany()
            && $user->canForCompany(Permission::ViewBranches, $this->context->company());
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->canForCompany(Permission::ViewBranches, $branch->company_id);
    }

    public function create(User $user, Company $company): bool
    {
        return $user->canForCompany(Permission::ManageBranches, $company);
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->canForCompany(Permission::ManageBranches, $branch->company_id);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->canForCompany(Permission::ManageBranches, $branch->company_id);
    }
}
