<?php

namespace App\Actions;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Membership;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\MembershipPermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UpdateMembershipUserAction
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly MembershipPermissionService $permissions,
    ) {}

    public function execute(Membership $membership, User $actor, array $data): Membership
    {
        $this->access->ensure($actor, $membership->company, Permission::ManageMemberships);
        $role = MembershipRole::from($data['role']);
        $this->permissions->ensureAssignable($actor, $membership->company, $role, $data['permissions'] ?? [], $membership);

        return DB::transaction(function () use ($membership, $data, $role): Membership {
            Membership::query()->where('company_id', $membership->company_id)->orderBy('id')->lockForUpdate()->get();
            $membership = Membership::query()->with(['company', 'user'])->lockForUpdate()->findOrFail($membership->id);

            $userData = ['name' => $data['name'] ?? $membership->user->name];
            if (filled($data['password'] ?? null)) {
                $userData['password'] = Hash::make($data['password']);
            }
            $membership->user->update($userData);
            $membership->update([
                'role' => $role,
                'is_active' => $data['is_active'],
            ]);
            $this->permissions->sync($membership, $data['permissions'] ?? []);

            return $membership->refresh()->load(['user', 'permissionOverrides']);
        });
    }
}
