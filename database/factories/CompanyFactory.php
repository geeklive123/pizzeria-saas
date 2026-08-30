<?php

namespace Database\Factories;

use App\Enums\TableChargeMode;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'legal_name' => fake()->optional()->company(),
            'tax_id' => fake()->optional()->numerify('###########'),
            'phone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->companyEmail(),
            'is_active' => true,
            'table_charge_mode' => TableChargeMode::AtEnd,
        ];
    }
}
