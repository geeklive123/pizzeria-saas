<?php

namespace App\Services;

use App\Data\ReportDateRange;
use App\Models\Company;
use App\Models\UserAccessLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserAccessLogQueryService
{
    public function paginate(Company $company, ReportDateRange $range, array $filters): LengthAwarePaginator
    {
        return UserAccessLog::query()
            ->forCompany($company)
            ->with(['user:id,name', 'branch:id,name'])
            ->whereBetween('logged_in_at', [$range->fromUtc(), $range->toUtc()])
            ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->whereNull('logged_out_at'))
            ->when(($filters['status'] ?? null) === 'closed', fn ($query) => $query->whereNotNull('logged_out_at'))
            ->latest('logged_in_at')
            ->paginate(25)
            ->withQueryString();
    }
}
