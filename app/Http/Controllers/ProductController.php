<?php

namespace App\Http\Controllers;

use App\Actions\SaveProductAction;
use App\Enums\ProductType;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Services\SellableAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(SellableAvailabilityService $availability): View
    {
        Gate::authorize('viewAny', Product::class);
        $products = Product::query()->forCompany($this->company())->whereDoesntHave('variants.promotion')
            ->with(['category', 'variants.recipe', 'variants.inventoryItem'])->orderBy('name')->paginate(15);
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
        $product = $this->save($request, $action);

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

        return $this->form($model->load('variants.inventoryItem'));
    }

    public function update(ProductRequest $request, string $product, SaveProductAction $action): RedirectResponse
    {
        $model = $this->find($product);
        Gate::authorize('update', $model);
        $this->save($request, $action, $model);

        return back()->with('success', 'Producto actualizado correctamente.');
    }

    private function save(ProductRequest $request, SaveProductAction $action, ?Product $product = null): Product
    {
        $disk = Storage::disk('product_images');
        $oldPath = $product?->image_path;
        $newPath = null;

        try {
            if ($request->hasFile('image')) {
                $newPath = $request->file('image')->store('', 'product_images');
                if (! is_string($newPath)) {
                    throw new \RuntimeException('No se pudo guardar la imagen del producto.');
                }
            }

            $saved = DB::transaction(function () use ($request, $action, $product, $newPath): Product {
                $saved = $action->execute($this->company(), $request->validated(), $request->validated('variants'), $product);
                if ($newPath || $request->boolean('remove_image')) {
                    $saved->image_path = $newPath;
                    $saved->save();
                }

                return $saved;
            });
        } catch (\Throwable $exception) {
            if ($newPath) {
                $disk->delete($newPath);
            }
            throw $exception;
        }

        if ($oldPath && ($newPath || $request->boolean('remove_image'))) {
            $disk->delete($oldPath);
        }

        return $saved;
    }

    private function form(Product $product): View
    {
        $categories = Category::query()->forCompany($this->company())->where('is_active', true)->orderBy('sort_order')->get();
        $types = ProductType::cases();
        $units = Unit::query()->forCompany($this->company())->where('is_active', true)->orderBy('name')->get();
        $variantRows = $product->exists
            ? $product->variants->sortBy('sort_order')->map(fn ($variant): array => [
                ...$variant->toArray(),
                'track_stock' => $variant->inventoryItem !== null,
                'inventory_unit_id' => $variant->inventoryItem?->unit_id,
                'stock_control_locked' => $variant->inventoryItem !== null,
            ])->values()->all()
            : [[
                'name' => '',
                'sku' => '',
                'price' => '0.00',
                'requires_preparation' => true,
                'track_stock' => false,
                'inventory_unit_id' => null,
                'is_active' => true,
                'sort_order' => 0,
            ]];

        return view('products.form', compact('product', 'categories', 'types', 'units', 'variantRows'));
    }

    private function find(string $ulid): Product
    {
        return Product::query()->forCompany($this->company())->whereDoesntHave('variants.promotion')
            ->where('ulid', $ulid)->firstOrFail();
    }
}
