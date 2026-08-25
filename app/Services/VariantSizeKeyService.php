<?php

namespace App\Services;

use App\Models\ProductVariant;
use Illuminate\Support\Str;

class VariantSizeKeyService
{
    public function fromVisibleName(string $name): string
    {
        return Str::of($name)->ascii()->lower()->slug('-')->toString();
    }

    public function fromVariant(ProductVariant $variant): string
    {
        return $this->fromVisibleName($variant->name);
    }
}
