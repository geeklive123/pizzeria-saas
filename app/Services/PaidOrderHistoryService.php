<?php

namespace App\Services;

use App\Data\ReportDateRange;
use App\Enums\MembershipRole;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;

class PaidOrderHistoryService
{
    public function __construct(private readonly ReportDateRangeService $ranges) {}

    public function canFilter(User $user, Company $company): bool
    {
        $membership = $user->membershipFor($company);

        return $membership !== null
            && in_array($membership->role, [MembershipRole::Owner, MembershipRole::Admin], true);
    }

    public function range(User $user, Company $company, array $filters): ReportDateRange
    {
        return $this->ranges->from($this->canFilter($user, $company) ? $filters : ['preset' => 'today']);
    }

    public function allows(User $user, Order $order): bool
    {
        if ($order->status !== OrderStatus::Paid) {
            return true;
        }

        $membership = $user->membershipFor($order->company_id);
        if (! $membership || ! in_array($membership->role, [MembershipRole::Cashier, MembershipRole::Waiter], true)) {
            return true;
        }

        if ($order->closed_at === null) {
            return false;
        }

        $today = $this->ranges->from(['preset' => 'today']);

        return $order->closed_at->betweenIncluded($today->fromUtc(), $today->toUtc());
    }
}
