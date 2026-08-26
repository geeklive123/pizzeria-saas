<?php

namespace App\Http\Controllers;

use App\Actions\SaveCategoryAction;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Category::class);
        $categories = Category::query()->forCompany($this->company())
            ->withCount('products')->orderBy('sort_order')->orderBy('name')->paginate(20);

        return view('categories.index', compact('categories'));
    }

    public function create(): View
    {
        Gate::authorize('create', Category::class);
        $nextOrder = (int) Category::query()->forCompany($this->company())->max('sort_order') + 1;

        return view('categories.form', ['category' => new Category(['is_active' => true, 'sort_order' => $nextOrder])]);
    }

    public function store(CategoryRequest $request, SaveCategoryAction $action): RedirectResponse
    {
        Gate::authorize('create', Category::class);
        $action->execute($this->company(), $request->user(), $request->validated());

        return redirect()->route('categories.index')->with('success', 'Categoría creada correctamente.');
    }

    public function edit(string $category): View
    {
        $category = $this->category($category);
        Gate::authorize('update', $category);

        return view('categories.form', compact('category'));
    }

    public function update(CategoryRequest $request, string $category, SaveCategoryAction $action): RedirectResponse
    {
        $category = $this->category($category);
        Gate::authorize('update', $category);
        $action->execute($this->company(), $request->user(), $request->validated(), $category);

        return redirect()->route('categories.index')->with('success', 'Categoría actualizada correctamente.');
    }

    public function toggle(Request $request, string $category, SaveCategoryAction $action): RedirectResponse
    {
        $category = $this->category($category);
        Gate::authorize('update', $category);
        $action->execute($this->company(), $request->user(), [
            'name' => $category->name,
            'description' => $category->description,
            'sort_order' => $category->sort_order,
            'is_active' => ! $category->is_active,
        ], $category);

        return back()->with('success', 'Estado de la categoría actualizado.');
    }

    private function category(string $ulid): Category
    {
        return Category::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail();
    }
}
