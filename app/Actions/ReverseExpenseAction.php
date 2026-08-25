<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\ExpensePaymentMethod;
use App\Enums\ExpenseStatus;
use App\Enums\Permission;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReverseExpenseAction
{
    public function __construct(private readonly RecordCashMovementAction $movements, private readonly CompanyAccessService $access) {}

    public function execute(Expense $expense, string $reason, User $user, ?CashSession $session = null): Expense
    {
        $this->access->ensure($user, $expense->company, Permission::ReverseExpenses);
        if (blank($reason)) {
            throw new DomainException('An expense reversal reason is required.');
        }

        return DB::transaction(function () use ($expense, $reason, $user, $session): Expense {
            $expense = Expense::query()->with('company')->lockForUpdate()->findOrFail($expense->id);
            if ($expense->status !== ExpenseStatus::Posted || $expense->reversal_of_id !== null || $expense->reversals()->exists()) {
                throw new DomainException('This expense cannot be reversed again.');
            }

            if ($expense->payment_method === ExpensePaymentMethod::Cash) {
                if (! $session) {
                    throw new DomainException('You must open cash before reversing a cash expense.');
                }
                $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
                if ($session->status !== CashSessionStatus::Open
                    || (int) $session->company_id !== (int) $expense->company_id
                    || (int) $session->branch_id !== (int) $expense->branch_id) {
                    throw new DomainException('The open cash session must belong to the expense branch.');
                }
            } else {
                $session = null;
            }

            $reversal = Expense::query()->create([
                'company_id' => $expense->company_id,
                'branch_id' => $expense->branch_id,
                'expense_category_id' => $expense->expense_category_id,
                'supplier_id' => $expense->supplier_id,
                'description' => 'Reversal: '.$expense->description,
                'amount' => $expense->amount,
                'expense_date' => today(),
                'document_type' => $expense->document_type,
                'document_number' => $expense->document_number,
                'payment_method' => $expense->payment_method,
                'cash_session_id' => $session?->id,
                'status' => ExpenseStatus::Posted,
                'notes' => $reason,
                'created_by' => $user->id,
                'reversal_of_id' => $expense->id,
            ]);

            $expense->forceFill(['status' => ExpenseStatus::Reversed])->save();

            if ($session) {
                $originalMovement = CashMovement::query()
                    ->where('reference_type', Expense::class)
                    ->where('reference_id', $expense->id)
                    ->where('type', CashMovementType::ExpenseOut->value)
                    ->firstOrFail();
                $this->movements->execute($session, CashMovementType::ExpenseReversal, $expense->amount, $user, $reason, $reversal, $originalMovement);
            }

            return $reversal->refresh();
        });
    }
}
