<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Promotion;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StorefrontPromotionCatalogService
{
    public function __construct(
        private readonly PromotionAvailabilityService $availability,
        private readonly StorefrontVisibilityService $visibility,
    ) {}

    /** @return Collection<int, Promotion> */
    public function activeFor(Company $company, Branch $branch, ?int $limit = null): Collection
    {
        $query = Promotion::query()
            ->forCompany($company)
            ->currentlyActive()
            ->whereHas('productVariant', function (Builder $variant) use ($company): void {
                $variant->where('is_active', true)
                    ->whereHas('product', function (Builder $product) use ($company): void {
                        $product->where('company_id', $company->getKey())
                            ->where('is_active', true)
                            ->whereHas('category', function (Builder $category) use ($company): void {
                                $category->where('company_id', $company->getKey())
                                    ->where('is_active', true);
                                $this->visibility->applyCategoryVisibility($category);
                            });
                        $this->visibility->applyProductVisibility($product);
                    });
            })
            ->with(['productVariant.product.category'])
            ->orderByRaw('CASE WHEN ends_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('ends_at')
            ->latest('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->each(function (Promotion $promotion) use ($branch): void {
            $availability = $this->availability->calculate($promotion, $branch, OrderType::Takeaway);
            $promotion->setAttribute(
                'storefront_availability',
                $availability,
            );
            $promotion->setAttribute(
                'storefront_orderable',
                BigDecimal::of($availability->availableQuantity)->isPositive(),
            );
        });
    }
}
