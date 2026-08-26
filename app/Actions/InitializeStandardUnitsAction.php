<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\UnitType;
use App\Models\Company;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InitializeStandardUnitsAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    /** @return Collection<int, Unit> */
    public function execute(Company $company, User $user): Collection
    {
        $this->access->ensure($user, $company, Permission::ManageCatalog);

        return DB::transaction(function () use ($company): Collection {
            return collect(self::definitions())->map(function (array $definition) use ($company): Unit {
                $unit = Unit::query()->forCompany($company)
                    ->where('symbol', $definition['symbol'])->lockForUpdate()->first();

                if ($unit && $unit->type !== $definition['type']) {
                    throw new DomainException('El símbolo '.$definition['symbol'].' ya existe con un tipo incompatible.');
                }

                if (! $unit) {
                    $nameConflict = Unit::query()->forCompany($company)
                        ->where('name', $definition['name'])->lockForUpdate()->exists();
                    if ($nameConflict) {
                        throw new DomainException('La unidad '.$definition['name'].' ya existe con otro símbolo.');
                    }

                    $unit = new Unit([
                        'company_id' => $company->getKey(),
                        ...$definition,
                    ]);
                }

                $unit->is_active = true;
                $unit->save();

                return $unit->refresh();
            });
        });
    }

    /** @return list<array{name: string, symbol: string, type: UnitType}> */
    public static function definitions(): array
    {
        return [
            ['name' => 'Gramo', 'symbol' => 'g', 'type' => UnitType::Weight],
            ['name' => 'Kilogramo', 'symbol' => 'kg', 'type' => UnitType::Weight],
            ['name' => 'Mililitro', 'symbol' => 'ml', 'type' => UnitType::Volume],
            ['name' => 'Litro', 'symbol' => 'L', 'type' => UnitType::Volume],
            ['name' => 'Unidad', 'symbol' => 'u', 'type' => UnitType::Unit],
        ];
    }
}
