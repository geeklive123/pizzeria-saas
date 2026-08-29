<?php

namespace App\Services;

use App\Enums\InventoryReservationStatus;
use App\Enums\ProductModifierPurpose;
use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\Promotion;

class OrderPosCatalogService
{
    public function __construct(
        private readonly SellableAvailabilityService $availability,
        private readonly VariantSizeKeyService $sizeKeys,
        private readonly PromotionAvailabilityService $promotionAvailability,
    ) {}

    /** @return array<string, mixed> */
    public function forOrderScreen(Company $company, Branch $branch): array
    {
        $products = Product::query()->forCompany($company)->where('is_active', true)
            ->whereDoesntHave('variants.promotion')
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

        $promotions = Promotion::query()->forCompany($company)->currentlyActive()
            ->whereHas('productVariant', fn ($query) => $query->where('is_active', true)
                ->whereHas('product', fn ($product) => $product->where('is_active', true)))
            ->whereHas('components')
            ->with([
                'productVariant.product.category',
                'components.inventoryItem.unit',
                'components.inventoryItem.inventoryStocks' => fn ($query) => $query->where('branch_id', $branch->getKey()),
                'components.inventoryItem.inventoryBatches' => fn ($query) => $query->where('branch_id', $branch->getKey()),
                'components.inventoryItem.inventoryReservations' => fn ($query) => $query
                    ->where('branch_id', $branch->getKey())
                    ->where('status', InventoryReservationStatus::Reserved->value),
            ])
            ->get()
            ->each(fn ($promotion) => $promotion->setAttribute(
                'sellable_availability',
                $this->promotionAvailability->calculate($promotion, $branch),
            ));

        return compact('products', 'promotions', 'pizzaVariants', 'pizzaSizeKeys', 'modifierOptions', 'toppingOptions');
    }
}
