<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

class CompanyContext
{
    private ?Company $company = null;

    private ?Membership $membership = null;

    public function setForUser(User $user, Company $company): void
    {
        $membership = $user->memberships()
            ->with('permissionOverrides')
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->first();

        if (! $company->is_active || ! $membership) {
            throw new AuthorizationException('You do not have access to this company.');
        }

        $this->company = $company;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->company = null;
        $this->membership = null;
    }

    public function hasCompany(): bool
    {
        return $this->company !== null;
    }

    public function company(): Company
    {
        return $this->company ?? throw new LogicException('No active company has been resolved.');
    }

    public function companyId(): int
    {
        return (int) $this->company()->getKey();
    }

    public function membership(): Membership
    {
        return $this->membership ?? throw new LogicException('No active company membership has been resolved.');
    }

    public function allows(Permission $permission): bool
    {
        return $this->membership()->allows($permission);
    }
}
