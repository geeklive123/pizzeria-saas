<?php

namespace Tests\Unit;

use App\Services\WeightedAverageCostCalculator;
use PHPUnit\Framework\TestCase;

class WeightedAverageCostCalculatorTest extends TestCase
{
    public function test_it_calculates_weighted_average_with_decimal_arithmetic(): void
    {
        $calculator = new WeightedAverageCostCalculator;

        $result = $calculator->calculate('1000.000', '0.040000', '1000.000', '0.050000');

        $this->assertSame('0.045000', $result);
    }
}
