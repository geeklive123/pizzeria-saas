<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Collection;

class MembershipQueryService
{
    public function forCompany(Company $company): Collection
    {
        return $company->memberships()->with('user')->orderBy('id')->get();
    }
}
