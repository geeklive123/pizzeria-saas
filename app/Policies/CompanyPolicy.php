<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function view(User $user, Company $company): bool
    {
        return $user->canForCompany(Permission::ViewCompany, $company);
    }

    public function update(User $user, Company $company): bool
    {
        return $user->canForCompany(Permission::ManageCompany, $company);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->canForCompany(Permission::ManageCompany, $company);
    }
}
