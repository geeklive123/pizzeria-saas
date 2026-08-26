<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Category;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyAccessService;
use Illuminate\Support\Facades\DB;

class SaveCategoryAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    public function execute(Company $company, User $user, array $data, ?Category $category = null): Category
    {
        $this->access->ensure($user, $company, Permission::ManageCatalog);
        $category ??= new Category;
        abort_if($category->exists && (int) $category->company_id !== (int) $company->getKey(), 404);

        return DB::transaction(function () use ($company, $data, $category): Category {
            $category->fill([
                'company_id' => $company->getKey(),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'sort_order' => $data['sort_order'],
                'is_active' => $data['is_active'],
            ])->save();

            return $category->refresh();
        });
    }
}
