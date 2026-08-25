<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

class RecipeCatalogService
{
    public function __construct(
        private readonly RecipeCostService $costService,
        private readonly SellableAvailabilityService $availability,
    ) {}

    /** @return array{prepared: Collection<int, ProductVariant>, direct: Collection<int, ProductVariant>} */
    public function catalog(Company $company, Branch $branch): array
    {
        $variants = ProductVariant::query()
            ->forCompany($company)
            ->with([
                'product.category',
                'recipe.items.ingredient.unit',
                'recipe.items.ingredient.inventoryItem.inventoryStocks',
            ])
            ->whereHas('product')
            ->orderBy('product_id')
            ->orderBy('sort_order')
            ->get();

        foreach ($variants as $variant) {
            if ($variant->recipe) {
                $variant->recipe->setAttribute('estimated_cost', $this->costService->estimate($variant->recipe, $branch));
            }

            $variant->setAttribute('sellable_availability', $this->availability->calculate($variant, $branch));
        }

        return [
            'prepared' => $variants->where('requires_preparation', true)->values(),
            'direct' => $variants->where('requires_preparation', false)->values(),
        ];
    }

    /** @return Collection<int, ProductVariant> */
    public function eligibleVariants(Company $company, ?string $productUlid = null): Collection
    {
        return ProductVariant::query()
            ->forCompany($company)
            ->where('requires_preparation', true)
            ->where('is_active', true)
            ->whereDoesntHave('recipe')
            ->whereHas('product', function ($query) use ($productUlid): void {
                $query->where('is_active', true)
                    ->when($productUlid, fn ($productQuery) => $productQuery->where('ulid', $productUlid));
            })
            ->with('product.category')
            ->orderBy('product_id')
            ->orderBy('sort_order')
            ->get();
    }
}
