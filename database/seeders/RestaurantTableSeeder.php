<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\RestaurantTable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RestaurantTableSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $company = Company::query()->where('name', 'Mi Pizzería')->firstOrFail();
        $branch = Branch::query()->where('company_id', $company->id)->where('name', 'Principal')->firstOrFail();
        DB::transaction(function () use ($company, $branch): void {
            foreach (range(1, 6) as $number) {
                RestaurantTable::query()->updateOrCreate(
                    ['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => "Mesa $number"],
                    ['capacity' => 4, 'sort_order' => $number, 'is_active' => true],
                );
            }
        });
    }
}
