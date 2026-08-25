<?php

namespace App\Services;

use App\Data\ReportDateRange;
use App\Enums\ExpenseStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Expense;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ExpenseReportService
{
    public function __construct(private readonly ReportDecimalService $decimal) {}

    public function data(Company $company, Branch $branch, ReportDateRange $range, array $filters = []): array
    {
        $expenses = $this->query($company, $branch, $range, $filters)
            ->with(['category:id,name', 'supplier:id,name', 'createdBy:id,name'])->get();

        return [
            'total' => $this->sum($expenses),
            'by_category' => $this->group($expenses, fn (Expense $expense) => $expense->category->name),
            'by_method' => $this->group($expenses, fn (Expense $expense) => $expense->payment_method->value),
            'by_document' => $this->group($expenses, fn (Expense $expense) => $expense->document_type->value),
            'by_supplier' => $this->group($expenses, fn (Expense $expense) => $expense->supplier?->name ?? 'Sin proveedor'),
            'by_day' => $this->group($expenses, fn (Expense $expense) => $expense->expense_date->format('Y-m-d')),
            'by_month' => $this->group($expenses, fn (Expense $expense) => $expense->expense_date->format('Y-m')),
        ];
    }

    public function paginate(Company $company, Branch $branch, ReportDateRange $range, array $filters = []): LengthAwarePaginator
    {
        return $this->query($company, $branch, $range, $filters)
            ->with(['category:id,name', 'supplier:id,name', 'createdBy:id,name'])
            ->latest('expense_date')->latest('id')->paginate(config('reports.per_page'))->withQueryString();
    }

    public function query(Company $company, Branch $branch, ReportDateRange $range, array $filters = []): Builder
    {
        return Expense::query()->forCompany($company)->where('branch_id', $branch->id)
            ->where('status', ExpenseStatus::Posted->value)->whereNull('reversal_of_id')
            ->whereDate('expense_date', '>=', $range->from->toDateString())
            ->whereDate('expense_date', '<=', $range->to->toDateString())
            ->when($filters['expense_category_id'] ?? null, fn (Builder $query, int $category) => $query->where('expense_category_id', $category))
            ->when($filters['expense_method'] ?? null, fn (Builder $query, string $method) => $query->where('payment_method', $method))
            ->when($filters['document_type'] ?? null, fn (Builder $query, string $type) => $query->where('document_type', $type));
    }

    private function group(Collection $expenses, callable $key): Collection
    {
        return $expenses->groupBy($key)->map(fn (Collection $group, string $name) => ['name' => $name, 'count' => $group->count(), 'amount' => $this->sum($group)])->sort(fn (array $left, array $right) => BigDecimal::of($right['amount'])->compareTo($left['amount']))->values();
    }

    private function sum(iterable $expenses): string
    {
        $total = BigDecimal::zero();
        foreach ($expenses as $expense) {
            $total = $total->plus($expense->amount);
        }

        return $this->decimal->money((string) $total);
    }
}
