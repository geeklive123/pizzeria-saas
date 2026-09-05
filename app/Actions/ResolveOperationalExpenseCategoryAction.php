<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\ExpenseCategory;

class ResolveOperationalExpenseCategoryAction
{
    public const NAME = 'Gastos operativos';

    public function execute(Company $company): ExpenseCategory
    {
        return ExpenseCategory::query()->createOrFirst(
            ['company_id' => $company->id, 'name' => self::NAME],
            ['description' => 'Categoría predeterminada para egresos rápidos desde Caja.', 'is_active' => true],
        );
    }
}
