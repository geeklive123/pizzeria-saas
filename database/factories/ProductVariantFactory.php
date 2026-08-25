<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        $priceCents = fake()->numberBetween(100, 20000);

        return [
            'product_id' => Product::factory(),
            'company_id' => fn (array $attributes) => Product::query()
                ->findOrFail($attributes['product_id'])
                ->company_id,
            'name' => fake()->unique()->word(),
            'size_key' => 'standard',
            'sku' => fake()->optional()->bothify('SKU-####-??'),
            'price' => sprintf('%d.%02d', intdiv($priceCents, 100), $priceCents % 100),
            'requires_preparation' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
