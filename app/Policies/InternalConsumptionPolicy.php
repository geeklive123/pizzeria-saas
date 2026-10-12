<?php

namespace App\Policies;

use App\Enums\InternalConsumptionStatus;
use App\Enums\Permission;
use App\Models\InternalConsumption;
use App\Models\User;
use App\Support\CompanyContext;

class InternalConsumptionPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ViewInternalConsumptions, $this->context->company());
    }

    public function view(User $user, InternalConsumption $consumption): bool
    {
        return $user->canForCompany(Permission::ViewInternalConsumptions, $consumption->company_id);
    }

    public function create(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::CreateInternalConsumptions, $this->context->company());
    }

    public function print(User $user, InternalConsumption $consumption): bool
    {
        return $consumption->status === InternalConsumptionStatus::Confirmed
            && $user->canForCompany(Permission::PrintInternalConsumptions, $consumption->company_id);
    }

    public function cancel(User $user, InternalConsumption $consumption): bool
    {
        return $user->canForCompany(Permission::CancelInternalConsumptions, $consumption->company_id);
    }

    public function report(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ViewInternalConsumptionReports, $this->context->company());
    }
}
