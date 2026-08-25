<?php

namespace App\Http\Controllers;

use App\Actions\UpdateRecipeAction;
use App\Http\Requests\RecipeRequest;
use App\Models\Ingredient;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Services\RecipeCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RecipeController extends Controller
{
    public function index(RecipeCatalogService $catalog): View
    {
        Gate::authorize('viewAny', Recipe::class);
        ['prepared' => $preparedVariants, 'direct' => $directVariants] = $catalog->catalog($this->company(), $this->branch());

        return view('recipes.index', compact('preparedVariants', 'directVariants'));
    }

    public function create(Request $request, RecipeCatalogService $catalog): View
    {
        Gate::authorize('create', Recipe::class);
        $variants = $catalog->eligibleVariants($this->company(), $request->string('product')->toString() ?: null);

        return view('recipes.create', compact('variants'));
    }

    public function edit(string $variant): View
    {
        $variant = $this->variant($variant)->load(['product', 'recipe.items.ingredient']);
        abort_if(! $variant->requires_preparation && ! $variant->recipe, 404);
        $ability = $variant->recipe ? 'update' : 'create';
        Gate::authorize($ability, $variant->recipe ?: Recipe::class);
        $ingredients = Ingredient::query()->forCompany($this->company())->where('is_active', true)->with('unit')->orderBy('name')->get();

        return view('recipes.form', compact('variant', 'ingredients'));
    }

    public function update(RecipeRequest $request, string $variant, UpdateRecipeAction $action): RedirectResponse
    {
        $variant = $this->variant($variant)->load('recipe');
        Gate::authorize($variant->recipe ? 'update' : 'create', $variant->recipe ?: Recipe::class);
        $action->execute(
            $this->company(),
            $variant,
            $request->validated('items'),
            $request->validated('name'),
            $request->boolean('is_active'),
        );

        return redirect()->route('recipes.index')->with('success', 'Receta guardada correctamente.');
    }

    private function variant(string $ulid): ProductVariant
    {
        return ProductVariant::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail();
    }
}
