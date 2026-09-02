<?php

namespace App\Data;

class PreparationAvailabilityResult
{
    /**
     * @param  list<array{inventory_item_id:int,name:string,unit:string,required_per_lot:string,available:string,possible_lots:int}>  $components
     * @param  list<array{inventory_item_id:int,name:string,unit:string,required_per_lot:string,available:string,possible_lots:int}>  $limitingComponents
     */
    public function __construct(
        public readonly int $maximumLots,
        public readonly string $estimatedYield,
        public readonly array $components,
        public readonly array $limitingComponents,
    ) {}
}
