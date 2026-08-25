<?php

namespace Database\Seeders;

use App\Enums\MembershipRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $password = env('DEMO_OWNER_PASSWORD');

        if (app()->isProduction() && blank($password)) {
            throw new RuntimeException('Set DEMO_OWNER_PASSWORD before running the demo seeder in production.');
        }

        DB::transaction(function () use ($password): void {
            $company = Company::query()->updateOrCreate(
                ['name' => 'Mi Pizzería'],
                ['is_active' => true],
            );

            Branch::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'name' => 'Principal'],
                ['is_active' => true],
            );

            $owner = User::query()->updateOrCreate(
                ['email' => env('DEMO_OWNER_EMAIL', 'owner@pizzeria.test')],
                [
                    'name' => 'Owner Demo',
                    'email_verified_at' => now(),
                    'password' => Hash::make($password ?: 'password'),
                ],
            );

            Membership::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'user_id' => $owner->getKey()],
                ['role' => MembershipRole::Owner, 'is_active' => true],
            );
        });

        $this->call(CatalogSeeder::class);
        $this->call(InventorySeeder::class);
        $this->call(RestaurantTableSeeder::class);
        $this->call(CashRegisterSeeder::class);
        $this->call(ExpenseCategorySeeder::class);
    }
}
