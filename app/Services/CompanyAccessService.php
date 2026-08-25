<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class CompanyAccessService
{
    public function ensure(User $user, Company $company, Permission $permission): void
    {
        if (! $company->is_active || ! $user->canForCompany($permission, $company)) {
            throw new AuthorizationException('You are not authorized for this company operation.');
        }
    }
}
