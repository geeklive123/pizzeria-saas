<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => null,
            'company_id' => fn (array $attributes) => $attributes['category_id']
                ? Category::query()->findOrFail($attributes['category_id'])->company_id
                : Company::factory()->create()->getKey(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'type' => fake()->randomElement(ProductType::cases()),
            'is_active' => true,
        ];
    }
}
