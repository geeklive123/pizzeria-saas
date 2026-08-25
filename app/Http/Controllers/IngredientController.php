<?php

namespace App\Http\Controllers;

use App\Actions\SaveIngredientAction;
use App\Http\Requests\IngredientRequest;
use App\Models\Ingredient;
use App\Models\Unit;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class IngredientController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Ingredient::class);
        $branch = $this->branch();
        $ingredients = Ingredient::query()->forCompany($this->company())
            ->with(['unit', 'inventoryItem.inventoryStocks' => fn ($query) => $query->where('branch_id', $branch->getKey())])
            ->orderBy('name')->paginate(15);

        return view('ingredients.index', compact('ingredients'));
    }

    public function create(): View
    {
        Gate::authorize('create', Ingredient::class);

        return $this->form(new Ingredient(['is_active' => true]));
    }

    public function store(IngredientRequest $request, SaveIngredientAction $action): RedirectResponse
    {
        Gate::authorize('create', Ingredient::class);
        try {
            $ingredient = $action->execute($this->company(), $request->validated());
        } catch (DomainException $exception) {
            return back()->withErrors(['ingredient' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('ingredients.edit', $ingredient->ulid)->with('success', 'Ingrediente creado correctamente.');
    }

    public function edit(string $ingredient): View
    {
        $model = $this->find($ingredient);
        Gate::authorize('update', $model);

        return $this->form($model);
    }

    public function update(IngredientRequest $request, string $ingredient, SaveIngredientAction $action): RedirectResponse
    {
        $model = $this->find($ingredient);
        Gate::authorize('update', $model);
        try {
            $action->execute($this->company(), $request->validated(), $model);
        } catch (DomainException $exception) {
            return back()->withErrors(['ingredient' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', 'Ingrediente actualizado correctamente.');
    }

    private function form(Ingredient $ingredient): View
    {
        $units = Unit::query()->forCompany($this->company())->where('is_active', true)->orderBy('name')->get();

        return view('ingredients.form', compact('ingredient', 'units'));
    }

    private function find(string $ulid): Ingredient
    {
        return Ingredient::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail();
    }
}
