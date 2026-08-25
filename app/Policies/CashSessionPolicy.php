<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CashSession;
use App\Models\User;
use App\Support\CompanyContext;

class CashSessionPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ViewCash, $this->context->company());
    }

    public function view(User $user, CashSession $session): bool
    {
        return $user->canForCompany(Permission::ViewCash, $session->company_id);
    }

    public function update(User $user, CashSession $session): bool
    {
        return $user->canForCompany(Permission::RegisterManualCashMovements, $session->company_id)
            && ((int) $session->opened_by === (int) $user->id
                || $user->canForCompany(Permission::AuthorizeCashWithdrawals, $session->company_id));
    }

    public function close(User $user, CashSession $session): bool
    {
        return $user->canForCompany(Permission::CloseCash, $session->company_id)
            && ((int) $session->opened_by === (int) $user->id
                || $user->canForCompany(Permission::AuthorizeCashWithdrawals, $session->company_id));
    }

    public function withdraw(User $user, CashSession $session): bool
    {
        return $user->canForCompany(Permission::AuthorizeCashWithdrawals, $session->company_id);
    }
}
