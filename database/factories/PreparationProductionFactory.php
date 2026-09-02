<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Preparation;
use App\Models\PreparationProduction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PreparationProduction> */
class PreparationProductionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'preparation_id' => Preparation::factory(),
            'company_id' => fn (array $attributes) => Preparation::query()->findOrFail($attributes['preparation_id'])->company_id,
            'branch_id' => fn (array $attributes) => Branch::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'lots' => 1,
            'theoretical_yield' => '1000.000',
            'actual_yield' => '1000.000',
            'yield_variance' => '0.000',
            'total_cost' => '0.000000',
            'unit_cost' => '0.000000',
            'produced_at' => now(),
            'created_by' => User::factory(),
        ];
    }
}
