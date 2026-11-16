<?php

namespace App\Services;

use App\Enums\CashSessionStatus;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\User;

class CurrentCashSessionService
{
    public function forBranch(Company $company, Branch $branch, bool $lock = false): ?CashSession
    {
        $query = CashSession::query()->forCompany($company)->forBranch($branch)
            ->where('status', CashSessionStatus::Open->value)->whereNotNull('active_cash_register_id')
            ->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function forUser(Company $company, Branch $branch, User $user): ?CashSession
    {
        return CashSession::query()
            ->forCompany($company)
            ->forBranch($branch)
            ->whereNotNull('active_cash_register_id')
            ->where('opened_by', $user->id)
            ->first();
    }
}
