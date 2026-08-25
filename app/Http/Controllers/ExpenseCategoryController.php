<?php

namespace App\Http\Controllers;

use App\Actions\SaveExpenseCategoryAction;
use App\Http\Requests\ExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ExpenseCategoryController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', ExpenseCategory::class);
        $categories = ExpenseCategory::query()->forCompany($this->company())->withCount('expenses')->orderBy('name')->paginate(20);

        return view('expense-categories.index', compact('categories'));
    }

    public function create(): View
    {
        Gate::authorize('create', ExpenseCategory::class);

        return view('expense-categories.form', ['category' => new ExpenseCategory(['is_active' => true])]);
    }

    public function store(ExpenseCategoryRequest $request, SaveExpenseCategoryAction $action): RedirectResponse
    {
        Gate::authorize('create', ExpenseCategory::class);
        $action->execute($this->company(), $request->user(), $request->validated());

        return redirect()->route('expense-categories.index')->with('success', 'Categoría creada.');
    }

    public function edit(string $expense_category): View
    {
        $category = $this->category($expense_category);
        Gate::authorize('update', $category);

        return view('expense-categories.form', compact('category'));
    }

    public function update(ExpenseCategoryRequest $request, string $expense_category, SaveExpenseCategoryAction $action): RedirectResponse
    {
        $category = $this->category($expense_category);
        Gate::authorize('update', $category);
        $action->execute($this->company(), $request->user(), $request->validated(), $category);

        return redirect()->route('expense-categories.index')->with('success', 'Categoría actualizada.');
    }

    public function toggle(Request $request, string $expense_category, SaveExpenseCategoryAction $action): RedirectResponse
    {
        $category = $this->category($expense_category);
        Gate::authorize('update', $category);
        $action->execute($this->company(), $request->user(), ['is_active' => ! $category->is_active], $category);

        return back()->with('success', 'Estado de la categoría actualizado.');
    }

    private function category(string $ulid): ExpenseCategory
    {
        return ExpenseCategory::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail();
    }
}
