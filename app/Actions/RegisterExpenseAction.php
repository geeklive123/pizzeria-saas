<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\ExpenseDocumentType;
use App\Enums\ExpensePaymentMethod;
use App\Enums\ExpenseStatus;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use App\Services\CompanyAccessService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class RegisterExpenseAction
{
    public function __construct(
        private readonly RecordCashMovementAction $movements,
        private readonly CashSessionSummaryService $summary,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(Company $company, Branch $branch, User $user, array $data, ?CashSession $session = null): Expense
    {
        $this->access->ensure($user, $company, Permission::CreateExpenses);
        $amount = BigDecimal::of($data['amount'])->toScale(2, RoundingMode::HalfUp);
        if ($amount->isLessThanOrEqualTo(0)) {
            throw new DomainException('Expense amount must be greater than zero.');
        }

        $documentType = ExpenseDocumentType::from($data['document_type']);
        $paymentMethod = ExpensePaymentMethod::from($data['payment_method']);
        if ($documentType === ExpenseDocumentType::WithInvoice && blank($data['document_number'] ?? null)) {
            throw new DomainException('An invoice number is required.');
        }

        return DB::transaction(function () use ($company, $branch, $user, $data, $session, $amount, $documentType, $paymentMethod): Expense {
            if ((int) $branch->company_id !== (int) $company->id) {
                throw new DomainException('The expense branch must belong to the company.');
            }
            $category = ExpenseCategory::query()->forCompany($company)->whereKey($data['expense_category_id'])->firstOrFail();
            $supplier = filled($data['supplier_id'] ?? null)
                ? Supplier::query()->forCompany($company)->whereKey($data['supplier_id'])->firstOrFail()
                : null;

            if ($paymentMethod === ExpensePaymentMethod::Cash) {
                if (! $session) {
                    throw new DomainException('You must open cash before recording a cash expense.');
                }
                $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
                if ($session->status !== CashSessionStatus::Open
                    || (int) $session->company_id !== (int) $company->id
                    || (int) $session->branch_id !== (int) $branch->id) {
                    throw new DomainException('The open cash session must belong to the expense branch.');
                }
                if ($amount->isGreaterThan(BigDecimal::of($this->summary->calculate($session)['expected_cash']))) {
                    throw new DomainException('The cash expense cannot exceed expected physical cash.');
                }
            } else {
                $session = null;
            }

            $expense = Expense::query()->create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'expense_category_id' => $category->id,
                'supplier_id' => $supplier?->id,
                'description' => $data['description'],
                'amount' => (string) $amount,
                'expense_date' => $data['expense_date'],
                'document_type' => $documentType,
                'document_number' => filled($data['document_number'] ?? null) ? $data['document_number'] : null,
                'payment_method' => $paymentMethod,
                'cash_session_id' => $session?->id,
                'status' => ExpenseStatus::Posted,
                'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
                'created_by' => $user->id,
            ]);

            if ($session) {
                $this->movements->execute($session, CashMovementType::ExpenseOut, (string) $amount, $user, $expense->description, $expense);
            }

            return $expense->refresh();
        });
    }
}
