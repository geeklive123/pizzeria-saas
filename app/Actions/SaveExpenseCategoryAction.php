<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\CompanyAccessService;

class SaveExpenseCategoryAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    public function execute(Company $company, User $user, array $data, ?ExpenseCategory $category = null): ExpenseCategory
    {
        $this->access->ensure($user, $company, Permission::ManageExpenseCategories);
        $category ??= new ExpenseCategory(['company_id' => $company->id]);
        abort_unless((int) $category->company_id === (int) $company->id, 404);
        $category->fill($data)->save();

        return $category->refresh();
    }
}
