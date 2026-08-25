<?php

namespace App\Actions;

use App\Enums\MembershipRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProvisionProductionOwnerAction
{
    /**
     * @param  array{name:string,email:string,password:string,company:string,branch:string}  $data
     */
    public function execute(array $data, bool $allowExistingOwner = false): Membership
    {
        $email = mb_strtolower(trim($data['email']));
        $companyName = trim($data['company']);
        $branchName = trim($data['branch']);

        return DB::transaction(function () use ($data, $email, $companyName, $branchName, $allowExistingOwner): Membership {
            $companies = Company::query()
                ->where('name', $companyName)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($companies->count() > 1) {
                throw new DomainException('Hay más de una empresa con ese nombre. El aprovisionamiento fue cancelado.');
            }

            $company = $companies->first();
            if ($company && ! $company->is_active) {
                throw new DomainException('La empresa existente está inactiva. No se modificó su estado.');
            }

            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($user && ! Hash::check($data['password'], $user->password)) {
                throw new DomainException('El correo ya existe y la contraseña no autentica esa cuenta.');
            }

            if ($company) {
                Membership::query()
                    ->where('company_id', $company->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $existingMembership = $user?->memberships()
                    ->where('company_id', $company->id)
                    ->first();

                if ($existingMembership) {
                    if (! $existingMembership->isActiveOwner()) {
                        throw new DomainException('La cuenta ya pertenece a la empresa con un rol o estado diferente.');
                    }

                    $this->ensureBranch($company, $branchName);

                    return $existingMembership->load(['company', 'user']);
                }

                $hasActiveOwner = Membership::query()
                    ->where('company_id', $company->id)
                    ->where('role', MembershipRole::Owner->value)
                    ->where('is_active', true)
                    ->exists();

                if ($hasActiveOwner && ! $allowExistingOwner) {
                    throw new DomainException('La empresa ya tiene un owner activo. Use --allow-existing-owner solo si desea agregar otro explícitamente.');
                }
            } else {
                $company = Company::query()->create([
                    'name' => $companyName,
                    'is_active' => true,
                ]);
            }

            $this->ensureBranch($company, $branchName);

            $user ??= User::query()->create([
                'name' => trim($data['name']),
                'email' => $email,
                'password' => Hash::make($data['password']),
            ]);

            return Membership::query()->create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'role' => MembershipRole::Owner,
                'is_active' => true,
            ])->load(['company', 'user']);
        });
    }

    private function ensureBranch(Company $company, string $branchName): Branch
    {
        $branch = Branch::query()
            ->where('company_id', $company->id)
            ->where('name', $branchName)
            ->lockForUpdate()
            ->first();

        if ($branch && ! $branch->is_active) {
            throw new DomainException('La sucursal existente está inactiva. No se modificó su estado.');
        }

        return $branch ?? Branch::query()->create([
            'company_id' => $company->id,
            'name' => $branchName,
            'is_active' => true,
        ]);
    }
}
