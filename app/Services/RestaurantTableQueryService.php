<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\RestaurantTable;
use Illuminate\Support\Collection;

class RestaurantTableQueryService
{
    public function forManagement(Company $company, Branch $branch, bool $includeInactive): Collection
    {
        return RestaurantTable::query()->forCompany($company)->forBranch($branch)
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->with(['openOrder.items'])->orderBy('sort_order')->orderBy('name')->get();
    }
}
