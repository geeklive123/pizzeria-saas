<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Expense;
use App\Models\User;
use App\Support\CompanyContext;

class ExpensePolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ViewExpenses, $this->context->company());
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->canForCompany(Permission::ViewExpenses, $expense->company_id);
    }

    public function create(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::CreateExpenses, $this->context->company());
    }

    public function reverse(User $user, Expense $expense): bool
    {
        return $user->canForCompany(Permission::ReverseExpenses, $expense->company_id);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return false;
    }
}
