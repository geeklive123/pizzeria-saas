<?php

namespace Database\Factories;

use App\Enums\OrderItemStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'company_id' => fn (array $attributes) => Order::query()->findOrFail($attributes['order_id'])->company_id,
            'branch_id' => fn (array $attributes) => Order::query()->findOrFail($attributes['order_id'])->branch_id,
            'product_variant_id' => function (array $attributes): int {
                $product = Product::factory()->create(['company_id' => $attributes['company_id']]);

                return ProductVariant::factory()->for($product)->create([
                    'company_id' => $attributes['company_id'],
                ])->id;
            },
            'quantity' => '1.000',
            'unit_price' => '10.00',
            'line_total' => '10.00',
            'fulfillment_type' => OrderType::Takeaway,
            'requires_preparation' => true,
            'status' => OrderItemStatus::Draft,
            'created_by' => User::factory(),
        ];
    }
}
