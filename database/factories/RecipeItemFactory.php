<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecipeItem> */
class RecipeItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'ingredient_id' => fn (array $attributes) => Ingredient::factory()->create([
                'company_id' => Recipe::query()->findOrFail($attributes['recipe_id'])->company_id,
            ])->getKey(),
            'company_id' => fn (array $attributes) => Recipe::query()
                ->findOrFail($attributes['recipe_id'])
                ->company_id,
            'quantity' => fake()->randomElement(['0.250', '1.000', '1.500', '450.000']),
        ];
    }
}
