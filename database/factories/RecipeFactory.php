<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Recipe> */
class RecipeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'company_id' => fn (array $attributes) => ProductVariant::query()
                ->findOrFail($attributes['product_variant_id'])
                ->company_id,
            'name' => fake()->optional()->words(3, true),
            'is_active' => true,
        ];
    }
}
