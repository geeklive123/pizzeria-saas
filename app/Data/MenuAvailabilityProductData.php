<?php

namespace App\Data;

use App\Enums\MenuAvailabilityStatus;
use App\Enums\ProductType;

final readonly class MenuAvailabilityProductData
{
    /** @param list<MenuAvailabilityVariantData> $variants */
    public function __construct(
        public string $ulid,
        public string $name,
        public ProductType $type,
        public ?string $categoryKey,
        public ?string $categoryName,
        public MenuAvailabilityStatus $status,
        public array $variants,
    ) {}
}
