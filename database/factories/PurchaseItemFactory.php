<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseItem> */
class PurchaseItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_id' => Purchase::factory(),
            'company_id' => fn (array $attributes) => Purchase::query()
                ->findOrFail($attributes['purchase_id'])
                ->company_id,
            'inventory_item_id' => function (array $attributes) {
                $unit = Unit::factory()->state(['company_id' => $attributes['company_id']])->create();

                return InventoryItem::factory()->for($unit)->create()->getKey();
            },
            'input_unit_id' => fn (array $attributes) => InventoryItem::query()
                ->findOrFail($attributes['inventory_item_id'])
                ->unit_id,
            'quantity' => '10.000',
            'base_quantity' => '10.000',
            'unit_cost' => '2.000000',
            'total_cost' => '20.000000',
        ];
    }
}
