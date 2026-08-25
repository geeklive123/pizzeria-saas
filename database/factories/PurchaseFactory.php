<?php

namespace Database\Factories;

use App\Enums\PurchaseStatus;
use App\Models\Branch;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Purchase> */
class PurchaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::query()
                ->findOrFail($attributes['branch_id'])
                ->company_id,
            'supplier_name' => fake()->company(),
            'document_number' => fake()->optional()->bothify('INV-####-??'),
            'purchased_at' => now(),
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
            'status' => PurchaseStatus::Draft,
        ];
    }
}
