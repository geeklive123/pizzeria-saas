<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\RestaurantTable;
use Illuminate\Database\Eloquent\Factories\Factory;

class RestaurantTableFactory extends Factory
{
    protected $model = RestaurantTable::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::query()->findOrFail($attributes['branch_id'])->company_id,
            'name' => 'Mesa '.fake()->unique()->numberBetween(1, 1000),
            'capacity' => 4,
            'sort_order' => fake()->numberBetween(1, 100),
            'is_active' => true,
        ];
    }
}
