<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class ReportDecimalService
{
    public function money(int|string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp);
    }

    public function quantity(int|string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(3, RoundingMode::HalfUp);
    }

    public function add(int|string ...$values): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $this->money((string) $total);
    }

    public function subtract(int|string $value, int|string ...$subtrahends): string
    {
        $result = BigDecimal::of($value);
        foreach ($subtrahends as $subtrahend) {
            $result = $result->minus($subtrahend);
        }

        return $this->money((string) $result);
    }

    public function average(int|string $total, int $count): string
    {
        return $count === 0
            ? '0.00'
            : (string) BigDecimal::of($total)->dividedBy($count, 2, RoundingMode::HalfUp);
    }

    public function percentage(int|string $amount, int|string $total): string
    {
        if (BigDecimal::of($total)->isZero()) {
            return '0.00';
        }

        return (string) BigDecimal::of($amount)->multipliedBy(100)->dividedBy($total, 2, RoundingMode::HalfUp);
    }
}
