<?php

namespace Database\Factories;

use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryMovement> */
class InventoryMovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::query()
                ->findOrFail($attributes['branch_id'])
                ->company_id,
            'inventory_item_id' => function (array $attributes) {
                $unit = Unit::factory()->state(['company_id' => $attributes['company_id']])->create();

                return InventoryItem::factory()->for($unit)->create()->getKey();
            },
            'type' => InventoryMovementType::ManualIn,
            'quantity' => '10.000',
            'unit_cost' => '0.010000',
            'total_cost' => '0.100000',
            'reference_type' => null,
            'reference_id' => null,
            'reason' => fake()->sentence(),
            'metadata' => null,
            'occurred_at' => now(),
            'created_by' => User::factory(),
            'reversal_of_id' => null,
        ];
    }
}
