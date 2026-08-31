<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Support;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Contracts\Clock;

/**
 * @link ../../../../.docs/features/anomaly/detector-maths.md#sampling
 */
final readonly class ChargeAnchor
{
    private function __construct(private CarbonImmutable $anchor) {}

    // The row's own posted_at, never the wall clock. The safety-net sweep
    // selects on created_at, so a statement imported today can carry a charge
    // posted two years ago, and a clock-anchored baseline holds no row
    // contemporaneous with the charge it is judging.
    /**
     * @param  array<string, mixed>  $txn  the raw transactions row under test
     */
    public static function forRow(array $txn, Clock $clock): self
    {
        $postedAt = is_string($txn['posted_at'] ?? null) ? $txn['posted_at'] : $clock->now()->toDateString();

        return new self(CarbonImmutable::parse($postedAt));
    }

    // Every edge is a date string because posted_at is a DATE column: SQLite
    // reads '2026-04-17' >= '2026-04-17 00:00:00' as false, which drops the
    // boundary day from the window.
    public function date(): string
    {
        return $this->anchor->toDateString();
    }

    public function baselineWindowStart(): string
    {
        return $this->anchor->subMonthsNoOverflow(RobustStatistics::WINDOW_MONTHS)->toDateString();
    }

    public function daysBefore(int $days): string
    {
        return $this->anchor->subDays($days)->toDateString();
    }
}
