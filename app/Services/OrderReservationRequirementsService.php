<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Collection;

class OrderReservationRequirementsService
{
    /** @return Collection<int, array{inventory_item:InventoryItem, quantity:string}> */
    public function forOrderItem(OrderItem $item, int|string $quantity): Collection
    {
        $snapshotRequirements = $item->configuration_snapshot['requirements'] ?? null;
        if (! is_array($snapshotRequirements)) {
            return $this->forVariant($item->productVariant, $quantity);
        }

        $orderQuantity = BigDecimal::of($quantity)->toScale(3, RoundingMode::HalfUp);
        $ids = collect($snapshotRequirements)->pluck('inventory_item_id')->map(fn ($id): int => (int) $id)->all();
        $inventoryItems = InventoryItem::query()->where('company_id', $item->company_id)
            ->whereIn('id', $ids)->where('is_active', true)->get()->keyBy('id');

        if ($inventoryItems->count() !== count(array_unique($ids))) {
            throw new DomainException('La configuración del pedido referencia inventario inactivo o de otra empresa.');
        }

        return collect($snapshotRequirements)->map(function (array $requirement) use ($inventoryItems, $orderQuantity): array {
            return [
                'inventory_item' => $inventoryItems->get((int) $requirement['inventory_item_id']),
                'quantity' => (string) BigDecimal::of($requirement['quantity'])
                    ->multipliedBy($orderQuantity)->toScale(3, RoundingMode::HalfUp),
            ];
        })->sortBy(fn (array $requirement) => $requirement['inventory_item']->getKey())->values();
    }

    /** @return Collection<int, array{inventory_item:InventoryItem, quantity:string}> */
    public function forVariant(ProductVariant $variant, int|string $quantity): Collection
    {
        $orderQuantity = BigDecimal::of($quantity)->toScale(3, RoundingMode::HalfUp);
        $variant->loadMissing(['recipe.items.ingredient.inventoryItem', 'inventoryItem']);

        if ($variant->recipe?->is_active && $variant->recipe->items->isNotEmpty()) {
            return $variant->recipe->items->map(function ($recipeItem) use ($orderQuantity): array {
                $inventoryItem = $recipeItem->ingredient->inventoryItem;

                if (! $inventoryItem || ! $inventoryItem->is_active) {
                    throw new DomainException("El ingrediente {$recipeItem->ingredient->name} no tiene un artículo de inventario activo.");
                }

                return [
                    'inventory_item' => $inventoryItem,
                    'quantity' => (string) BigDecimal::of($recipeItem->quantity)
                        ->multipliedBy($orderQuantity)->toScale(3, RoundingMode::HalfUp),
                ];
            })->sortBy(fn (array $requirement) => $requirement['inventory_item']->getKey())->values();
        }

        $inventoryItem = $variant->inventoryItem;

        if (! $inventoryItem || ! $inventoryItem->is_active) {
            throw new DomainException('La variante no tiene una receta activa ni un artículo de venta directa disponible.');
        }

        return collect([[
            'inventory_item' => $inventoryItem,
            'quantity' => (string) $orderQuantity,
        ]]);
    }
}
