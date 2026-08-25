<?php

namespace Database\Factories;

use App\Enums\UnitType;
use App\Models\Company;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Unit> */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->word(),
            'symbol' => fake()->unique()->lexify('???'),
            'type' => fake()->randomElement(UnitType::cases()),
            'is_active' => true,
        ];
    }
}
