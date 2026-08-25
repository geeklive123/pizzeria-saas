<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Company;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class SaveRestaurantTableAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    public function execute(Company $company, Branch $branch, User $actor, array $data, ?RestaurantTable $table = null): RestaurantTable
    {
        $this->access->ensure($actor, $company, Permission::ManageTables);

        if ((int) $branch->company_id !== (int) $company->id
            || ($table && ((int) $table->company_id !== (int) $company->id || (int) $table->branch_id !== (int) $branch->id))) {
            throw new DomainException('La mesa y la sucursal deben pertenecer a la empresa activa.');
        }

        return DB::transaction(function () use ($company, $branch, $data, $table): RestaurantTable {
            if ($table) {
                $table = RestaurantTable::query()->lockForUpdate()->findOrFail($table->id);
                if (! $data['is_active'] && $table->openOrder()->exists()) {
                    throw new DomainException('No puedes desactivar una mesa con una cuenta abierta.');
                }
            } else {
                $table = new RestaurantTable;
                $table->company_id = $company->id;
                $table->branch_id = $branch->id;
            }

            $table->fill([
                'name' => $data['name'],
                'capacity' => $data['capacity'],
                'sort_order' => $data['sort_order'],
                'is_active' => $data['is_active'],
            ])->save();

            return $table->refresh();
        });
    }
}
