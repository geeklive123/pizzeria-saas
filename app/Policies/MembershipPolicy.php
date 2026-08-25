<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Membership;
use App\Models\User;
use App\Support\CompanyContext;

class MembershipPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany()
            && $user->canForCompany(Permission::ViewMemberships, $this->context->company());
    }

    public function view(User $user, Membership $membership): bool
    {
        return $user->canForCompany(Permission::ViewMemberships, $membership->company_id);
    }

    public function create(User $user): bool
    {
        return $this->context->hasCompany()
            && $user->canForCompany(Permission::ManageMemberships, $this->context->company());
    }

    public function update(User $user, Membership $membership): bool
    {
        $actorMembership = $user->membershipFor($membership->company_id);

        return $actorMembership?->allows(Permission::ManageMemberships) === true
            && ($membership->role !== MembershipRole::Owner || $actorMembership->role === MembershipRole::Owner);
    }

    public function delete(User $user, Membership $membership): bool
    {
        return $this->update($user, $membership);
    }
}
