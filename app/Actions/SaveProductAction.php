<?php

namespace App\Actions;

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use App\Services\VariantSizeKeyService;
use Illuminate\Support\Facades\DB;

class SaveProductAction
{
    public function __construct(private readonly VariantSizeKeyService $sizeKeys) {}

    /** @param list<array{name:string, sku:?string, price:string, is_active:bool, sort_order:int}> $variants */
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
                $variantData['sku'] = filled($variantData['sku'] ?? null) ? $variantData['sku'] : null;
                $variantData['size_key'] = $product->type === ProductType::Pizza
                    ? $this->sizeKeys->fromVisibleName($variantData['name'])
                    : null;
                $variant = isset($variantData['ulid'])
                    ? $product->variants()->where('ulid', $variantData['ulid'])->firstOrFail()
                    : $product->variants()->make(['company_id' => $company->getKey()]);
                $variant->fill($variantData)->save();
                $kept[] = $variant->getKey();
            }

            $product->variants()->whereNotIn('id', $kept)->update(['is_active' => false]);

            return $product->refresh()->load(['category', 'variants']);
        });
    }
}
