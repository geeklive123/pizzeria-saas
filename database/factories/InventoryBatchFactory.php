<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryBatch> */
class InventoryBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inventory_item_id' => InventoryItem::factory(),
            'company_id' => fn (array $attributes) => InventoryItem::query()->findOrFail($attributes['inventory_item_id'])->company_id,
            'branch_id' => fn (array $attributes) => Branch::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'quantity_received' => '10.000',
            'quantity_remaining' => '10.000',
            'unit_cost' => '1.000000',
            'received_at' => now(),
            'expires_at' => null,
        ];
    }
}
