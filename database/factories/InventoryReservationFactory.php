<?php

namespace Database\Factories;

use App\Enums\InventoryReservationStatus;
use App\Models\InventoryItem;
use App\Models\InventoryReservation;
use App\Models\OrderItem;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryReservationFactory extends Factory
{
    protected $model = InventoryReservation::class;

    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'order_id' => fn (array $attributes) => OrderItem::query()->findOrFail($attributes['order_item_id'])->order_id,
            'company_id' => fn (array $attributes) => OrderItem::query()->findOrFail($attributes['order_item_id'])->company_id,
            'branch_id' => fn (array $attributes) => OrderItem::query()->findOrFail($attributes['order_item_id'])->branch_id,
            'inventory_item_id' => function (array $attributes): int {
                $unit = Unit::factory()->create(['company_id' => $attributes['company_id']]);

                return InventoryItem::factory()->for($unit)->create([
                    'company_id' => $attributes['company_id'],
                ])->id;
            },
            'quantity' => '1.000',
            'status' => InventoryReservationStatus::Reserved,
            'reserved_at' => now(),
        ];
    }
}
