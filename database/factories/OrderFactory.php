<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::query()->findOrFail($attributes['branch_id'])->company_id,
            'restaurant_table_id' => null,
            'active_restaurant_table_id' => null,
            'order_number' => fake()->unique()->numberBetween(1, 999999),
            'type' => OrderType::Takeaway,
            'status' => OrderStatus::Open,
            'subtotal' => '0.00',
            'discount_total' => '0.00',
            'total' => '0.00',
            'opened_at' => now(),
            'created_by' => User::factory(),
        ];
    }
}
