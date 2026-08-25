<?php

namespace App\Http\Controllers;

use App\Actions\RegisterExpenseAction;
use App\Actions\ReverseExpenseAction;
use App\Http\Requests\ExpenseRequest;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Expense::class);
        $filters = $request->validate([
            'period' => ['nullable', 'in:today'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'category' => ['nullable', 'integer'],
            'method' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);
        $expenses = Expense::query()->forCompany($this->company())->where('branch_id', $this->branch()->id)
            ->with(['category', 'supplier', 'createdBy', 'reversalOf'])
            ->when(($filters['period'] ?? null) === 'today', fn ($query) => $query->whereDate('expense_date', today()))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '<=', $date))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('expense_category_id', $category))
            ->when($filters['method'] ?? null, fn ($query, $method) => $query->where('payment_method', $method))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('expense_date')->latest('id')->paginate(20)->withQueryString();
        $categories = ExpenseCategory::query()->forCompany($this->company())->orderBy('name')->get();

        return view('expenses.index', compact('expenses', 'categories'));
    }

    public function create(): View
    {
        Gate::authorize('create', Expense::class);
        $categories = ExpenseCategory::query()->forCompany($this->company())->where('is_active', true)->orderBy('name')->get();
        $suppliers = Supplier::query()->forCompany($this->company())->where('is_active', true)->orderBy('name')->get();
        $hasOpenCash = $this->openSession() !== null;

        return view('expenses.create', compact('categories', 'suppliers', 'hasOpenCash'));
    }

    public function store(ExpenseRequest $request, RegisterExpenseAction $action): RedirectResponse
    {
        Gate::authorize('create', Expense::class);
        try {
            $action->execute($this->company(), $this->branch(), $request->user(), $request->validated(), $this->openSession());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['expense' => $exception->getMessage()]);
        }

        return redirect()->route('expenses.index')->with('success', 'Gasto registrado correctamente.');
    }

    public function reverse(Request $request, string $expense, ReverseExpenseAction $action): RedirectResponse
    {
        $expense = $this->expense($expense);
        Gate::authorize('reverse', $expense);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $action->execute($expense, $data['reason'], $request->user(), $this->openSession());
        } catch (DomainException $exception) {
            return back()->withErrors(['expense' => $exception->getMessage()]);
        }

        return back()->with('success', 'Gasto revertido mediante un registro compensatorio.');
    }

    private function expense(string $ulid): Expense
    {
        return Expense::query()->forCompany($this->company())->where('branch_id', $this->branch()->id)->where('ulid', $ulid)->firstOrFail();
    }

    private function openSession(): ?CashSession
    {
        return CashSession::query()->forCompany($this->company())->forBranch($this->branch())->whereNotNull('active_cash_register_id')->first();
    }
}
