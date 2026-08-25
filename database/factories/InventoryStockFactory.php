<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryStock> */
class InventoryStockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::query()
                ->findOrFail($attributes['branch_id'])
                ->company_id,
            'inventory_item_id' => function (array $attributes) {
                $unit = Unit::factory()->state(['company_id' => $attributes['company_id']])->create();

                return InventoryItem::factory()->for($unit)->create()->getKey();
            },
            'quantity' => '1000.000',
            'average_cost' => '0.010000',
        ];
    }
}
