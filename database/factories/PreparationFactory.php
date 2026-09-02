<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Preparation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Preparation> */
class PreparationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'output_inventory_item_id' => InventoryItem::factory(),
            'company_id' => fn (array $attributes) => InventoryItem::query()->findOrFail($attributes['output_inventory_item_id'])->company_id,
            'unit_id' => fn (array $attributes) => InventoryItem::query()->findOrFail($attributes['output_inventory_item_id'])->unit_id,
            'name' => fake()->unique()->words(2, true),
            'theoretical_yield' => '1000.000',
            'is_active' => true,
        ];
    }
}
