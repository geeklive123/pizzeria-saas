<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            'Servicios básicos',
            'Alquiler',
            'Limpieza',
            'Mantenimiento',
            'Transporte',
            'Publicidad',
            'Personal',
            'Impuestos y tasas',
            'Otros',
        ];

        Company::query()->each(function (Company $company) use ($names): void {
            foreach ($names as $name) {
                ExpenseCategory::query()->updateOrCreate(
                    ['company_id' => $company->id, 'name' => $name],
                    ['is_active' => true],
                );
            }
        });
    }
}
