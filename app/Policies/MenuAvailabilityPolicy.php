<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\CompanyContext;

class MenuAvailabilityPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function view(User $user): bool
    {
        return $this->context->hasCompany()
            && $this->context->allows(Permission::ViewMenuAvailability);
    }
}
