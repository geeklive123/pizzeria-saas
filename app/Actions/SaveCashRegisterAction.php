<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class SaveCashRegisterAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    public function execute(
        Company $company,
        Branch $branch,
        User $user,
        array $data,
        ?CashRegister $register = null,
    ): CashRegister {
        $this->access->ensure($user, $company, Permission::ManageCash);

        abort_unless((int) $branch->company_id === (int) $company->getKey(), 404);
        if ($register) {
            abort_unless(
                (int) $register->company_id === (int) $company->getKey()
                && (int) $register->branch_id === (int) $branch->getKey(),
                404,
            );
        }

        return DB::transaction(function () use ($company, $branch, $data, $register): CashRegister {
            if ($register) {
                $register = CashRegister::query()->lockForUpdate()->findOrFail($register->getKey());
                if (! $data['is_active'] && $register->activeSession()->exists()) {
                    throw new DomainException('No se puede desactivar una caja con un turno abierto.');
                }
            } else {
                $register = new CashRegister;
            }

            $register->fill([
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'name' => $data['name'],
                'is_active' => $data['is_active'],
            ])->save();

            return $register->refresh();
        });
    }
}
