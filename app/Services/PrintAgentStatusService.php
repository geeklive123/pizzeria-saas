<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PrintAgent;

class PrintAgentStatusService
{
    public function current(Company $company, Branch $branch): ?PrintAgent
    {
        return PrintAgent::query()->forCompany($company)->forBranch($branch)
            ->where('is_active', true)
            ->latest('last_seen_at')
            ->first();
    }

    public function isOnline(?PrintAgent $agent): bool
    {
        return $agent?->last_seen_at?->gte(
            now()->subSeconds(max(5, (int) config('thermal-printing.agent.online_threshold_seconds', 30))),
        ) ?? false;
    }
}
