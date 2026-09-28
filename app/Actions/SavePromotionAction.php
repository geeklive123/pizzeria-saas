<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\PromotionSelectionGroup;
use App\Models\User;
use App\Services\CompanyAccessService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class SavePromotionAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    public function execute(Company $company, array $data, User $user, ?Promotion $promotion = null): Promotion
    {
        $this->access->ensure($user, $company, Permission::ManageCatalog);

        return DB::transaction(function () use ($company, $data, $promotion): Promotion {
            if ($promotion?->exists && (int) $promotion->company_id !== (int) $company->getKey()) {
                throw new DomainException('La promoción no pertenece a la empresa activa.');
            }

            $components = $data['components'] ?? [];
            $selectionGroups = $data['selection_groups'] ?? [];
            if ($components === [] && $selectionGroups === []) {
                throw new DomainException('La promoción debe tener al menos un componente fijo o un grupo de pizzas.');
            }

            $componentUlids = collect($components)->pluck('inventory_item_ulid')->all();
            $inventoryItems = InventoryItem::query()->forCompany($company)->where('is_active', true)
                ->whereIn('ulid', $componentUlids)->orderBy('id')->lockForUpdate()->get()->keyBy('ulid');
            if ($inventoryItems->count() !== count(array_unique($componentUlids))) {
                throw new DomainException('Todos los componentes deben ser artículos de inventario activos de la empresa.');
            }

            $categoryUlids = collect($selectionGroups)->pluck('category_ulid')->all();
            $categories = Category::query()->forCompany($company)->where('is_active', true)
                ->whereIn('ulid', $categoryUlids)->orderBy('id')->lockForUpdate()->get()->keyBy('ulid');
            if ($categories->count() !== count(array_unique($categoryUlids))) {
                throw new DomainException('Todas las categorías de pizza deben estar activas y pertenecer a la empresa.');
            }
            foreach ($selectionGroups as $groupData) {
                $groupCategory = $categories->get($groupData['category_ulid']);
                if (! in_array($groupData['size_key'] ?? null, ['personal', 'mediana', 'familiar'], true)
                    || (int) ($groupData['quantity'] ?? 0) < 1
                    || (int) ($groupData['quantity'] ?? 0) > 12
                    || ! $groupCategory->products()->where('type', ProductType::Pizza->value)->where('is_active', true)->exists()) {
                    throw new DomainException('Cada grupo debe usar una categoría de pizzas activa, un tamaño compatible y una cantidad válida.');
                }
            }

            $category = Category::query()->forCompany($company)->where('name', 'Promociones')->lockForUpdate()->first();
            if (! $category) {
                $category = Category::query()->create([
                    'company_id' => $company->getKey(),
                    'name' => 'Promociones',
                    'description' => 'Promociones vendibles del POS.',
                    'is_active' => true,
                    'sort_order' => 0,
                ]);
            } elseif (! $category->is_active) {
                $category->update(['is_active' => true]);
            }

            $promotion?->loadMissing('productVariant.product');
            $product = $promotion?->productVariant?->product;
            $duplicate = Product::query()->forCompany($company)->where('name', $data['name'])
                ->when($product, fn ($query) => $query->whereKeyNot($product->getKey()))->exists();
            if ($duplicate) {
                throw new DomainException('Ya existe un producto o promoción con ese nombre.');
            }
            $product ??= new Product(['company_id' => $company->getKey()]);
            $product->fill([
                'category_id' => $category->getKey(),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => ProductType::Combo,
                'is_active' => $data['is_active'],
            ])->save();

            $variant = $promotion?->productVariant ?? new ProductVariant([
                'company_id' => $company->getKey(),
                'product_id' => $product->getKey(),
            ]);
            $variant->fill([
                'name' => 'Promoción',
                'size_key' => null,
                'price' => (string) BigDecimal::of($data['price'])->toScale(2, RoundingMode::HalfUp),
                'requires_preparation' => false,
                'is_active' => $data['is_active'],
                'sort_order' => 0,
            ])->save();

            $promotion ??= new Promotion;
            $promotion->fill([
                'company_id' => $company->getKey(),
                'product_variant_id' => $variant->getKey(),
                'is_active' => $data['is_active'],
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
            ])->save();

            $kept = [];
            foreach ($components as $componentData) {
                $inventoryItem = $inventoryItems->get($componentData['inventory_item_ulid']);
                $component = $promotion->components()->updateOrCreate(
                    ['inventory_item_id' => $inventoryItem->getKey()],
                    [
                        'company_id' => $company->getKey(),
                        'quantity' => (string) BigDecimal::of($componentData['quantity'])->toScale(3, RoundingMode::HalfUp),
                    ],
                );
                $kept[] = $component->getKey();
            }
            $promotion->components()->when($kept, fn ($query) => $query->whereNotIn('id', $kept))->delete();

            $keptGroups = [];
            foreach (array_values($selectionGroups) as $index => $groupData) {
                $category = $categories->get($groupData['category_ulid']);
                $group = filled($groupData['ulid'] ?? null)
                    ? PromotionSelectionGroup::query()->where('promotion_id', $promotion->getKey())
                        ->where('company_id', $company->getKey())->where('ulid', $groupData['ulid'])->firstOrFail()
                    : new PromotionSelectionGroup;
                $group->fill([
                    'company_id' => $company->getKey(),
                    'promotion_id' => $promotion->getKey(),
                    'category_id' => $category->getKey(),
                    'size_key' => $groupData['size_key'],
                    'quantity' => (int) $groupData['quantity'],
                    'allow_repeated_flavors' => filter_var($groupData['allow_repeated_flavors'] ?? false, FILTER_VALIDATE_BOOL),
                    'allow_fusions' => filter_var($groupData['allow_fusions'] ?? false, FILTER_VALIDATE_BOOL),
                    'allow_extras' => filter_var($groupData['allow_extras'] ?? false, FILTER_VALIDATE_BOOL),
                    'allow_removals' => filter_var($groupData['allow_removals'] ?? false, FILTER_VALIDATE_BOOL),
                    'sort_order' => $index,
                ])->save();
                $keptGroups[] = $group->getKey();
            }
            $promotion->selectionGroups()->when($keptGroups, fn ($query) => $query->whereNotIn('id', $keptGroups))->delete();

            return $promotion->refresh()->load(['productVariant.product.category', 'components.inventoryItem.unit', 'selectionGroups.category']);
        });
    }
}
