<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\OnlineOrderRequest;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\CompanyContext;

class OnlineOrderRequestPolicy
{
    public function __construct(private readonly CompanyContext $company, private readonly BranchContext $branch) {}

    public function viewAny(User $user): bool
    {
        return $this->company->hasCompany() && $this->branch->hasBranch()
            && $user->canForCompany(Permission::ViewOrders, $this->company->company());
    }

    public function view(User $user, OnlineOrderRequest $request): bool
    {
        return $this->viewAny($user) && (int) $request->company_id === $this->company->companyId()
            && (int) $request->branch_id === (int) $this->branch->branch()->id;
    }

    public function update(User $user, OnlineOrderRequest $request): bool
    {
        return $this->view($user, $request) && $user->canForCompany(Permission::ManageOrders, $request->company_id);
    }

    public function cancel(User $user, OnlineOrderRequest $request): bool
    {
        return $this->view($user, $request) && $user->canForCompany(Permission::CancelOrders, $request->company_id);
    }
}
