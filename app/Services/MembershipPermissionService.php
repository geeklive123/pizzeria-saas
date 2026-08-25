<?php

namespace App\Services;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class MembershipPermissionService
{
    public function ensureAssignable(User $actor, Company $company, MembershipRole $role, array $states, ?Membership $target = null): void
    {
        $actorMembership = $actor->membershipFor($company);
        if (! $actorMembership || ! $actorMembership->allows(Permission::ManageMemberships)) {
            throw new AuthorizationException('No autorizado para administrar permisos.');
        }
        if (($role === MembershipRole::Owner || $target?->role === MembershipRole::Owner)
            && $actorMembership->role !== MembershipRole::Owner) {
            throw new AuthorizationException('Solo un propietario puede administrar propietarios.');
        }
        $granted = collect($role->permissions())
            ->reject(fn (Permission $permission): bool => ($states[$permission->value] ?? null) === 'deny')
            ->map->value
            ->merge(collect($states)->filter(fn (string $state): bool => $state === 'allow')->keys())->unique();
        foreach ($granted as $permissionValue) {
            $permission = Permission::from($permissionValue);
            if (! $actorMembership->allows($permission)) {
                throw new AuthorizationException('No puedes conceder '.$permission->label().' porque no tienes ese permiso.');
            }
        }
    }

    public function sync(Membership $membership, array $states): void
    {
        $membership->permissionOverrides()->delete();
        if ($membership->role === MembershipRole::Owner) {
            return;
        }
        foreach ($states as $permissionValue => $state) {
            if ($state !== 'inherit') {
                $membership->permissionOverrides()->create([
                    'company_id' => $membership->company_id,
                    'permission' => Permission::from($permissionValue),
                    'allowed' => $state === 'allow',
                ]);
            }
        }
    }

    public function groupedCatalog(): array
    {
        $groups = [];
        foreach (Permission::cases() as $permission) {
            if ($permission === Permission::ManageCash) {
                continue;
            }
            $groups[$permission->module()] ??= ['label' => $permission->module(), 'permissions' => []];
            $groups[$permission->module()]['permissions'][] = $permission;
        }

        return $groups;
    }
}
