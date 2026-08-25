<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class WeightedAverageCostCalculator
{
    public function calculate(
        int|string $currentQuantity,
        int|string $currentAverageCost,
        int|string $incomingQuantity,
        int|string $incomingUnitCost,
    ): string {
        $currentQuantity = BigDecimal::of($currentQuantity);
        $incomingQuantity = BigDecimal::of($incomingQuantity);

        if ($currentQuantity->isNegative() || $incomingQuantity->isLessThanOrEqualTo(0)) {
            throw new DomainException('Weighted average quantities are invalid.');
        }

        $totalQuantity = $currentQuantity->plus($incomingQuantity);
        $totalValue = $currentQuantity->multipliedBy($currentAverageCost)
            ->plus($incomingQuantity->multipliedBy($incomingUnitCost));

        return (string) $totalValue->dividedBy($totalQuantity, 6, RoundingMode::HalfUp);
    }

    public function afterRemoval(
        int|string $currentQuantity,
        int|string $currentAverageCost,
        int|string $removedQuantity,
        int|string $removedUnitCost,
    ): string {
        $currentQuantity = BigDecimal::of($currentQuantity);
        $removedQuantity = BigDecimal::of($removedQuantity);
        $remainingQuantity = $currentQuantity->minus($removedQuantity);

        if ($remainingQuantity->isNegative()) {
            throw new DomainException('The removal exceeds current stock.');
        }

        if ($remainingQuantity->isZero()) {
            return '0.000000';
        }

        $remainingValue = $currentQuantity->multipliedBy($currentAverageCost)
            ->minus($removedQuantity->multipliedBy($removedUnitCost));

        if ($remainingValue->isNegative()) {
            throw new DomainException('The reversal would produce a negative inventory value.');
        }

        return (string) $remainingValue->dividedBy($remainingQuantity, 6, RoundingMode::HalfUp);
    }

    public function totalCost(int|string $quantity, int|string $unitCost): string
    {
        return (string) BigDecimal::of($quantity)
            ->multipliedBy($unitCost)
            ->toScale(6, RoundingMode::HalfUp);
    }

    public function baseUnitCost(
        int|string $inputQuantity,
        int|string $baseQuantity,
        int|string $inputUnitCost,
    ): string {
        $totalCost = BigDecimal::of($inputQuantity)->multipliedBy($inputUnitCost);
        $baseQuantity = BigDecimal::of($baseQuantity);

        if ($baseQuantity->isLessThanOrEqualTo(0)) {
            throw new DomainException('Base quantity must be greater than zero.');
        }

        return (string) $totalCost->dividedBy($baseQuantity, 6, RoundingMode::HalfUp);
    }
}
