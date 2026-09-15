<?php

namespace App\Services;

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\ProductVariant;
use App\Support\UiFormatter;
use Illuminate\Support\Collection;

class ToppingSizeCatalogService
{
    public function __construct(private readonly VariantSizeKeyService $sizeKeys) {}

    /** @return Collection<int, array{key:string,label:string}> */
    public function forCompany(Company $company): Collection
    {
        return ProductVariant::query()->forCompany($company)
            ->whereHas('product', fn ($query) => $query->where('type', ProductType::Pizza))
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (ProductVariant $variant): array => [
                'key' => $this->sizeKeys->fromVariant($variant),
                'label' => UiFormatter::variantName($variant->name, $this->sizeKeys->fromVariant($variant)),
            ])
            ->unique('key')->values();
    }
}
