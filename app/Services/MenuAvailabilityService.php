<?php

namespace App\Services;

use App\Data\MenuAvailabilityCatalogData;
use App\Data\MenuAvailabilityProductData;
use App\Data\MenuAvailabilityVariantData;
use App\Enums\InventoryReservationStatus;
use App\Enums\MenuAvailabilityStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

class MenuAvailabilityService
{
    private const LIMITED_MAX = 3;

    public function __construct(private readonly SellableAvailabilityService $availability) {}

    public function catalog(Company $company, Branch $branch): MenuAvailabilityCatalogData
    {
        if ((int) $company->getKey() !== (int) $branch->company_id) {
            throw new DomainException('Menu availability cannot mix companies.');
        }

        $products = Product::query()
            ->forCompany($company)
            ->where('is_active', true)
            ->whereHas('variants', fn (Builder $query): Builder => $query
                ->where('is_active', true)
                ->whereDoesntHave('promotion'))
            ->with([
                'category',
                'variants' => fn ($query) => $query
                    ->where('is_active', true)
                    ->whereDoesntHave('promotion')
                    ->orderBy('sort_order')
                    ->orderBy('name'),
                'variants.product',
                'variants.recipe.items.ingredient.inventoryItem.inventoryStocks' => fn ($query) => $query
                    ->where('branch_id', $branch->getKey()),
                'variants.recipe.items.ingredient.inventoryItem.inventoryBatches' => fn ($query) => $query
                    ->where('branch_id', $branch->getKey())
                    ->where('quantity_remaining', '>', 0),
                'variants.recipe.items.ingredient.inventoryItem.inventoryReservations' => fn ($query) => $query
                    ->where('branch_id', $branch->getKey())
                    ->where('status', InventoryReservationStatus::Reserved->value),
                'variants.inventoryItem.inventoryStocks' => fn ($query) => $query
                    ->where('branch_id', $branch->getKey()),
                'variants.inventoryItem.inventoryBatches' => fn ($query) => $query
                    ->where('branch_id', $branch->getKey())
                    ->where('quantity_remaining', '>', 0),
                'variants.inventoryItem.inventoryReservations' => fn ($query) => $query
                    ->where('branch_id', $branch->getKey())
                    ->where('status', InventoryReservationStatus::Reserved->value),
            ])
            ->orderBy('name')
            ->get();

        $productData = $products->map(function (Product $product) use ($branch): MenuAvailabilityProductData {
            $variants = $product->variants
                ->map(fn (ProductVariant $variant): MenuAvailabilityVariantData => $this->variant($variant, $branch))
                ->values()
                ->all();
            $statuses = collect($variants)
                ->pluck('status')
                ->reject(fn (MenuAvailabilityStatus $item): bool => $item === MenuAvailabilityStatus::Untracked);
            $status = $statuses->isEmpty()
                ? MenuAvailabilityStatus::Untracked
                : ($statuses->every(fn (MenuAvailabilityStatus $item): bool => $item === MenuAvailabilityStatus::Unavailable)
                    ? MenuAvailabilityStatus::Unavailable
                    : ($statuses->contains(fn (MenuAvailabilityStatus $item): bool => $item !== MenuAvailabilityStatus::Available)
                        ? MenuAvailabilityStatus::Limited
                        : MenuAvailabilityStatus::Available));
            $category = $product->category?->is_active ? $product->category : null;

            return new MenuAvailabilityProductData(
                $product->ulid,
                $product->name,
                $product->type,
                $category?->ulid,
                $category?->name,
                $status,
                $variants,
            );
        })->values();

        $categories = $products
            ->pluck('category')
            ->filter(fn ($category): bool => (bool) $category?->is_active)
            ->unique('id')
            ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
            ->map(fn ($category): array => ['key' => $category->ulid, 'name' => $category->name])
            ->values()
            ->all();

        return new MenuAvailabilityCatalogData(
            $productData->all(),
            $categories,
            [
                'available' => $productData->where('status', MenuAvailabilityStatus::Available)->count(),
                'limited' => $productData->where('status', MenuAvailabilityStatus::Limited)->count(),
                'unavailable' => $productData->where('status', MenuAvailabilityStatus::Unavailable)->count(),
                'total' => $productData->count(),
            ],
        );
    }

    private function variant(ProductVariant $variant, Branch $branch): MenuAvailabilityVariantData
    {
        $result = $this->availability->calculate($variant, $branch);

        if ($result->mode === 'recipe_pending' || ($result->mode === 'direct' && ! $variant->inventoryItem)) {
            return new MenuAvailabilityVariantData(
                $variant->name,
                $variant->price,
                null,
                MenuAvailabilityStatus::Untracked,
                [],
            );
        }

        $quantity = (string) BigDecimal::of($result->availableQuantity)->toScale(0, RoundingMode::Down);
        $status = $this->status($quantity);
        $limiting = $status === MenuAvailabilityStatus::Available
            ? []
            : collect($result->limitingIngredients)->pluck('ingredient')->filter()->unique()->values()->all();

        if ($limiting === [] && $status !== MenuAvailabilityStatus::Available) {
            $limiting = [$result->mode === 'direct' ? $variant->product->name : 'Preparación no disponible'];
        }

        return new MenuAvailabilityVariantData(
            $variant->name,
            $variant->price,
            $quantity,
            $status,
            $limiting,
        );
    }

    private function status(string $quantity): MenuAvailabilityStatus
    {
        $available = BigDecimal::of($quantity);

        if ($available->isZero()) {
            return MenuAvailabilityStatus::Unavailable;
        }

        return $available->compareTo(BigDecimal::of(self::LIMITED_MAX)) <= 0
            ? MenuAvailabilityStatus::Limited
            : MenuAvailabilityStatus::Available;
    }
}
