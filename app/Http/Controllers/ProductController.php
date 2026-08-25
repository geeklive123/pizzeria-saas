<?php

namespace App\Http\Controllers;

use App\Actions\SaveProductAction;
use App\Enums\ProductType;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Services\SellableAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(SellableAvailabilityService $availability): View
    {
        Gate::authorize('viewAny', Product::class);
        $products = Product::query()->forCompany($this->company())->with(['category', 'variants.recipe'])->orderBy('name')->paginate(15);
        $products->getCollection()->each(function (Product $product) use ($availability): void {
            $product->variants->each(fn ($variant) => $variant->setAttribute(
                'sellable_availability',
                $availability->calculate($variant, $this->branch()),
            ));
        });

        return view('products.index', compact('products'));
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return $this->form(new Product(['is_active' => true, 'type' => ProductType::Pizza]));
    }

    public function store(ProductRequest $request, SaveProductAction $action): RedirectResponse
    {
        Gate::authorize('create', Product::class);
        $product = $action->execute($this->company(), $request->validated(), $request->validated('variants'));

        if ($product->type === ProductType::Pizza) {
            return redirect()->route('recipes.create', ['product' => $product->ulid])
                ->with('success', 'Pizza creada correctamente. ¿Quieres configurar sus recetas?');
        }

        return redirect()->route('products.edit', $product->ulid)->with('success', 'Producto creado correctamente.');
    }

    public function edit(string $product): View
    {
        $model = $this->find($product);
        Gate::authorize('update', $model);

        return $this->form($model->load('variants'));
    }

    public function update(ProductRequest $request, string $product, SaveProductAction $action): RedirectResponse
    {
        $model = $this->find($product);
        Gate::authorize('update', $model);
        $action->execute($this->company(), $request->validated(), $request->validated('variants'), $model);

        return back()->with('success', 'Producto actualizado correctamente.');
    }

    private function form(Product $product): View
    {
        $categories = Category::query()->forCompany($this->company())->where('is_active', true)->orderBy('sort_order')->get();
        $types = ProductType::cases();

        return view('products.form', compact('product', 'categories', 'types'));
    }

    private function find(string $ulid): Product
    {
        return Product::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail();
    }
}
