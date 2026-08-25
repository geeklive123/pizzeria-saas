<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\CompanyContext;

class ExpenseCategoryPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ManageExpenseCategories, $this->context->company());
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ExpenseCategory $category): bool
    {
        return $user->canForCompany(Permission::ManageExpenseCategories, $category->company_id);
    }

    public function delete(User $user, ExpenseCategory $category): bool
    {
        return false;
    }
}
