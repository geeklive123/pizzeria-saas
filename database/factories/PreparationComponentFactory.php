<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Preparation;
use App\Models\PreparationComponent;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PreparationComponent> */
class PreparationComponentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'preparation_id' => Preparation::factory(),
            'company_id' => fn (array $attributes) => Preparation::query()->findOrFail($attributes['preparation_id'])->company_id,
            'inventory_item_id' => function (array $attributes) {
                $unit = Unit::factory()->create(['company_id' => $attributes['company_id']]);

                return InventoryItem::factory()->for($unit)->create()->getKey();
            },
            'quantity' => '100.000',
        ];
    }
}
