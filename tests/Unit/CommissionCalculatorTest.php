<?php

namespace Tests\Unit;

use App\Domain\Finance\CommissionCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CommissionCalculatorTest extends TestCase
{
    public function test_default_commission_is_ten_percent_and_balances_exactly(): void
    {
        $split = (new CommissionCalculator())->split(10000);

        self::assertSame([
            'gross_minor' => 10000,
            'commission_minor' => 1000,
            'creator_net_minor' => 9000,
        ], $split);
        self::assertSame($split['gross_minor'], $split['commission_minor'] + $split['creator_net_minor']);
    }

    public function test_commission_rounds_half_up_to_minor_unit(): void
    {
        $split = (new CommissionCalculator())->split(5, 1000);

        self::assertSame(1, $split['commission_minor']);
        self::assertSame(4, $split['creator_net_minor']);
    }

    public function test_zero_value_and_full_commission_are_supported(): void
    {
        $calculator = new CommissionCalculator();

        self::assertSame(0, $calculator->split(0)['commission_minor']);
        self::assertSame(100, $calculator->split(100, 10000)['commission_minor']);
    }

    public function test_invalid_amount_and_rate_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CommissionCalculator())->split(-1);
    }

    public function test_rate_above_one_hundred_percent_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CommissionCalculator())->split(100, 10001);
    }
}
