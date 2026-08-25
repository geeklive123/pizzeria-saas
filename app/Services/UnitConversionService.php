<?php

namespace App\Services;

use App\Enums\UnitType;
use App\Models\Unit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class UnitConversionService
{
    public function convert(int|string $quantity, Unit $from, Unit $to): string
    {
        if ((int) $from->company_id !== (int) $to->company_id) {
            throw new DomainException('Units from different companies cannot be converted.');
        }

        if ($from->type !== $to->type) {
            throw new DomainException('The selected units are not compatible.');
        }

        $value = BigDecimal::of($quantity);

        if ($value->isLessThanOrEqualTo(0)) {
            throw new DomainException('The quantity must be greater than zero.');
        }

        $fromFactor = $this->factor($from);
        $toFactor = $this->factor($to);

        return (string) $value
            ->multipliedBy($fromFactor)
            ->dividedBy($toFactor, 3, RoundingMode::HalfUp);
    }

    private function factor(Unit $unit): string
    {
        return match ($unit->type) {
            UnitType::Weight => match ($unit->symbol) {
                'g' => '1',
                'kg' => '1000',
                default => throw new DomainException("Unsupported weight unit: {$unit->symbol}."),
            },
            UnitType::Volume => match ($unit->symbol) {
                'ml' => '1',
                'L' => '1000',
                default => throw new DomainException("Unsupported volume unit: {$unit->symbol}."),
            },
            UnitType::Unit => $unit->symbol === 'u'
                ? '1'
                : throw new DomainException("Unsupported count unit: {$unit->symbol}."),
        };
    }
}
