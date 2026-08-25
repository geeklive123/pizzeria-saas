<?php

namespace App\Services;

use App\Data\ReportDateRange;
use App\Enums\ExpenseStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;

class UserOperationsReportService
{
    public function data(Company $company, Branch $branch, ReportDateRange $range): Collection
    {
        $users = User::query()->whereHas('memberships', fn ($query) => $query->where('company_id', $company->id)->where('is_active', true))->get(['id', 'name'])->keyBy('id');
        $orders = Order::query()->forCompany($company)->forBranch($branch)->where('status', OrderStatus::Paid->value)->whereBetween('closed_at', [$range->fromUtc(), $range->toUtc()])->get()->countBy('created_by');
        $payments = Payment::query()->forCompany($company)->where('branch_id', $branch->id)->where('status', PaymentStatus::Completed->value)->whereBetween('paid_at', [$range->fromUtc(), $range->toUtc()])->get()->countBy('received_by');
        $sessions = CashSession::query()->forCompany($company)->forBranch($branch)->whereBetween('opened_at', [$range->fromUtc(), $range->toUtc()])->get()->countBy('opened_by');
        $expenses = Expense::query()->forCompany($company)->where('branch_id', $branch->id)->where('status', ExpenseStatus::Posted->value)->whereNull('reversal_of_id')->whereDate('expense_date', '>=', $range->from->toDateString())->whereDate('expense_date', '<=', $range->to->toDateString())->get()->countBy('created_by');

        return $users->map(fn (User $user) => [
            'name' => $user->name,
            'orders' => $orders->get($user->id, 0),
            'payments' => $payments->get($user->id, 0),
            'cash_sessions' => $sessions->get($user->id, 0),
            'expenses' => $expenses->get($user->id, 0),
        ])->filter(fn (array $row) => array_sum(array_slice($row, 1)) > 0)->values();
    }
}
