<?php

namespace App\Domain\Finance;

use InvalidArgumentException;

final class CommissionCalculator
{
    /**
     * Split a gross amount expressed in minor currency units.
     *
     * Commission is rounded half-up to the nearest minor unit.
     *
     * @return array{gross_minor:int, commission_minor:int, creator_net_minor:int}
     */
    public function split(int $grossMinor, int $basisPoints = 1000): array
    {
        if ($grossMinor < 0) {
            throw new InvalidArgumentException('Gross amount cannot be negative.');
        }

        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw new InvalidArgumentException('Commission basis points must be between 0 and 10000.');
        }

        $whole = intdiv($grossMinor, 10000) * $basisPoints;
        $remainder = intdiv(($grossMinor % 10000) * $basisPoints + 5000, 10000);
        $commission = $whole + $remainder;

        return [
            'gross_minor' => $grossMinor,
            'commission_minor' => $commission,
            'creator_net_minor' => $grossMinor - $commission,
        ];
    }
}
