<?php

namespace App\Actions;

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Services\VariantSizeKeyService;
use DomainException;
use Illuminate\Support\Facades\DB;

class SaveProductAction
{
    public function __construct(private readonly VariantSizeKeyService $sizeKeys) {}

    /** @param list<array{name:string, sku:?string, price:string, requires_preparation:bool, track_stock?:bool, inventory_unit_id?:int|null, is_active:bool, sort_order:int}> $variants */
    public function execute(Company $company, array $data, array $variants, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($company, $data, $variants, $product): Product {
            $product ??= new Product;
            $product->fill([
                'company_id' => $company->getKey(),
                'category_id' => $data['category_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => ProductType::from($data['type']),
                'is_active' => $data['is_active'],
            ])->save();

            $kept = [];
            foreach ($variants as $variantData) {
                $trackStock = (bool) ($variantData['track_stock'] ?? false);
                $inventoryUnitId = $variantData['inventory_unit_id'] ?? null;
                unset($variantData['track_stock'], $variantData['inventory_unit_id']);

                if ($trackStock && $variantData['requires_preparation']) {
                    throw new DomainException('Solo las variantes de venta directa pueden controlar stock mediante un artículo de inventario.');
                }

                $variantData['sku'] = filled($variantData['sku'] ?? null) ? $variantData['sku'] : null;
                $variantData['size_key'] = $product->type === ProductType::Pizza
                    ? $this->sizeKeys->fromVisibleName($variantData['name'])
                    : null;
                $variant = isset($variantData['ulid'])
                    ? $product->variants()->where('ulid', $variantData['ulid'])->firstOrFail()
                    : $product->variants()->make(['company_id' => $company->getKey()]);
                $variant->fill($variantData)->save();
                $kept[] = $variant->getKey();

                $inventoryItem = $variant->inventoryItem()->first();
                if (! $variant->requires_preparation && ($trackStock || $inventoryItem)) {
                    if (! $inventoryItem && ! $inventoryUnitId) {
                        throw new DomainException('Selecciona la unidad de inventario para controlar el stock de la variante.');
                    }

                    InventoryItem::query()->updateOrCreate(
                        ['product_variant_id' => $variant->getKey()],
                        [
                            'company_id' => $company->getKey(),
                            'unit_id' => $inventoryItem?->unit_id ?? $inventoryUnitId,
                            'ingredient_id' => null,
                            'name' => $product->name.' · '.$variant->name,
                            'is_active' => $product->is_active && $variant->is_active,
                        ],
                    );
                }
            }

            $product->variants()->whereNotIn('id', $kept)->update(['is_active' => false]);

            return $product->refresh()->load(['category', 'variants']);
        });
    }
}
