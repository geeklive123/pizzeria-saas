<?php

namespace App\Actions;

use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\PurchaseItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class CreateInventoryBatchAction
{
    public function execute(
        InventoryMovement $movement,
        int|string $quantity,
        int|string $unitCost,
        ?PurchaseItem $purchaseItem = null,
        ?\DateTimeInterface $receivedAt = null,
        ?\DateTimeInterface $expiresAt = null,
    ): InventoryBatch {
        return InventoryBatch::query()->create([
            'company_id' => $movement->company_id,
            'branch_id' => $movement->branch_id,
            'inventory_item_id' => $movement->inventory_item_id,
            'purchase_item_id' => $purchaseItem?->getKey(),
            'inventory_movement_id' => $movement->getKey(),
            'quantity_received' => (string) BigDecimal::of($quantity)->toScale(3, RoundingMode::HalfUp),
            'quantity_remaining' => (string) BigDecimal::of($quantity)->toScale(3, RoundingMode::HalfUp),
            'unit_cost' => (string) BigDecimal::of($unitCost)->toScale(6, RoundingMode::HalfUp),
            'received_at' => $receivedAt ?? $movement->occurred_at,
            'expires_at' => $expiresAt,
        ]);
    }
}
