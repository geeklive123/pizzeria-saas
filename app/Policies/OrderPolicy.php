<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;
use App\Services\PaidOrderHistoryService;
use App\Support\CompanyContext;

class OrderPolicy
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly PaidOrderHistoryService $paidHistory,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasCompany() && $user->canForCompany(Permission::ViewOrders, $this->context->company());
    }

    public function view(User $user, Order $order): bool
    {
        return $user->canForCompany(Permission::ViewOrders, $order->company_id)
            && $this->paidHistory->allows($user, $order);
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
        $membership = $user->membershipFor($order->company_id);

        return $membership !== null
            && in_array($membership->role, [MembershipRole::Owner, MembershipRole::Admin], true)
            && $membership->allows(Permission::CancelOrders);
    }

    public function cancelPaid(User $user, Order $order): bool
    {
        return $this->cancel($user, $order)
            && $user->canForCompany(Permission::ReversePayments, $order->company_id);
    }

    public function cancelItems(User $user, Order $order): bool
    {
        $membership = $user->membershipFor($order->company_id);

        return $membership !== null
            && in_array($membership->role, [MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Cashier], true)
            && $membership->allows(Permission::CancelOrders);
    }

    public function restoreCancellation(User $user, Order $order): bool
    {
        $membership = $user->membershipFor($order->company_id);

        return $membership !== null
            && in_array($membership->role, [MembershipRole::Owner, MembershipRole::Admin], true)
            && $membership->allows(Permission::RestoreCancelledOrders);
    }

    public function reprintKitchen(User $user, Order $order): bool
    {
        return ($user->canForCompany(Permission::ManageOrders, $order->company_id)
            || $user->canForCompany(Permission::ManageKitchen, $order->company_id))
            && $this->paidHistory->allows($user, $order);
    }

    public function reprintCustomerTicket(User $user, Order $order): bool
    {
        return $user->canForCompany(Permission::CreatePayments, $order->company_id)
            && $this->paidHistory->allows($user, $order);
    }

    public function printAccount(User $user, Order $order): bool
    {
        return $user->canForCompany(Permission::ManageOrders, $order->company_id);
    }

    public function transferPayments(User $user, Order $order): bool
    {
        $membership = $user->membershipFor($order->company_id);

        return $membership !== null
            && in_array($membership->role, [MembershipRole::Owner, MembershipRole::Admin], true)
            && $membership->allows(Permission::TransferCashSessionOperations);
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }
}
