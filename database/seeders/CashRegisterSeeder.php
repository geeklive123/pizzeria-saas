<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use Illuminate\Database\Seeder;

class CashRegisterSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('name', 'Mi Pizzería')->first();
        $branch = $company ? Branch::query()->where('company_id', $company->id)->where('name', 'Principal')->first() : null;
        if (! $company || ! $branch) {
            return;
        }

        CashRegister::query()->updateOrCreate(
            ['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja Principal'],
            ['is_active' => true],
        );
    }
}
