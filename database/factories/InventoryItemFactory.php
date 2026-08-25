<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryItem> */
class InventoryItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'company_id' => fn (array $attributes) => Unit::query()
                ->findOrFail($attributes['unit_id'])
                ->company_id,
            'ingredient_id' => fn (array $attributes) => Ingredient::factory()
                ->for(Unit::query()->findOrFail($attributes['unit_id']))
                ->create()
                ->getKey(),
            'product_variant_id' => null,
            'name' => fake()->unique()->words(2, true),
            'is_active' => true,
        ];
    }
}
