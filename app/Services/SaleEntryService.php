<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\RestaurantTable;
use Illuminate\Support\Collection;

class SaleEntryService
{
    public function activeTables(Company $company, Branch $branch): Collection
    {
        return RestaurantTable::query()->forCompany($company)->forBranch($branch)
            ->where('is_active', true)->with('openOrder')->orderBy('sort_order')->orderBy('name')->get();
    }
}
