<?php

namespace App\Actions;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

class UpdateSettingsAction
{
    public function execute(Company $company, Branch $branch, array $data): void
    {
        DB::transaction(function () use ($company, $branch, $data): void {
            $company->update($data['company']);
            $branch->update($data['branch']);
        });
    }
}
