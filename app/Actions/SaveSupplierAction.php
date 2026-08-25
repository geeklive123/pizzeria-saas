<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CompanyAccessService;

class SaveSupplierAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    public function execute(Company $company, User $user, array $data, ?Supplier $supplier = null): Supplier
    {
        $this->access->ensure($user, $company, Permission::ManageSuppliers);
        $supplier ??= new Supplier(['company_id' => $company->id]);
        abort_unless((int) $supplier->company_id === (int) $company->id, 404);
        $supplier->fill($data)->save();

        return $supplier->refresh();
    }
}
