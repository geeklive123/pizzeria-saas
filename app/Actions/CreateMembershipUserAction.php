<?php

namespace App\Actions;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\MembershipPermissionService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateMembershipUserAction
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly MembershipPermissionService $permissions,
    ) {}

    public function execute(Company $company, User $actor, array $data): Membership
    {
        $this->access->ensure($actor, $company, Permission::ManageMemberships);
        $role = MembershipRole::from($data['role']);
        $this->permissions->ensureAssignable($actor, $company, $role, $data['permissions'] ?? []);

        return DB::transaction(function () use ($company, $data, $role): Membership {
            $email = mb_strtolower(trim($data['email']));
            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            if ($user && Membership::query()->where('company_id', $company->id)->where('user_id', $user->id)->exists()) {
                throw new DomainException('El usuario ya pertenece a esta empresa.');
            }

            if (! $user) {
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $email,
                    'password' => Hash::make($data['password']),
                ]);
            }

            $membership = Membership::query()->create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'role' => $role,
                'is_active' => $data['is_active'],
            ]);
            $this->permissions->sync($membership, $data['permissions'] ?? []);

            return $membership->load(['user', 'permissionOverrides']);
        });
    }
}
