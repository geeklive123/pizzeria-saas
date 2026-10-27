<?php

namespace App\Support;

use App\Enums\ProductType;
use Illuminate\Support\Str;

class StorefrontCatalogPresenter
{
    public static function description(?string $description): ?string
    {
        $description = trim((string) $description);
        if ($description === '') {
            return null;
        }

        $internalMarkers = [
            'la carta no indica',
            'receta pendiente',
            'revisar antes',
            'stock directo',
            'vendibles del pos',
        ];

        return Str::contains(Str::lower($description), $internalMarkers) ? null : $description;
    }

    public static function kind(ProductType $type): string
    {
        return match ($type) {
            ProductType::Pizza => 'Al horno',
            ProductType::Beverage => 'Bebida',
            ProductType::Combo => 'Combo',
            ProductType::Extra => 'Extra',
            ProductType::Other => 'Especial',
        };
    }
}
