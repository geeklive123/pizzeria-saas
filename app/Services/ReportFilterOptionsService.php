<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\ProductVariant;

class ReportFilterOptionsService
{
    public function for(Company $company, string $section): array
    {
        return [
            'categories' => $section === 'expenses'
                ? ExpenseCategory::query()->forCompany($company)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'productCategories' => in_array($section, ['sales', 'products'], true)
                ? Category::query()->forCompany($company)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'products' => in_array($section, ['sales', 'products'], true)
                ? Product::query()->forCompany($company)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'variants' => in_array($section, ['sales', 'products'], true)
                ? ProductVariant::query()->forCompany($company)->with('product:id,name')->orderBy('name')->get(['id', 'product_id', 'name'])
                : collect(),
        ];
    }
}
