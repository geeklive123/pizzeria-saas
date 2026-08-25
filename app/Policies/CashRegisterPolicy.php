<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CashRegister;
use App\Models\User;
use App\Support\CompanyContext;

class CashRegisterPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ViewCash, $this->context->company());
    }

    public function view(User $user, CashRegister $register): bool
    {
        return $user->canForCompany(Permission::ViewCash, $register->company_id);
    }

    public function open(User $user, CashRegister $register): bool
    {
        return $user->canForCompany(Permission::OpenCash, $register->company_id);
    }
}
