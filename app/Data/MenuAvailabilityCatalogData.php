<?php

namespace App\Data;

final readonly class MenuAvailabilityCatalogData
{
    /**
     * @param  list<MenuAvailabilityProductData>  $products
     * @param  list<array{key:string, name:string}>  $categories
     * @param  array{available:int, limited:int, unavailable:int, total:int}  $summary
     */
    public function __construct(
        public array $products,
        public array $categories,
        public array $summary,
    ) {}
}
