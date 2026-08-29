<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Promotion> */
class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'product_variant_id' => function (array $attributes): int {
                $product = Product::factory()->create(['company_id' => $attributes['company_id'], 'type' => ProductType::Combo]);

                return ProductVariant::factory()->for($product)->create([
                    'company_id' => $attributes['company_id'],
                    'requires_preparation' => false,
                ])->getKey();
            },
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }
}
