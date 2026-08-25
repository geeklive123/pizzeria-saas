<?php

namespace App\Actions;

use App\Models\Branch;
use App\Models\Company;
use App\Models\OrderSequence;

class NextOrderNumberAction
{
    public function execute(Company $company, Branch $branch): int
    {
        OrderSequence::query()->insertOrIgnore([
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = OrderSequence::query()->where('company_id', $company->getKey())
            ->where('branch_id', $branch->getKey())->lockForUpdate()->firstOrFail();
        $number = $sequence->next_number;
        $sequence->forceFill(['next_number' => $number + 1])->save();

        return $number;
    }
}
