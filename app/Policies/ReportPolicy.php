<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\CompanyContext;

class ReportPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function view(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ViewReports, $this->context->company());
    }

    public function financial(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ViewFinancialReports, $this->context->company());
    }

    public function export(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ExportReports, $this->context->company());
    }
}
