<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OnlineSystemIdentityService
{
    private const UNUSABLE_PASSWORD = '!non-interactive-system-identity!';

    public function isNonInteractiveForBranch(User $user, int $companyId, int $branchId): bool
    {
        return DB::table('online_system_identities')->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('user_id', $user->id)->exists()
            && DB::table('users')->where('id', $user->id)->where('password', self::UNUSABLE_PASSWORD)->exists()
            && ! DB::table('memberships')->where('user_id', $user->id)->exists();
    }

    public function forBranch(Company $company, Branch $branch): User
    {
        if (! config('storefront.online_dynamic_qr_enabled')) {
            throw new DomainException('El QR online está desactivado.');
        }

        return DB::transaction(function () use ($company, $branch): User {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $branch = Branch::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($branch->id);
            if (! $company->is_active || ! $branch->is_active) {
                throw new DomainException('La empresa o sucursal no está activa.');
            }
            $identity = DB::table('online_system_identities')->where('company_id', $company->id)->where('branch_id', $branch->id)->first();
            if ($identity) {
                $user = User::query()->findOrFail($identity->user_id);
                if ($user->getRawOriginal('password') !== self::UNUSABLE_PASSWORD || $user->memberships()->exists()) {
                    throw new DomainException('La identidad interna requiere revisión.');
                }

                return $user;
            }

            // Not a password hash: even a guessed value cannot authenticate through the web provider.
            $userId = DB::table('users')->insertGetId([
                'name' => 'Sistema — Tienda online',
                'email' => 'online-system+'.Str::uuid().'@invalid.invalid',
                'password' => self::UNUSABLE_PASSWORD,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('online_system_identities')->insert([
                'company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $userId, 'created_at' => now(),
            ]);

            return User::query()->findOrFail($userId);
        }, 3);
    }
}
