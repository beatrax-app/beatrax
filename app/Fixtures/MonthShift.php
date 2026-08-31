<?php

declare(strict_types=1);

namespace App\Fixtures;

use Carbon\CarbonImmutable;

/**
 * @link ../../.docs/local_development/rebasing-a-statement-fixture.md#why-whole-months
 */
final readonly class MonthShift
{
    private function __construct(public int $months) {}

    public static function of(int $months): self
    {
        return new self($months);
    }

    // The anchor's MONTH, not the anchor date: landing on or before the date
    // instead drops the older of the two in-window months for any series
    // falling early in the month, and the detector's window is two months wide.
    public static function intoMonthOf(CarbonImmutable $newest, CarbonImmutable $anchor): self
    {
        return new self(($anchor->year - $newest->year) * 12 + ($anchor->month - $newest->month));
    }

    public function apply(CarbonImmutable $date): CarbonImmutable
    {
        return $this->months >= 0
            ? $date->addMonthsNoOverflow($this->months)
            : $date->subMonthsNoOverflow(-$this->months);
    }
}
