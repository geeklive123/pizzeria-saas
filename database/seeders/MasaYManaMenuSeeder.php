<?php

namespace Database\Seeders;

use App\Actions\InitializeMasaYManaBusinessAction;
use App\Enums\MembershipRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use Illuminate\Database\Seeder;
use RuntimeException;

class MasaYManaMenuSeeder extends Seeder
{
    private const COMPANY_NAME = 'Mi Pizzería';

    private const BRANCH_NAME = 'Principal';

    public function run(InitializeMasaYManaBusinessAction $initialize): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'En producción usa exclusivamente php artisan app:initialize-masa-y-mana.',
            );
        }

        $company = Company::query()->where('name', self::COMPANY_NAME)->firstOrFail();
        $branch = Branch::query()->forCompany($company)->where('name', self::BRANCH_NAME)->firstOrFail();
        $actor = Membership::query()
            ->where('company_id', $company->getKey())
            ->where('role', MembershipRole::Owner->value)
            ->where('is_active', true)
            ->with('user')->firstOrFail()->user;

        $initialize->execute($company, $branch, $actor);
    }
}
