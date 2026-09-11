<?php

namespace App\Actions;

use App\Models\Branch;
use App\Models\Company;
use App\Models\OrderSequence;

class NextOperationalOrderNumberAction
{
    public function execute(Company $company, Branch $branch): int
    {
        OrderSequence::query()->insertOrIgnore([
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'next_number' => 1,
            'next_operational_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = OrderSequence::query()->where('company_id', $company->getKey())
            ->where('branch_id', $branch->getKey())->lockForUpdate()->firstOrFail();
        $number = $sequence->next_operational_number;
        $sequence->forceFill(['next_operational_number' => $number + 1])->save();

        return $number;
    }
}
