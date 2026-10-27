<?php

namespace App\Services;

use App\Data\MenuAvailabilityVariantData;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StorefrontCatalogService
{
    public function __construct(
        private readonly MenuAvailabilityService $availability,
        private readonly StorefrontVisibilityService $visibility,
    ) {}

    /**
     * @return array{
     *     categories:Collection<int, Category>,
     *     variantAvailability:Collection<string, MenuAvailabilityVariantData>
     * }
     */
    public function catalog(Company $company, Branch $branch): array
    {
        $availability = $this->availability->catalog($company, $branch);
        $variantAvailability = collect($availability->products)
            ->flatMap(fn ($product): array => $product->variants)
            ->keyBy('ulid');

        $categories = Category::query()
            ->forCompany($company)
            ->where('is_active', true);
        $this->visibility->applyCategoryVisibility($categories);

        $categories
            ->whereHas('products', function (Builder $products) use ($company): void {
                $products->where('company_id', $company->getKey())
                    ->where('is_active', true)
                    ->whereHas('variants', fn (Builder $variants): Builder => $variants
                        ->where('is_active', true)
                        ->whereDoesntHave('promotion'));
                $this->visibility->applyProductVisibility($products);
            })
            ->with(['products' => function ($products) use ($company): void {
                $products->where('company_id', $company->getKey())
                    ->where('is_active', true)
                    ->whereHas('variants', fn (Builder $variants): Builder => $variants
                        ->where('is_active', true)
                        ->whereDoesntHave('promotion'));
                $this->visibility->applyProductVisibility($products);
                $products->with(['variants' => fn ($variants) => $variants
                    ->where('is_active', true)
                    ->whereDoesntHave('promotion')
                    ->orderBy('sort_order')
                    ->orderBy('name')])
                    ->orderBy('name');
            }])
            ->orderBy('sort_order')
            ->orderBy('name');

        return [
            'categories' => $categories->get(),
            'variantAvailability' => $variantAvailability,
        ];
    }
}
