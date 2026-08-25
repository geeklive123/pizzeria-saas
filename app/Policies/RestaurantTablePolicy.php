<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Support\CompanyContext;

class RestaurantTablePolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ViewTables, $this->context->company());
    }

    public function view(User $user, RestaurantTable $table): bool
    {
        return $user->canForCompany(Permission::ViewTables, $table->company_id);
    }

    public function create(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ManageTables, $this->context->company());
    }

    public function update(User $user, RestaurantTable $table): bool
    {
        return $user->canForCompany(Permission::ManageTables, $table->company_id);
    }

    public function delete(User $user, RestaurantTable $table): bool
    {
        return false;
    }
}
