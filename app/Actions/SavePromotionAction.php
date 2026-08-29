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

            $componentUlids = collect($data['components'])->pluck('inventory_item_ulid')->all();
            $inventoryItems = InventoryItem::query()->forCompany($company)->where('is_active', true)
                ->whereIn('ulid', $componentUlids)->orderBy('id')->lockForUpdate()->get()->keyBy('ulid');
            if ($inventoryItems->count() !== count(array_unique($componentUlids))) {
                throw new DomainException('Todos los componentes deben ser artículos de inventario activos de la empresa.');
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
            foreach ($data['components'] as $componentData) {
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

            return $promotion->refresh()->load(['productVariant.product.category', 'components.inventoryItem.unit']);
        });
    }
}
