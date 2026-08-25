<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Payment;
use App\Models\User;
use App\Support\CompanyContext;

class PaymentPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function create(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::CreatePayments, $this->context->company());
    }

    public function reverse(User $user, Payment $payment): bool
    {
        return $user->canForCompany(Permission::ReversePayments, $payment->company_id);
    }
}
