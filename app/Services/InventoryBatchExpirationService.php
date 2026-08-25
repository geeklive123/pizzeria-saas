<?php

namespace App\Services;

use App\Enums\InventoryBatchStatus;
use App\Models\InventoryBatch;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class InventoryBatchExpirationService
{
    public function status(InventoryBatch $batch, ?DateTimeInterface $today = null): InventoryBatchStatus
    {
        if (! $batch->expires_at) {
            return InventoryBatchStatus::NoExpiration;
        }

        $today = $today
            ? CarbonImmutable::instance($today)->startOfDay()
            : CarbonImmutable::now(config('inventory.timezone', 'America/La_Paz'))->startOfDay();
        $expiresAt = $batch->expires_at->startOfDay();

        if ($expiresAt->isBefore($today)) {
            return InventoryBatchStatus::Expired;
        }

        return $expiresAt->lessThanOrEqualTo($today->addDays((int) config('inventory.expiring_soon_days', 7)))
            ? InventoryBatchStatus::ExpiringSoon
            : InventoryBatchStatus::Ok;
    }

    public function daysUntilExpiration(InventoryBatch $batch, ?DateTimeInterface $today = null): ?int
    {
        if (! $batch->expires_at) {
            return null;
        }

        $today = $today
            ? CarbonImmutable::instance($today)->startOfDay()
            : CarbonImmutable::now(config('inventory.timezone', 'America/La_Paz'))->startOfDay();

        return $today->diffInDays($batch->expires_at->startOfDay(), false);
    }
}
