<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * How often a plan is billed.
 */
enum BillingPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * The first period boundary after the given moment, counting whole periods from the start; month ends clamp rather than overflow.
     */
    public function periodEndAfter(CarbonInterface $start, CarbonInterface $moment): CarbonInterface
    {
        $periods = 1;

        while ($this->addPeriods($start, $periods)->lessThanOrEqualTo($moment)) {
            $periods++;
        }

        return $this->addPeriods($start, $periods);
    }

    private function addPeriods(CarbonInterface $start, int $periods): CarbonInterface
    {
        return match ($this) {
            self::Monthly => $start->copy()->addMonthsNoOverflow($periods),
            self::Yearly => $start->copy()->addYearsNoOverflow($periods),
        };
    }
}
