<?php

namespace App\Data;

use App\Enums\MenuAvailabilityStatus;

final readonly class MenuAvailabilityVariantData
{
    /** @param list<string> $limitingComponents */
    public function __construct(
        public string $name,
        public string $price,
        public ?string $availableQuantity,
        public MenuAvailabilityStatus $status,
        public array $limitingComponents,
    ) {}
}
