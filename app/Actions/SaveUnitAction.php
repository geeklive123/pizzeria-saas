<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\UnitType;
use App\Models\Company;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class SaveUnitAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    public function execute(Company $company, User $user, array $data, ?Unit $unit = null): Unit
    {
        $this->access->ensure($user, $company, Permission::ManageCatalog);
        $unit ??= new Unit;
        abort_if($unit->exists && (int) $unit->company_id !== (int) $company->getKey(), 404);

        return DB::transaction(function () use ($company, $data, $unit): Unit {
            if ($unit->exists) {
                $unit = Unit::query()->lockForUpdate()->findOrFail($unit->getKey());
                $newType = UnitType::from($data['type']);
                if ($unit->type !== $newType
                    && ($unit->ingredients()->exists()
                        || $unit->inventoryItems()->exists()
                        || $unit->purchaseItems()->exists())) {
                    throw new DomainException('No se puede cambiar el tipo de una unidad que ya está en uso.');
                }
            }

            $unit->fill([
                'company_id' => $company->getKey(),
                'name' => $data['name'],
                'symbol' => $data['symbol'],
                'type' => UnitType::from($data['type']),
                'is_active' => $data['is_active'],
            ])->save();

            return $unit->refresh();
        });
    }
}
