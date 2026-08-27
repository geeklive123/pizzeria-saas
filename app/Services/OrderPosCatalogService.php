<?php

namespace App\Services;

use App\Enums\ProductModifierPurpose;
use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ModifierOption;
use App\Models\Product;

class OrderPosCatalogService
{
    public function __construct(
        private readonly SellableAvailabilityService $availability,
        private readonly VariantSizeKeyService $sizeKeys,
    ) {}

    /** @return array<string, mixed> */
    public function forOrderScreen(Company $company, Branch $branch): array
    {
        $products = Product::query()->forCompany($company)->where('is_active', true)
            ->with(['category', 'variants' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->whereHas('variants', fn ($query) => $query->where('is_active', true))->orderBy('name')->get();

        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $variant->setAttribute('sellable_availability', $this->availability->calculate($variant, $branch));
                $variant->setAttribute('compatibility_size_key', $this->sizeKeys->fromVariant($variant));
            }
        }

        $pizzaVariants = $products->where('type', ProductType::Pizza)
            ->flatMap->variants->groupBy('compatibility_size_key');
        $pizzaSizeKeys = $pizzaVariants->flatMap(
            fn ($variants, string $sizeKey) => $variants->mapWithKeys(fn ($variant) => [$variant->id => $sizeKey]),
        );
        $modifierOptions = ModifierOption::query()->forCompany($company)->where('is_active', true)
            ->whereHas('modifier', fn ($query) => $query->where('is_active', true)
                ->where('purpose', ProductModifierPurpose::OrderModifier))
            ->with('modifier')->orderBy('sort_order')->get();
        $toppingOptions = ModifierOption::query()->forCompany($company)->where('is_active', true)
            ->whereHas('modifier', fn ($query) => $query->where('is_active', true)
                ->where('purpose', ProductModifierPurpose::ToppingCatalog))
            ->with(['modifier', 'sizeRules'])->orderBy('sort_order')->orderBy('name')->get();

        return compact('products', 'pizzaVariants', 'pizzaSizeKeys', 'modifierOptions', 'toppingOptions');
    }
}
