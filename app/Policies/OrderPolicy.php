<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;
use App\Support\CompanyContext;

class OrderPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ViewOrders, $this->context->company());
    }

    public function view(User $user, Order $order): bool
    {
        return $user->canForCompany(Permission::ViewOrders, $order->company_id);
    }

    public function create(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ManageOrders, $this->context->company());
    }

    public function update(User $user, Order $order): bool
    {
        return $user->canForCompany(Permission::ManageOrders, $order->company_id);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->canForCompany(Permission::CancelOrders, $order->company_id);
    }

    public function reprintKitchen(User $user, Order $order): bool
    {
        return $user->canForCompany(Permission::ManageOrders, $order->company_id)
            || $user->canForCompany(Permission::ManageKitchen, $order->company_id);
    }

    public function reprintCustomerTicket(User $user, Order $order): bool
    {
        return $user->canForCompany(Permission::CreatePayments, $order->company_id);
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }
}
